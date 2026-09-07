<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Tests\Support\FrontierPipeline;
use Testo\Assert;
use Testo\Test;

final class TraitDependencyFrontierPipelineTest
{
    #[Test]
    public function cliSourcePathsPreserveUnsafeDescendantsWithoutConfiguredPaths(): void
    {
        $files = [
            'Base.php' => '<?php namespace Tests; abstract class TestCase extends \\Illuminate\\Foundation\\Testing\\TestCase {}',
            'Child.php' => '<?php namespace TraitFrontier; trait AppAccess { protected function helper(): void { $this->app->make("config"); } } final class Child extends \\Tests\\TestCase { use AppAccess; public function testChild(): void {} }',
        ];
        foreach ([[], ['Base.php', 'Child.php']] as $cliPaths) {
            $output = FrontierPipeline::run($files, cliPaths: $cliPaths);
            Assert::string($output['Base.php'])->contains('preserve the shared source base');
            Assert::string($output['Base.php'])->notContains('extends \\Laratesto\\Testing\\LaravelTestCase');
            Assert::string($output['Child.php'])->contains('laratesto-residual(code=CLASS_UNSAFE_HIERARCHY');
            Assert::string($output['Child.php'])->notContains('#[\\Testo\\Test]');
        }
    }

    #[Test]
    public function unsafeDescendantTraitPreservesSharedBaseInEitherFileOrder(): void
    {
        $base = <<<'PHP'
            <?php
            namespace Tests;
            abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase {}
            PHP;
        $child = <<<'PHP'
            <?php
            namespace TraitFrontier;
            trait AppHelper { protected function helper(): void { $this->app->make('config'); } }
            trait NestedApp { use AppHelper; }
            final class Child extends \Tests\TestCase { use NestedApp; public function testChild(): void {} }
            PHP;
        foreach ([
            ['ABase.php' => $base, 'ZChild.php' => $child],
            ['ZBase.php' => $base, 'AChild.php' => $child],
            ['Together.php' => $base . "\n" . substr($child, 5)],
        ] as $files) {
            $output = FrontierPipeline::run($files);
            foreach ($output as $bytes) {
                Assert::string($bytes)->contains('laratesto-residual(code=CLASS_UNSAFE_HIERARCHY');
                Assert::string($bytes)->notContains('extends \\Laratesto\\Testing\\LaravelTestCase');
                Assert::string($bytes)->notContains('#[\\Testo\\Test]');
            }
            Assert::string(implode("\n", $output))->contains('preserve the shared source base');
        }
    }

    #[Test]
    public function traitBodiesAndDiscoveryPreserveConsumersAndDescendants(): void
    {
        $files = [
            'Support.php' => <<<'PHP'
                <?php
                namespace TraitFrontier;
                use PHPUnit\Framework\Attributes\Test as PHPUnitTest;
                trait AppHelper { protected function helper(): void { $this->app->make('config'); } }
                trait NestedApp { use AppHelper; }
                trait NamedTest { public function testProvided(): void {} }
                trait AnnotatedTest { /** @test */ public function provided(): void {} }
                trait AttributedTest { #[PHPUnitTest] public function provided(): void {} }
                trait ParentAssert { protected function helper(): void { parent::assertTrue(true); } }
                trait Ordinary { protected function clock(): int { return 42; } }
                trait OrdinaryAlias { use Ordinary { clock as currentClock; } }
                trait AliasTest { use Ordinary { clock as public testClock; } }
                trait BootsFlag { public bool $booted = false; protected function setUpBootsFlag(): void { $this->booted = true; } }
                trait TargetTest {
                    use OrdinaryAlias;
                    #[\Testo\Test] public function testProvided(): void {
                        if ($this->currentClock() !== 42 || self::clock() !== 42) {
                            throw new \RuntimeException('Ordinary trait helper changed');
                        }
                    }
                }
                PHP,
            'Base.php' => <<<'PHP'
                <?php
                namespace Tests;
                abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
                {
                    use \TraitFrontier\NestedApp;
                    protected function setUp(): void { parent::setUp(); }
                }
                PHP,
            'Child.php' => <<<'PHP'
                <?php
                namespace TraitFrontier;
                final class Child extends \Tests\TestCase
                {
                    protected function setUp(): void { parent::setUp(); }
                    public function testChild(): void {}
                }
                PHP,
        ];
        foreach (['NamedTest', 'AnnotatedTest', 'AttributedTest', 'ParentAssert', 'AliasTest', 'BootsFlag'] as $trait) {
            $files[$trait . '.php'] = '<?php namespace TraitFrontier; final class ' . $trait . 'Consumer extends \\Illuminate\\Foundation\\Testing\\TestCase { use ' . $trait . '; public function testLocal(): void {} }';
        }
        foreach (['Ordinary', 'OrdinaryAlias', 'TargetTest'] as $trait) {
            $files[$trait . '.php'] = '<?php namespace TraitFrontier; final class ' . $trait . 'Consumer extends \\Illuminate\\Foundation\\Testing\\TestCase { use ' . $trait . '; public function testLocal(): void {} }';
        }

        $output = FrontierPipeline::run($files);
        Assert::same($files['Support.php'], $output['Support.php'], 'Shared trait declarations must remain byte-identical.');
        foreach (['Base', 'Child', 'NamedTest', 'AnnotatedTest', 'AttributedTest', 'ParentAssert', 'AliasTest', 'BootsFlag'] as $file) {
            Assert::string($output[$file . '.php'])->contains('laratesto-residual(code=CLASS_UNSAFE_HIERARCHY');
            Assert::string($output[$file . '.php'])->notContains('extends \\Laratesto\\Testing\\LaravelTestCase');
            Assert::string($output[$file . '.php'])->notContains('#[\\Testo\\Test]');
            Assert::string($output[$file . '.php'])->notContains('setUpLaravel');
        }
        Assert::string($output['Base.php'])->contains('property app');
        Assert::string($output['ParentAssert.php'])->contains('method assertTrue');
        Assert::string($output['BootsFlag.php'])->contains('Laravel trait hook setUpBootsFlag()');
        foreach (['Ordinary', 'OrdinaryAlias', 'TargetTest'] as $file) {
            Assert::string($output[$file . '.php'])->contains('extends \\Laratesto\\Testing\\LaravelTestCase');
            Assert::string($output[$file . '.php'])->contains('#[\\Testo\\Test]');
            Assert::string($output[$file . '.php'])->notContains('laratesto-residual');
        }
    }
}
