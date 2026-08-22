<?php

declare(strict_types=1);

namespace Laratesto\Migration;

/**
 * Conservative source-to-source converter for common PHPUnit and Laravel tests.
 *
 * The converter deliberately refuses constructs that cannot be translated
 * faithfully without understanding the test's intent. A refused file is never
 * written by the console command.
 */
final class PhpUnitToTestoMigrator
{
    /** @var list<string> */
    private const LARAVEL_ASSERTIONS = [
        'assertAuthenticated',
        'assertAuthenticatedAs',
        'assertDatabaseCount',
        'assertDatabaseHas',
        'assertDatabaseMissing',
        'assertExitCode',
        'assertGuest',
        'assertSessionHas',
        'assertSessionHasErrors',
        'assertSessionMissing',
    ];

    /** @var array<string, string> */
    private const UNSUPPORTED_PATTERNS = [
        'data providers and inline datasets require an explicit Testo data-plugin decision'
            => '/@dataProvider\b|#\[\s*(?:\\\\?PHPUnit\\\\Framework\\\\Attributes\\\\)?(?:DataProvider|DataProviderExternal|TestWith)\b/',
        'test dependencies have no mechanical one-to-one conversion'
            => '/@depends\b|#\[\s*(?:\\\\?PHPUnit\\\\Framework\\\\Attributes\\\\)?Depends(?:External)?\b/',
        'PHPUnit groups and size attributes require Testo filter configuration'
            => '/@group\b|#\[\s*(?:\\\\?PHPUnit\\\\Framework\\\\Attributes\\\\)?(?:Group|Small|Medium|Large)\b/',
        'PHPUnit coverage attributes require a Testo coverage-policy decision'
            => '/#\[\s*(?:\\\\?PHPUnit\\\\Framework\\\\Attributes\\\\)?(?:CoversClass|CoversFunction|UsesClass|UsesFunction|CoversNothing)\b/',
        'PHPUnit test doubles must be replaced with fakes or an explicit mocking bridge'
            => '/->(?:createMock|createStub|getMockBuilder|getMock|prophesize)\s*\(|\bMockObject\b/',
        'PHPUnit constraint objects used by assertThat require a manual assertion rewrite'
            => '/(?:\$this|self|static)(?:::|->)assertThat\s*\(/',
        'regular-expression exception-message expectations require a manual Testo expectation'
            => '/(?:\$this|self|static)(?:::|->)expectExceptionMessageMatches\s*\(/',
        'PHPUnit incomplete tests require an explicit skip or implementation decision'
            => '/(?:\$this|self|static)(?:::|->)markTestIncomplete\s*\(/',
        'class-level PHPUnit lifecycle hooks require a manual Testo lifecycle decision'
            => '/\bfunction\s+(?:setUpBeforeClass|tearDownAfterClass)\s*\(/',
    ];

    public function migrate(string $source): MigrationResult
    {
        $errors = $this->validateSource($source);

        if ($errors !== []) {
            return new MigrationResult($source, errors: $errors);
        }

        $isLaravel = $this->isLaravelTest($source);

        if (!$isLaravel && \preg_match('/\bfunction\s+(?:setUp|tearDown)\s*\(/', $source) === 1) {
            return new MigrationResult(
                $source,
                errors: ['Pure PHPUnit lifecycle hooks require an explicit Testo lifecycle-plugin migration.'],
            );
        }

        $searchableSource = $this->maskNonCodeTokens($source);
        $definedAssertions = [];
        if (\preg_match_all('/\bfunction\s+(assert[A-Za-z0-9_]+)\s*\(/', $searchableSource, $matches) > 0) {
            $definedAssertions = $matches[1];
        }

        $needsAssert = false;
        $needsExpect = false;
        $warnings = [];
        $conversionErrors = [];

        $code = $this->rewriteCalls(
            $source,
            $definedAssertions,
            $needsAssert,
            $needsExpect,
            $warnings,
            $conversionErrors,
        );

        $code = $this->rewriteStructure($code, $source, $isLaravel, $conversionErrors);

        if ($needsAssert) {
            $code = $this->addUse($code, 'Testo\\Assert');
        }
        if ($needsExpect) {
            $code = $this->addUse($code, 'Testo\\Expect');
        }

        $residuals = [
            'PHPUnit\\Framework' => 'PHPUnit framework references remain after conversion.',
            'extends TestCase' => 'A TestCase base class remains after conversion.',
            '->expectException' => 'A PHPUnit exception expectation remains after conversion.',
            '::expectException' => 'A PHPUnit exception expectation remains after conversion.',
        ];

        foreach ($residuals as $needle => $message) {
            if (\str_contains($code, $needle)) {
                $conversionErrors[] = $message;
            }
        }

        try {
            \token_get_all($code, \TOKEN_PARSE);
        } catch (\ParseError $error) {
            $conversionErrors[] = 'Generated PHP is invalid: ' . $error->getMessage();
        }

        return new MigrationResult(
            code: $code,
            warnings: \array_values(\array_unique($warnings)),
            errors: \array_values(\array_unique($conversionErrors)),
        );
    }

