<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Configuration;

use Laratesto\Rector\Configuration\BaseClassConfiguration;
use Testo\Assert;
use Testo\Test;

/**
 * The configure() contract: every parse starts from the defaults, so re-configuring
 * the same shared rule instance can never leak a previous base list or target mode.
 */
final class BaseClassConfigurationTest
{
    #[Test]
    public function defaultsAreThePublicSetDefaults(): void
    {
        $defaults = BaseClassConfiguration::defaults();

        Assert::same(
            ['Tests\TestCase', 'Illuminate\Foundation\Testing\TestCase'],
            $defaults->laravelBases,
        );
        Assert::same('base_class', $defaults->targetMode);
    }

    #[Test]
    public function emptyConfigurationFallsBackToDefaults(): void
    {
        $configuration = BaseClassConfiguration::fromArray([]);

        Assert::same(BaseClassConfiguration::defaults()->laravelBases, $configuration->laravelBases);
        Assert::same(BaseClassConfiguration::defaults()->targetMode, $configuration->targetMode);
    }

    #[Test]
    public function reConfigurationAfterACustomOneReturnsToDefaults(): void
    {
        $custom = BaseClassConfiguration::fromArray([
            'base_classes' => ['Tests\ApiTestCase'],
            'target_mode' => 'trait',
        ]);

        Assert::same(['Tests\ApiTestCase'], $custom->laravelBases);
        Assert::same('trait', $custom->targetMode);

        // The next configure() call over the same instance must be indistinguishable
        // from a first one.
        $next = BaseClassConfiguration::fromArray(['target_mode' => 'base_class']);

        Assert::same(BaseClassConfiguration::defaults()->laravelBases, $next->laravelBases);
        Assert::same('base_class', $next->targetMode);
    }

    #[Test]
    public function explicitBaseClassesAreTrimmedAndDeduplicated(): void
    {
        $configuration = BaseClassConfiguration::fromArray([
            'base_classes' => ['  Tests\ApiTestCase  ', 'Tests\ApiTestCase', 'App\Tests\Case'],
        ]);

        Assert::same(['Tests\ApiTestCase', 'App\Tests\Case'], $configuration->laravelBases);
    }

    /**
     * PR #8 review point 9: the documented `Tests/ApiTestCase` spelling and the
     * other accepted shell spellings all canonicalize to the same backslash form.
     */
    #[Test]
    public function acceptedSpellingsAreCanonicalized(): void
    {
        $configuration = BaseClassConfiguration::fromArray([
            'base_classes' => [
                'Tests/ApiTestCase',
                '/Tests/ApiTestCase',
                '\\Tests\\TestCase',
                ' Illuminate\\Foundation/Testing\\TestCase ',
            ],
        ]);

        Assert::same(
            ['Tests\ApiTestCase', 'Tests\TestCase', 'Illuminate\Foundation\Testing\TestCase'],
            $configuration->laravelBases,
        );
    }

    #[Test]
    public function canonicalDuplicatesAreRemoved(): void
    {
        $configuration = BaseClassConfiguration::fromArray([
            'base_classes' => ['Tests/TestCase', 'Tests\TestCase', '\\Tests\\TestCase'],
        ]);

        Assert::same(['Tests\TestCase'], $configuration->laravelBases);
    }

    /**
     * PR #8 review point 9: only valid leading separators and surrounding
     * whitespace are trimmed — anything that cannot be a PHP class name is
     * rejected instead of being accepted as a base no class can ever extend.
     */
    #[Test]
    public function malformedBaseClassNamesAreRejected(): void
    {
        // The double backslash a POSIX shell leaves behind: an empty segment.
        $this->assertInvalidBaseConfiguration(['base_classes' => ['Tests\\\\ApiTestCase']]);
        // A trailing separator is not a valid class name.
        $this->assertInvalidBaseConfiguration(['base_classes' => ['Tests\ApiTestCase\\']]);
        // Doubled forward slashes also leave an empty segment.
        $this->assertInvalidBaseConfiguration(['base_classes' => ['Tests//TestCase']]);
        // Labels must start with a letter or an underscore.
        $this->assertInvalidBaseConfiguration(['base_classes' => ['9Tests\\ApiTestCase']]);
        // Interior whitespace is not surrounding whitespace.
        $this->assertInvalidBaseConfiguration(['base_classes' => ['Tests\Api TestCase']]);
        // Separator-only input.
        $this->assertInvalidBaseConfiguration(['base_classes' => ['/']]);
        $this->assertInvalidBaseConfiguration(['base_classes' => ['\\']]);
    }

