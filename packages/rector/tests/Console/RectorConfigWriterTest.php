<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Console;

use Laratesto\Rector\Console\RectorConfigWriter;
use Testo\Assert;
use Testo\Test;

/**
 * PR #8 review point 9: the generated Rector configuration carries base class
 * names in canonical `Tests\ApiTestCase` form — documented forward-slash input
 * is canonicalized, extras deduplicate against the defaults, and malformed or
 * empty values are rejected instead of reaching the rule.
 */
final class RectorConfigWriterTest
{
    #[Test]
    public function withoutExtraBaseClassesOnlyTheTargetModeIsOverridden(): void
    {
        $writer = new RectorConfigWriter();
        $config = $writer->write('trait', ['tests/Unit'], []);

        try {
            $code = (string) \file_get_contents($config);

            Assert::false(\str_contains($code, 'BASE_CLASSES'), 'No base-class override is needed without extras.');
            Assert::true(\str_contains($code, "'trait'"), 'The target mode override must be written.');
        } finally {
            @\unlink($config);
        }
    }

    #[Test]
    public function extraBaseClassesAreWrittenCanonicalAndDeduplicated(): void
    {
        $writer = new RectorConfigWriter();
        $config = $writer->write('base_class', ['tests'], ['Tests/ApiTestCase', ' Tests\\TestCase ']);

        try {
            $code = (string) \file_get_contents($config);

            // var_export() single-quotes strings, so the canonical backslash form
            // appears as 'Tests\\ApiTestCase' in the generated PHP.
            Assert::true(\str_contains($code, "'Tests\\\\ApiTestCase'"), 'The forward-slash spelling must be canonicalized: ' . $code);
            Assert::same(1, \substr_count($code, "'Tests\\\\TestCase'"), 'A forward-slash extra equal to a default deduplicates against it.');
            Assert::same(1, \substr_count($code, "'Illuminate\\\\Foundation\\\\Testing\\\\TestCase'"));
            Assert::same(0, \substr_count($code, "'Tests/"), 'No forward-slash spelling may survive.');
        } finally {
            @\unlink($config);
        }
    }

    #[Test]
    public function aMalformedExtraBaseClassIsRejected(): void
    {
        try {
            (new RectorConfigWriter())->write('base_class', ['tests'], ['Tests\\\\ApiTestCase']);

            Assert::fail('Expected an InvalidArgumentException for the double-separator input.');
        } catch (\InvalidArgumentException $rejection) {
            Assert::true(\str_contains($rejection->getMessage(), 'Invalid base class'), $rejection->getMessage());
        }
    }

    #[Test]
    public function anEmptyExtraBaseClassIsRejected(): void
    {
        try {
            (new RectorConfigWriter())->write('base_class', ['tests'], ['   ']);

            Assert::fail('Expected an InvalidArgumentException for whitespace-only input.');
        } catch (\InvalidArgumentException $rejection) {
            Assert::true(\str_contains($rejection->getMessage(), 'Invalid base class'), $rejection->getMessage());
        }
    }
}
