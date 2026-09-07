<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Rules\LaravelDatabaseTraitsRector;
use Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector;
use Laratesto\Rector\Tests\Support\FrontierPipeline;
use Testo\Assert;
use Testo\Test;

final class DatabaseRuleSkipFrontierPipelineTest
{
    #[Test]
    public function databaseRuleSkipPreservesCurrentClassAndDependentDescendant(): void
    {
        foreach ([
            [LaravelDatabaseTraitsRector::class => ['Base.php']],
            [LaravelDatabaseTraitsRector::class => ['*ase.php']],
            [LaravelDatabaseTraitsRector::class],
        ] as $skip) {
            $output = FrontierPipeline::run(self::corpus(), $skip);
            Assert::string($output['Base.php'])->contains('required LaravelDatabaseTraitsRector conversion is excluded');
            Assert::string($output['Base.php'])->contains('extends \\Illuminate\\Foundation\\Testing\\TestCase');
            Assert::string($output['Base.php'])->contains('use \\Illuminate\\Foundation\\Testing\\DatabaseTransactions;');
            Assert::string($output['Base.php'])->notContains('#[\\Laratesto\\Attribute\\DatabaseTransactions');
            Assert::string($output['Child.php'])->contains('laratesto-residual(code=CLASS_UNSAFE_HIERARCHY');
            Assert::string($output['Child.php'])->contains('parent::setUp();');
            Assert::string($output['Child.php'])->notContains('setUpLaravel');
            Assert::string($output['Control.php'])->contains('extends \\Laratesto\\Testing\\LaravelTestCase');
        }
    }

    #[Test]
    public function databaseRuleSkipInOneFileGatesAllItsDatabaseConsumers(): void
    {
        $files = self::corpus();
        $files['Base.php'] .= "\nnamespace DbFrontier; final class SameFileChild extends \\Tests\\TestCase { protected function setUp(): void { parent::setUp(); } public function testLocal(): void {} }\n";
        $output = FrontierPipeline::run($files, [LaravelDatabaseTraitsRector::class => ['Base.php']]);
        Assert::string($output['Base.php'])->notContains('setUpLaravel');
        Assert::string($output['Base.php'])->notContains('#[\\Testo\\Test]');
        Assert::string($output['Base.php'])->notContains('extends \\Laratesto\\Testing\\LaravelTestCase');
    }

    #[Test]
    public function unneededSkipsAndEnabledDatabaseConversionRemainSafe(): void
    {
        foreach ([
            [],
            [LaravelDatabaseTraitsRector::class => ['Control.php']],
            [LaravelSourceCompatibleCallsRector::class => ['Base.php']],
        ] as $skip) {
            $output = FrontierPipeline::run(self::corpus(), $skip);
            Assert::string($output['Base.php'])->contains('extends \\Laratesto\\Testing\\LaravelTestCase');
            Assert::string($output['Base.php'])->contains('#[\\Laratesto\\Attribute\\DatabaseTransactions]');
            Assert::string($output['Base.php'])->notContains('use \\Illuminate\\Foundation\\Testing\\DatabaseTransactions;');
            Assert::string($output['Child.php'])->contains('setUpLaravel');
            Assert::string($output['Child.php'])->contains('#[\\Testo\\Test]');
            Assert::string($output['Child.php'])->notContains('laratesto-residual');
        }
    }

    #[Test]
    public function baseRuleAndWholeFileSkipsStillPreserveDependentChildren(): void
    {
        foreach ([[LaravelBaseClassRector::class => ['Base.php']], ['Base.php']] as $skip) {
            $output = FrontierPipeline::run(self::corpus(), $skip);
            Assert::string($output['Base.php'])->contains('extends \\Illuminate\\Foundation\\Testing\\TestCase');
            Assert::string($output['Child.php'])->contains('skip configuration');
            Assert::string($output['Child.php'])->notContains('setUpLaravel');
            Assert::string($output['Control.php'])->contains('extends \\Laratesto\\Testing\\LaravelTestCase');
        }
    }

    /** @return array<string, string> */
    private static function corpus(): array
    {
        return [
            'Base.php' => <<<'PHP'
                <?php
                namespace Tests;
                abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
                {
                    use \Illuminate\Foundation\Testing\DatabaseTransactions;
                    protected function setUp(): void { parent::setUp(); }
                }
                PHP,
            'Child.php' => <<<'PHP'
                <?php
                namespace DbFrontier;
                final class Child extends \Tests\TestCase
                {
                    protected function setUp(): void { parent::setUp(); }
                    public function testChild(): void {}
                }
                PHP,
            'Control.php' => <<<'PHP'
                <?php
                namespace DbFrontier;
                final class Control extends \Illuminate\Foundation\Testing\TestCase
                {
                    protected function setUp(): void { parent::setUp(); }
                    public function testControl(): void {}
                }
                PHP,
        ];
    }
}