    /** @return list<string> */
    private function validateSource(string $source): array
    {
        try {
            \token_get_all($source, \TOKEN_PARSE);
        } catch (\ParseError $error) {
            return ['Source PHP is invalid: ' . $error->getMessage()];
        }

        if (\str_contains($source, 'namespace Tests\\Testo')) {
            return ['The source already uses the Tests\\Testo namespace.'];
        }

        if (\preg_match('/^namespace\s+Tests\\\\(?:Unit|Feature)(?:\\\\[^;]+)?;/m', $source) !== 1) {
            return ['Only tests in Tests\\Unit or Tests\\Feature namespaces are supported.'];
        }

        if (!$this->looksLikePhpUnit($source)) {
            return ['The source does not look like a PHPUnit or Laravel PHPUnit test.'];
        }

        $errors = [];
        foreach (self::UNSUPPORTED_PATTERNS as $message => $pattern) {
            if (\preg_match($pattern, $source) === 1) {
                $errors[] = $message;
            }
        }

        $usesLaravelMigrationTrait = \preg_match(
            '/Illuminate\\\\Foundation\\\\Testing\\\\(?:RefreshDatabase|DatabaseMigrations)/',
            $source,
        ) === 1;

        if ($usesLaravelMigrationTrait
            && \preg_match('/\bfunction\s+(?:beforeRefreshingDatabase|afterRefreshingDatabase)\s*\(/', $source) === 1
        ) {
            $errors[] = 'Custom Laravel database refresh hooks require a manual Testo lifecycle migration.';
        }

        if ($usesLaravelMigrationTrait
            && \preg_match(
                '/\b(?:public|protected|private)\s+(?:(?:static|readonly)\s+)*(?:[?A-Za-z_\\\\|&]+\s+)?\$(?:seed|seeder|dropViews|dropTypes)\b/',
                $source,
            ) === 1
        ) {
            $errors[] = 'Custom Laravel migration properties require explicit Testo attribute arguments.';
        }

        return $errors;
    }

    public function looksLikePhpUnit(string $source): bool
    {
        return \str_contains($source, 'PHPUnit\\Framework')
            || \str_contains($source, 'use Tests\\TestCase;')
            || \preg_match('/\bextends\s+(?:\\\\?Tests\\\\)?TestCase\b/', $source) === 1;
    }

    private function isLaravelTest(string $source): bool
    {
        return \str_contains($source, 'use Tests\\TestCase;')
            || \str_contains($source, 'Illuminate\\Foundation\\Testing\\RefreshDatabase')
            || \str_contains($source, 'Illuminate\\Foundation\\Testing\\DatabaseMigrations')
            || \str_contains($source, 'Illuminate\\Foundation\\Testing\\DatabaseTransactions')
            || \preg_match('/\bextends\s+\\\\?Tests\\\\TestCase\b/', $source) === 1;
    }

