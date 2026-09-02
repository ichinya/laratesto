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
