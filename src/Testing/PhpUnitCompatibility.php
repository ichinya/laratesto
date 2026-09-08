<?php

declare(strict_types=1);

namespace Laratesto\Testing;

use Testo\Assert;

/**
 * Argument and matching semantics needed by mechanically migrated PHPUnit calls.
 *
 * The typed string parameters deliberately perform PHP's normal caller-dependent
 * coercion. Casting inside a strict helper would also accept invalid source calls.
 */
final class PhpUnitCompatibility
{
    /**
     * @template T of object
     * @param class-string<T> $originalClassName
     * @return T&\PHPUnit\Framework\MockObject\Stub
     */
    public static function createStub(string $originalClassName, ?string $testClass = null): \PHPUnit\Framework\MockObject\Stub
    {
        if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
            throw new \LogicException('Migrated createStub() requires phpunit/phpunit as a dev dependency.');
        }
        if ($testClass !== null && class_exists(\PHPUnit\Metadata\Parser\Registry::class)
            && !\PHPUnit\Metadata\Parser\Registry::parser()->forClass($testClass)->isDisableReturnValueGenerationForTestDoubles()->isEmpty()) {
            return Internal\PhpUnitStubWithoutReturnValuesFactory::make($originalClassName);
        }
        return Internal\PhpUnitStubFactory::make($originalClassName);
    }

    /** Run framework/package assertions while recording their outcome in Testo. */
    public static function run(callable $assertions): mixed
    {
        if (!\class_exists(\PHPUnit\Framework\Assert::class)) {
            throw new \LogicException('Laravel package assertions require phpunit/phpunit as a dev dependency.');
        }
        // PHPUnit 12/13's assertion exporter reads the TextUI configuration even
        // when only its assertion library is used. Initialize defaults without
        // loading phpunit.xml, executing its bootstrap, or replacing an active
        // PHPUnit runner's configuration.
        if (\class_exists(\PHPUnit\TextUI\Configuration\Registry::class)) {
            try {
                \PHPUnit\TextUI\Configuration\Registry::get();
            } catch (\AssertionError|\TypeError) {
                (new \PHPUnit\TextUI\Configuration\Builder())->build(['phpunit', '--no-configuration']);
            }
        }
        $before = \PHPUnit\Framework\Assert::getCount();
        try {
            $result = $assertions();
        } catch (\PHPUnit\Framework\AssertionFailedError $failure) {
            Assert::fail($failure->getMessage());
        }
        self::addToAssertionCount(\max(0, \PHPUnit\Framework\Assert::getCount() - $before));
        return $result;
    }

    public static function facade(string $facade, string $method, array $arguments): mixed
    {
        return self::run(static fn(): mixed => $facade::$method(...$arguments));
    }

    /**
     * @template T of \Illuminate\Mail\Mailable
     * @param T $mailable
     * @return T
     */
    public static function mailable(\Illuminate\Mail\Mailable $mailable, string $method, array $arguments): \Illuminate\Mail\Mailable
    {
        return self::run(static fn(): object => $mailable->$method(...$arguments));
    }

    public static function exceptionMessagePattern(string $message): string
    {
        // PHPUnit treats an empty expected message as an exactly empty message.
        return $message === '' ? '~\A\z~' : '~' . \preg_quote($message, '~') . '~';
    }

    public static function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        Assert::string($haystack)->contains($needle, $message);
    }

    public static function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        Assert::string($haystack)->notContains($needle, $message);
    }

    public static function assertDoesNotMatchRegularExpression(string $pattern, string $string, string $message = ''): void
    {
        Assert::same(\preg_match($pattern, $string), 0, $message);
    }

    public static function assertEmpty(mixed $actual, string $message = ''): void
    {
        Assert::true(self::isEmpty($actual), $message);
    }

    public static function assertNotEmpty(mixed $actual, string $message = ''): void
    {
        Assert::false(self::isEmpty($actual), $message);
    }

    public static function assertIsArray(mixed $actual, string $message = ''): void
    {
        Assert::true(\is_array($actual), $message);
    }

    public static function assertIsString(mixed $actual, string $message = ''): void
    {
        Assert::true(\is_string($actual), $message);
    }

    public static function assertIsInt(mixed $actual, string $message = ''): void
    {
        Assert::true(\is_int($actual), $message);
    }

    public static function assertIsBool(mixed $actual, string $message = ''): void
    {
        Assert::true(\is_bool($actual), $message);
    }

    public static function assertIsObject(mixed $actual, string $message = ''): void
    {
        Assert::true(\is_object($actual), $message);
    }

    public static function assertNotFalse(mixed $actual, string $message = ''): void
    {
        Assert::notSame($actual, false, $message);
    }

    public static function assertMatchesRegularExpression(string $pattern, string $string, string $message = ''): void
    {
        Assert::same(\preg_match($pattern, $string), 1, $message);
    }

    public static function assertStringStartsWith(string $prefix, string $string, string $message = ''): void
    {
        Assert::true(\str_starts_with($string, $prefix), $message);
    }

    public static function assertFileExists(string $filename, string $message = ''): void
    {
        Assert::true(\is_file($filename), $message);
    }

    public static function assertFileDoesNotExist(string $filename, string $message = ''): void
    {
        Assert::false(\is_file($filename), $message);
    }

    public static function assertDirectoryExists(string $directory, string $message = ''): void
    {
        Assert::true(\is_dir($directory), $message);
    }

    public static function assertDirectoryDoesNotExist(string $directory, string $message = ''): void
    {
        Assert::false(\is_dir($directory), $message);
    }

    public static function assertNotContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        Assert::iterable($haystack)->notContains($needle, $message);
    }

    public static function addToAssertionCount(int $count): void
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('Negative PHPUnit assertion counts require manual migration.');
        }

        for ($index = 0; $index < $count; ++$index) {
            Assert::true(true);
        }
    }

    private static function isEmpty(mixed $actual): bool
    {
        return $actual instanceof \EmptyIterator
            || ($actual instanceof \Countable ? \count($actual) === 0 : !$actual);
    }
}