    /**
     * @param list<string> $definedAssertions
     * @param list<string> $warnings
     * @param list<string> $errors
     */
    private function rewriteCalls(
        string $source,
        array $definedAssertions,
        bool &$needsAssert,
        bool &$needsExpect,
        array &$warnings,
        array &$errors,
    ): string {
        $output = '';
        $position = 0;
        $length = \strlen($source);
        $searchableSource = $this->maskNonCodeTokens($source);

        while (\preg_match(
            '/(?:static|self|\$this)(?:::|->)(assert[A-Za-z0-9_]+|expectException[A-Za-z0-9_]*|markTestSkipped|fail|addToAssertionCount)\s*\(/',
            $searchableSource,
            $match,
            \PREG_OFFSET_CAPTURE,
            $position,
        ) === 1) {
            $spanStart = (int) $match[0][1];
            $method = $match[1][0];
            $open = $spanStart + \strlen($match[0][0]) - 1;
            $close = $this->findClosingParenthesis($source, $open);

            if ($close === null) {
                $errors[] = "Unable to parse {$method}() arguments.";
                break;
            }

            $arguments = $this->splitArguments(\substr($source, $open + 1, $close - $open - 1));
            $replacement = $this->mapCall(
                $method,
                $arguments,
                $definedAssertions,
                $needsAssert,
                $needsExpect,
                $warnings,
                $errors,
            );

            $output .= \substr($source, $position, $spanStart - $position);
            $output .= $replacement ?? \substr($source, $spanStart, $close + 1 - $spanStart);
            $position = $close + 1;
        }

        return $output . \substr($source, $position, $length - $position);
    }

    /**
     * Preserve byte offsets while hiding tokens that are not executable PHP.
     */
    private function maskNonCodeTokens(string $source): string
    {
        $masked = '';
        $nonCodeTokens = [
            \T_COMMENT,
            \T_DOC_COMMENT,
            \T_CONSTANT_ENCAPSED_STRING,
            \T_ENCAPSED_AND_WHITESPACE,
            \T_INLINE_HTML,
            \T_START_HEREDOC,
            \T_END_HEREDOC,
        ];

        foreach (\token_get_all($source) as $token) {
            if (\is_string($token)) {
                $masked .= $token;
                continue;
            }

            [$id, $text] = $token;
            $masked .= \in_array($id, $nonCodeTokens, true)
                ? \str_repeat(' ', \strlen($text))
                : $text;
        }

        return $masked;
    }