    /**
     * Zero or exactly one leading separator is accepted; a repeated or mixed
     * leading pair would leave an empty namespace segment and is rejected
     * instead of silently trimmed away.
     */
    #[Test]
    public function repeatedOrMixedLeadingSeparatorsAreRejected(): void
    {
        // Doubled leading backslash.
        $this->assertInvalidBaseConfiguration(['base_classes' => ['\\\\Tests\\TestCase']]);
        // Doubled leading slash.
        $this->assertInvalidBaseConfiguration(['base_classes' => ['//Tests/TestCase']]);
        // Mixed leading separators.
        $this->assertInvalidBaseConfiguration(['base_classes' => ['/\\Tests/TestCase']]);
        $this->assertInvalidBaseConfiguration(['base_classes' => ['\\/Tests/TestCase']]);
    }

    /**
     * Labels are PHP identifiers: dots, colons and embedded whitespace can
     * never appear in a resolvable class name.
     */
    #[Test]
    public function dotsColonsAndEmbeddedWhitespaceAreRejected(): void
    {
        $this->assertInvalidBaseConfiguration(['base_classes' => ['Tests.\\ApiTestCase']]);
        $this->assertInvalidBaseConfiguration(['base_classes' => ['.Tests\\ApiTestCase']]);
        $this->assertInvalidBaseConfiguration(['base_classes' => ['Tests:ApiTestCase']]);
        $this->assertInvalidBaseConfiguration(['base_classes' => ['Tests\\Api TestCase']]);
    }

    #[Test]
    public function aRejectionMessageNamesTheOffendingValue(): void
    {
        try {
            BaseClassConfiguration::fromArray(['base_classes' => ['Tests\\\\ApiTestCase']]);

            Assert::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $rejection) {
            Assert::true(\str_contains($rejection->getMessage(), 'Invalid base class'), $rejection->getMessage());
            Assert::true(\str_contains($rejection->getMessage(), 'ApiTestCase'), $rejection->getMessage());
            Assert::true(\str_contains($rejection->getMessage(), 'malformed'), $rejection->getMessage());
        }
    }

    #[Test]
    public function anUnknownTargetModeIsRejected(): void
    {
        $this->assertInvalidBaseConfiguration(['target_mode' => 'trait_class']);
    }

    #[Test]
    public function nonStringAndEmptyBaseEntriesAreRejected(): void
    {
        $this->assertInvalidBaseConfiguration(['base_classes' => ['A', 42]]);
        $this->assertInvalidBaseConfiguration(['base_classes' => ['  ']]);
    }

    #[Test]
    public function anEmptyBaseClassListIsRejected(): void
    {
        $this->assertInvalidBaseConfiguration(['base_classes' => []]);
    }

    #[Test]
    public function anUnknownConfigurationKeyIsRejected(): void
    {
        $this->assertInvalidBaseConfiguration(['base_class' => ['Tests\ApiTestCase']]);
        $this->assertInvalidBaseConfiguration(['base_clases' => ['Tests\ApiTestCase']]);
        $this->assertInvalidBaseConfiguration([
            'base_classes' => ['Tests\ApiTestCase'],
            'targetmode' => 'trait',
        ]);
    }

    private function assertInvalidBaseConfiguration(array $configuration): void
    {
        try {
            BaseClassConfiguration::fromArray($configuration);

            Assert::fail('Expected an InvalidArgumentException for ' . \json_encode($configuration));
        } catch (\InvalidArgumentException) {
            Assert::true(true);
        }
    }
}