    private function findClosingParenthesis(string $source, int $open): ?int
    {
        $depth = 0;
        $string = null;
        $length = \strlen($source);

        for ($index = $open; $index < $length; $index++) {
            $character = $source[$index];

            if ($string !== null) {
                if ($character === '\\') {
                    $index++;
                    continue;
                }
                if ($character === $string) {
                    $string = null;
                }
                continue;
            }

            if ($character === "'" || $character === '"') {
                $string = $character;
                continue;
            }

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private function splitArguments(string $source): array
    {
        if (\trim($source) === '') {
            return [];
        }

        $arguments = [];
        $current = '';
        $depth = 0;
        $string = null;
        $length = \strlen($source);

        for ($index = 0; $index < $length; $index++) {
            $character = $source[$index];

            if ($string !== null) {
                $current .= $character;
                if ($character === '\\') {
                    $index++;
                    $current .= $source[$index] ?? '';
                    continue;
                }
                if ($character === $string) {
                    $string = null;
                }
                continue;
            }

            if ($character === "'" || $character === '"') {
                $string = $character;
                $current .= $character;
                continue;
            }

            if ($character === '(' || $character === '[' || $character === '{') {
                $depth++;
            } elseif ($character === ')' || $character === ']' || $character === '}') {
                $depth--;
            }

            if ($character === ',' && $depth === 0) {
                $arguments[] = \trim($current);
                $current = '';
                continue;
            }

            $current .= $character;
        }

        if (\trim($current) !== '') {
            $arguments[] = \trim($current);
        }

        return $arguments;
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $definedAssertions
     * @param list<string> $warnings
     * @param list<string> $errors
     */
    private function mapCall(
        string $method,
        array $arguments,
        array $definedAssertions,
        bool &$needsAssert,
        bool &$needsExpect,
        array &$warnings,
        array &$errors,
    ): ?string {
        if (\in_array($method, $definedAssertions, true)
            || \in_array($method, self::LARAVEL_ASSERTIONS, true)
        ) {
            return null;
        }

        if ($method === 'markTestSkipped') {
            $message = $arguments[0] ?? "'Skipped during PHPUnit migration.'";

            return "throw new \\Testo\\Core\\Exception\\SkipTest({$message})";
        }

        if ($method === 'fail') {
            $needsAssert = true;

            return 'Assert::fail(' . ($arguments[0] ?? "'Test failed.'") . ')';
        }

        if ($method === 'addToAssertionCount') {
            $needsAssert = true;
            $warnings[] = 'addToAssertionCount() was replaced with a successful Testo assertion; review assertion-count-sensitive logic.';

            return 'Assert::true(true)';
        }

        if ($method === 'expectException') {
            if (!$this->requireArguments($method, $arguments, 1, $errors)) {
                return null;
            }
            $needsExpect = true;

            return "Expect::exception({$arguments[0]})";
        }

        if ($method === 'expectExceptionMessage') {
            if (!$this->requireArguments($method, $arguments, 1, $errors)) {
                return null;
            }
            $needsExpect = true;
            $warnings[] = 'expectExceptionMessage() was converted to a Throwable expectation; review the exception class if the test can be more specific.';

            return "Expect::exception(\\Throwable::class)->withMessage({$arguments[0]})";
        }

        if ($method === 'expectExceptionCode') {
            if (!$this->requireArguments($method, $arguments, 1, $errors)) {
                return null;
            }
            $needsExpect = true;
            $warnings[] = 'expectExceptionCode() was converted to a Throwable expectation; review the exception class if the test can be more specific.';

            return "Expect::exception(\\Throwable::class)->withCode({$arguments[0]})";
        }

        if (!\str_starts_with($method, 'assert')) {
            $errors[] = "Unsupported PHPUnit call: {$method}().";

            return null;
        }

        $needsAssert = true;
        if (!$this->validateNamedArguments($method, $arguments, $errors)) {
            return null;
        }

        return match ($method) {
            'assertSame' => $this->binaryCall('Assert::same', $method, $arguments, $errors),
            'assertNotSame' => $this->binaryCall('Assert::notSame', $method, $arguments, $errors),
            'assertEquals' => $this->binaryCall('Assert::equals', $method, $arguments, $errors),
            'assertNotEquals' => $this->binaryCall('Assert::notEquals', $method, $arguments, $errors),
            'assertTrue' => $this->unaryCall('Assert::true', $method, $arguments, $errors),
            'assertFalse' => $this->unaryCall('Assert::false', $method, $arguments, $errors),
            'assertNull' => $this->unaryCall('Assert::null', $method, $arguments, $errors),
            'assertNotNull' => $this->unaryCall('Assert::notNull', $method, $arguments, $errors),
            'assertIsString' => $this->typedCall('string', $method, $arguments, $errors),
            'assertIsArray' => $this->typedCall('array', $method, $arguments, $errors),
            'assertIsInt' => $this->typedCall('int', $method, $arguments, $errors),
            'assertIsIterable' => $this->typedCall('iterable', $method, $arguments, $errors),
            'assertIsResource' => $this->predicateCall('is_resource', true, $method, $arguments, $errors),
            'assertIsObject' => $this->predicateCall('is_object', true, $method, $arguments, $errors),
            'assertIsBool' => $this->predicateCall('is_bool', true, $method, $arguments, $errors),
            'assertNotFalse' => $this->notFalseCall($method, $arguments, $errors),
            'assertEmpty' => $this->emptyCall(true, $method, $arguments, $errors),
            'assertNotEmpty' => $this->emptyCall(false, $method, $arguments, $errors),
            'assertStringContainsString' => $this->stringCall('contains', $method, $arguments, $errors),
            'assertStringNotContainsString' => $this->stringCall('notContains', $method, $arguments, $errors),
            'assertStringStartsWith' => $this->stringStartsWithCall($method, $arguments, $errors),
            'assertContains' => $this->containsCall(false, $method, $arguments, $errors),
            'assertNotContains' => $this->containsCall(true, $method, $arguments, $errors),
            'assertCount' => $this->countCall($method, $arguments, $errors),
            'assertMatchesRegularExpression' => $this->regularExpressionCall(true, $method, $arguments, $errors),
            'assertDoesNotMatchRegularExpression' => $this->regularExpressionCall(false, $method, $arguments, $errors),
            'assertFileExists' => $this->fileCall(true, $method, $arguments, $errors),
            'assertFileDoesNotExist' => $this->fileCall(false, $method, $arguments, $errors),
            'assertLessThan' => $this->numericCall('lessThan', $method, $arguments, $errors),
            'assertLessThanOrEqual' => $this->numericCall('lessThanOrEqual', $method, $arguments, $errors),
            'assertGreaterThan' => $this->numericCall('greaterThan', $method, $arguments, $errors),
            'assertGreaterThanOrEqual' => $this->numericCall('greaterThanOrEqual', $method, $arguments, $errors),
            'assertArrayHasKey' => $this->arrayKeyCall(true, $method, $arguments, $errors),
            'assertArrayNotHasKey' => $this->arrayKeyCall(false, $method, $arguments, $errors),
            'assertInstanceOf' => $this->instanceOfCall($method, $arguments, $errors),
            default => $this->unsupportedAssertion($method, $errors),
        };
    }

    /** @param list<string> $arguments @param list<string> $errors */
    private function validateNamedArguments(string $method, array $arguments, array &$errors): bool
    {
        foreach ($arguments as $argument) {
            if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*:(?!:)/', $argument) === 1) {
                $errors[] = "Named arguments in {$method}() require a manual order-preserving conversion.";

                return false;
            }
        }

        return true;
    }

    /** @param list<string> $arguments @param list<string> $errors */
    private function requireArguments(string $method, array $arguments, int $count, array &$errors): bool
    {
        if (\count($arguments) >= $count) {
            return true;
        }

        $errors[] = "Unable to convert {$method}(): expected at least {$count} argument(s).";

        return false;
    }

    /** @param list<string> $arguments @param list<string> $errors */
    private function binaryCall(string $target, string $method, array $arguments, array &$errors): ?string
    {
        if (!$this->requireArguments($method, $arguments, 2, $errors)) {
            return null;
        }

        return $target . '(' . $this->join([$arguments[1], $arguments[0], ...\array_slice($arguments, 2)]) . ')';
    }

    /** @param list<string> $arguments @param list<string> $errors */
    private function unaryCall(string $target, string $method, array $arguments, array &$errors): ?string
    {
        if (!$this->requireArguments($method, $arguments, 1, $errors)) {
            return null;
        }

        return $target . '(' . $this->join($arguments) . ')';
    }

    /** @param list<string> $arguments @param list<string> $errors */
    private function typedCall(string $type, string $method, array $arguments, array &$errors): ?string
    {
        if (!$this->requireArguments($method, $arguments, 1, $errors)) {
            return null;
        }
        if (isset($arguments[1])) {
            return "Assert::true(\\is_{$type}({$arguments[0]}), {$arguments[1]})";
        }

        return "Assert::{$type}({$arguments[0]})";
    }

    /** @param list<string> $arguments @param list<string> $errors */
    private function predicateCall(string $predicate, bool $expected, string $method, array $arguments, array &$errors): ?string
    {
        if (!$this->requireArguments($method, $arguments, 1, $errors)) {
            return null;
        }

        $target = $expected ? 'Assert::true' : 'Assert::false';
        $parts = ["\\{$predicate}({$arguments[0]})"];
        isset($arguments[1]) and $parts[] = $arguments[1];

        return $target . '(' . $this->join($parts) . ')';
    }

    /** @param list<string> $arguments @param list<string> $errors */
    private function notFalseCall(string $method, array $arguments, array &$errors): ?string
    {
        if (!$this->requireArguments($method, $arguments, 1, $errors)) {
            return null;
        }
        $parts = [$arguments[0], 'false'];
        isset($arguments[1]) and $parts[] = $arguments[1];

        return 'Assert::notSame(' . $this->join($parts) . ')';
    }

    /** @param list<string> $arguments @param list<string> $errors */
    private function emptyCall(bool $expectedEmpty, string $method, array $arguments, array &$errors): ?string
    {
        if (!$this->requireArguments($method, $arguments, 1, $errors)) {
            return null;
        }
        $parts = ["(bool) ({$arguments[0]})"];
        isset($arguments[1]) and $parts[] = $arguments[1];

        $assertion = $expectedEmpty ? 'Assert::false' : 'Assert::true';

        return $assertion . '(' . $this->join($parts) . ')';
    }

    /** @param list<string> $arguments @param list<string> $errors */
    private function stringCall(string $operation, string $method, array $arguments, array &$errors): ?string
    {
        if (!$this->requireArguments($method, $arguments, 2, $errors)) {
            return null;
        }
        $parts = [$arguments[0]];
        isset($arguments[2]) and $parts[] = $arguments[2];

        return "Assert::string({$arguments[1]})->{$operation}(" . $this->join($parts) . ')';
    }

    /** @param list<string> $arguments @param list<string> $errors */
    private function stringStartsWithCall(string $method, array $arguments, array &$errors): ?string
    {
        if (!$this->requireArguments($method, $arguments, 2, $errors)) {
            return null;
        }
        $parts = ["\\str_starts_with({$arguments[1]}, {$arguments[0]})"];
        isset($arguments[2]) and $parts[] = $arguments[2];

        return 'Assert::true(' . $this->join($parts) . ')';
    }

    /** @param list<string> $arguments @param list<string> $errors */
    private function containsCall(bool $negative, string $method, array $arguments, array &$errors): ?string
    {
        if (!$this->requireArguments($method, $arguments, 2, $errors)) {
            return null;
        }
        $parts = [$arguments[0]];
        isset($arguments[2]) and $parts[] = $arguments[2];

        if ($negative) {
            return "Assert::iterable({$arguments[1]})->notContains(" . $this->join($parts) . ')';
        }

        $parts = [$arguments[1], $arguments[0]];
        isset($arguments[2]) and $parts[] = $arguments[2];

        return 'Assert::contains(' . $this->join($parts) . ')';
    }

    /** @param list<string> $arguments @param list<string> $errors */
    private function countCall(string $method, array $arguments, array &$errors): ?string
    {
        if (!$this->requireArguments($method, $arguments, 2, $errors)) {
            return null;
        }
        $parts = [$arguments[1], $arguments[0]];
        isset($arguments[2]) and $parts[] = $arguments[2];

        return 'Assert::count(' . $this->join($parts) . ')';
    }

    /** @param list<string> $arguments @param list<string> $errors */
    private function regularExpressionCall(bool $matches, string $method, array $arguments, array &$errors): ?string
    {
        if (!$this->requireArguments($method, $arguments, 2, $errors)) {
            return null;
        }
        $parts = ["\\preg_match({$arguments[0]}, {$arguments[1]})", $matches ? '1' : '0'];
        isset($arguments[2]) and $parts[] = $arguments[2];

        return 'Assert::same(' . $this->join($parts) . ')';
    }

    /** @param list<string> $arguments @param list<string> $errors */
    private function fileCall(bool $exists, string $method, array $arguments, array &$errors): ?string
    {
        if (!$this->requireArguments($method, $arguments, 1, $errors)) {
            return null;
        }
        $parts = ["\\file_exists({$arguments[0]})"];
        isset($arguments[1]) and $parts[] = $arguments[1];

        return ($exists ? 'Assert::true(' : 'Assert::false(') . $this->join($parts) . ')';
    }

    /** @param list<string> $arguments @param list<string> $errors */
    private function numericCall(string $operation, string $method, array $arguments, array &$errors): ?string
    {
        if (!$this->requireArguments($method, $arguments, 2, $errors)) {
            return null;
        }
        $parts = [$arguments[0]];
        isset($arguments[2]) and $parts[] = $arguments[2];

        return "Assert::numeric({$arguments[1]})->{$operation}(" . $this->join($parts) . ')';
    }

    /** @param list<string> $arguments @param list<string> $errors */
    private function arrayKeyCall(bool $exists, string $method, array $arguments, array &$errors): ?string
    {
        if (!$this->requireArguments($method, $arguments, 2, $errors)) {
            return null;
        }
        $parts = ["\\array_key_exists({$arguments[0]}, {$arguments[1]})"];
        isset($arguments[2]) and $parts[] = $arguments[2];

        return ($exists ? 'Assert::true(' : 'Assert::false(') . $this->join($parts) . ')';
    }

    /** @param list<string> $arguments @param list<string> $errors */
    private function instanceOfCall(string $method, array $arguments, array &$errors): ?string
    {
        if (!$this->requireArguments($method, $arguments, 2, $errors)) {
            return null;
        }
        $parts = [$arguments[1], $arguments[0]];
        isset($arguments[2]) and $parts[] = $arguments[2];

        return 'Assert::instanceOf(' . $this->join($parts) . ')';
    }

    /** @param list<string> $errors */
    private function unsupportedAssertion(string $method, array &$errors): null
    {
        $errors[] = "Assertion {$method}() is not supported; add a Testo mapping or rewrite it manually.";

        return null;
    }

    /** @param list<string> $arguments */
    private function join(array $arguments): string
    {
        return \implode(', ', $arguments);
    }

    /** @param list<string> $errors */
    private function rewriteStructure(string $code, string $source, bool $isLaravel, array &$errors): string
    {
        $namespaceCount = 0;
        $code = \preg_replace_callback(
            '/^namespace\s+Tests\\\\(Unit|Feature)(?<suffix>\\\\[^;]+)?;/m',
            static function (array $matches) use (&$namespaceCount): string {
                $namespaceCount++;

                return 'namespace Tests\\Testo\\' . $matches[1] . ($matches['suffix'] ?? '') . ';';
            },
            $code,
            1,
        ) ?? $code;

        if ($namespaceCount !== 1) {
            $errors[] = 'Unable to rewrite the Tests namespace.';
        }

        $code = \str_replace('use PHPUnit\\Framework\\Attributes\\Test;', 'use Testo\\Test;', $code);

        if ($isLaravel) {
            $code = \str_replace('use Tests\\TestCase;', 'use Laratesto\\Testing\\LaravelTestCase;', $code);
            $code = \preg_replace(
                '/\bextends\s+(?:\\\\?Tests\\\\)?TestCase\b/',
                'extends LaravelTestCase',
                $code,
                1,
            ) ?? $code;

            if (!\str_contains($code, 'extends LaravelTestCase')) {
                $errors[] = 'Unable to replace the Laravel PHPUnit base class.';
            }

            $code = $this->rewriteLaravelTraits($code, $source);
            $code = $this->rewriteLaravelLifecycle($code);
            $code = \preg_replace('/\$this->app\b(?!\s*\()/', '$this->app()', $code) ?? $code;
            $code = \str_replace('use Illuminate\\Testing\\TestResponse;', 'use Laratesto\\Testing\\LaravelResponse;', $code);
            $code = \str_replace('\\Illuminate\\Testing\\TestResponse', '\\Laratesto\\Testing\\LaravelResponse', $code);
            $code = \preg_replace('/(?<![A-Za-z0-9_\\\\])TestResponse\b/', 'LaravelResponse', $code) ?? $code;
        } else {
            $code = \preg_replace('/^use PHPUnit\\\\Framework\\\\TestCase;\R/m', '', $code) ?? $code;
            $code = \preg_replace(
                '/\s+extends\s+(?:\\\\?PHPUnit\\\\Framework\\\\)?TestCase\b/',
                '',
                $code,
                1,
            ) ?? $code;
        }

        return $code;
    }

    private function rewriteLaravelTraits(string $code, string $source): string
    {
        $traits = [
            'RefreshDatabase' => 'Laratesto\\Attribute\\RefreshDatabase',
            'DatabaseMigrations' => 'Laratesto\\Attribute\\DatabaseMigrations',
            'DatabaseTransactions' => 'Laratesto\\Attribute\\DatabaseTransactions',
        ];

        foreach ($traits as $short => $replacement) {
            if (!\str_contains($source, "Illuminate\\Foundation\\Testing\\{$short}")) {
                continue;
            }

            $code = \preg_replace(
                '/^use Illuminate\\\\Foundation\\\\Testing\\\\' . $short . ';\R/m',
                '',
                $code,
            ) ?? $code;
            $code = \preg_replace('/^\s{4}use ' . $short . ';\R/m', '', $code, 1) ?? $code;
            $code = $this->addUse($code, $replacement);
            $code = \preg_replace(
                '/^((?:final\s+|abstract\s+)?class\s+[A-Za-z_][A-Za-z0-9_]*)/m',
                "#[{$short}]\n$1",
                $code,
                1,
            ) ?? $code;
        }

        return $code;
    }

    private function rewriteLaravelLifecycle(string $code): string
    {
        $code = \preg_replace('/\bfunction\s+setUp\s*\(\s*\)/', 'function setUpLaravel()', $code) ?? $code;
        $code = \preg_replace('/\bfunction\s+tearDown\s*\(\s*\)/', 'function tearDownLaravel()', $code) ?? $code;
        $code = \preg_replace('/^\s*parent::(?:setUp|tearDown)\(\);\R/m', '', $code) ?? $code;

        return $code;
    }

    private function addUse(string $code, string $class): string
    {
        if (\preg_match('/^use ' . \preg_quote($class, '/') . ';$/m', $code) === 1) {
            return $code;
        }

        return \preg_replace(
            '/^(namespace\s+[^;]+;\R)/m',
            "$1\nuse {$class};\n",
            $code,
            1,
        ) ?? $code;
    }
}
