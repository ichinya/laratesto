<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Tests\Support\FrontierPipeline;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

final class TraitDependencyFrontierPipelineTest
{
    #[Test]
    public function lifecycleDescendantPreservesSharedBaseAndSiblingInEitherFileOrder(): void
    {
        $base = '<?php namespace Tests; abstract class TestCase extends \\Illuminate\\Foundation\\Testing\\TestCase {}';
        $support = '<?php namespace Tests; trait Setup { protected function setUp(): void { parent::setUp(); $this->ready = true; } }';
        $child = '<?php namespace Tests; final class ChildTest extends TestCase { use Setup; public bool $ready = false; public function testValue(): void {} } final class SafeSibling extends TestCase { public function testSibling(): void {} }';
        foreach ([
            ['ABase.php' => $base, 'Support.php' => $support, 'ZChild.php' => $child],
            ['AChild.php' => $child, 'Support.php' => $support, 'ZBase.php' => $base],
            ['Together.php' => $base . "\n" . substr($support, 5) . "\n" . substr($child, 5)],
        ] as $files) {
            $output = FrontierPipeline::run($files, cliPaths: []);
            $combined = implode("\n", $output);
            Assert::string($combined)->contains('preserve the shared source base');
            Assert::string($combined)->contains('trait Tests\\Setup provides setUp()');
            Assert::string($combined)->contains('extends \\Illuminate\\Foundation\\Testing\\TestCase');
            Assert::string($combined)->notContains('extends \\Laratesto\\Testing\\LaravelTestCase');
            Assert::string($combined)->notContains('#[\\Testo\\Test]');
            Assert::string($combined)->contains('final class SafeSibling extends TestCase');
            Assert::same(substr_count($combined, 'code=CLASS_UNSAFE_HIERARCHY'), 3);

            // Declare base and provider before the child when executing the corpus.
            $orderedOutput = count($output) === 1 ? $output : [
                'Base.php' => $output[isset($output['ABase.php']) ? 'ABase.php' : 'ZBase.php'],
                'Support.php' => $output['Support.php'],
                'Child.php' => $output[isset($output['ZChild.php']) ? 'ZChild.php' : 'AChild.php'],
            ];
            self::assertSourceLifecycleRuns([$base, $support, $child], array_values($orderedOutput));
        }
    }

    #[Test]
    public function nestedAliasedBootstrapAndDirectLifecycleDependenciesPreserveTheirBase(): void
    {
        $variants = [
            'nested-setup' => ['trait Setup { protected function setUp(): void { parent::setUp(); } } trait Provider { use Setup; }', 'use Provider;', 'provides setUp()'],
            'nested-teardown-alias' => ['trait Worker { protected function finish(): void { parent::tearDown(); } } trait Provider { use Worker { finish as tearDown; } }', 'use Provider;', 'adapts a used trait method to tearDown()'],
            'class-setup-alias' => ['trait Worker { protected function initialize(): void {} }', 'use Worker { initialize as setUp; }', 'trait adaptations'],
            'trait-application' => ['trait Provider { public function createApplication(): \\Illuminate\\Foundation\\Application { return parent::createApplication(); } }', 'use Provider;', 'provides custom bootstrap method createApplication()'],
            'nested-application-alias' => ['trait Worker { public function application(): \\Illuminate\\Foundation\\Application { return new \\Illuminate\\Foundation\\Application(); } } trait Provider { use Worker { application as createApplication; } }', 'use Provider;', 'adapts a used trait method to custom bootstrap method createApplication()'],
            'trait-boot-callback' => ['trait Provider { public function beforeApplicationDestroyed($callback) { parent::beforeApplicationDestroyed($callback); } }', 'use Provider;', 'provides custom bootstrap method beforeApplicationDestroyed()'],
            'trait-named-hook' => ['trait Provider { protected function setUpProvider(): void {} }', 'use Provider;', 'Laravel trait hook setUpProvider()'],
            'direct-nested-parent-call' => ['', 'protected function setUp(): void { if (true) { parent::setUp(); } }', 'setUp() contains a parent lifecycle call'],
            'direct-application' => ['', 'public function createApplication(): \\Illuminate\\Foundation\\Application { return parent::createApplication(); }', 'custom bootstrap method createApplication()'],
        ];
        foreach ($variants as $variant => [$traits, $body, $reason]) {
            $output = FrontierPipeline::run([
                'Base.php' => '<?php namespace Tests; abstract class TestCase extends \\Illuminate\\Foundation\\Testing\\TestCase {}',
                'Provider.php' => '<?php namespace Tests; ' . $traits,
                'Child.php' => '<?php namespace Tests; final class ChildTest extends TestCase { ' . $body . ' public function testValue(): void {} }',
            ]);
            Assert::string($output['Base.php'])->contains('preserve the shared source base', $variant);
            Assert::string($output['Base.php'])->contains($reason, $variant);
            Assert::string($output['Base.php'])->contains('extends \\Illuminate\\Foundation\\Testing\\TestCase');
            Assert::string($output['Child.php'])->notContains('#[\\Testo\\Test]');
        }
    }

    #[Test]
    public function ordinaryNestedAliasesAndConvertibleLifecycleDoNotBlockTheirBase(): void
    {
        foreach ([['Base.php', 'Child.php'], ['Child.php', 'Base.php']] as $cliPaths) {
            $output = FrontierPipeline::run([
                'Base.php' => '<?php namespace Tests; abstract class TestCase extends \\Illuminate\\Foundation\\Testing\\TestCase { protected function setUp(): void { parent::setUp(); } }',
                'Child.php' => '<?php namespace Tests; trait Clock { protected function clock(): int { return 42; } } trait Provider { use Clock { clock as currentClock; } #[\\Testo\\Test] public function clockWorks(): void { \\Testo\\Assert::same($this->currentClock(), 42); } } final class ChildTest extends TestCase { use Provider; protected function setUp(): void { parent::setUp(); } public function testValue(): void {} }',
            ], cliPaths: $cliPaths);
            Assert::string(implode("\n", $output))->notContains('laratesto-residual');
            Assert::string($output['Base.php'])->contains('extends \\Laratesto\\Testing\\LaravelTestCase');
            Assert::string($output['Base.php'])->contains('function setUpLaravel()');
            Assert::string($output['Child.php'])->contains('parent::setUpLaravel();');
            Assert::string($output['Child.php'])->contains('#[\\Testo\\Test]');
            Assert::same(substr_count($output['Child.php'], '#[\\Testo\\Test]'), 2);
        }
    }

    /**
     * @param list<string> $input
     * @param list<string> $output
     */
    private static function assertSourceLifecycleRuns(array $input, array $output): void
    {
        $root = dirname(__DIR__, 4);
        $tmp = sys_get_temp_dir() . '/laratesto-preserved-lifecycle-' . bin2hex(random_bytes(8));
        Assert::true(mkdir($tmp));
        // PHPUnit is not installed in the rule-test environment. Only its unused
        // constructor is doubled; the Application and complete setUp path are Laravel.
        file_put_contents($tmp . '/runtime.php', <<<'PHP'
            <?php
            namespace PHPUnit\Framework { class TestCase { public function __construct(string $name = '') {} } }
            namespace {
                require $argv[1] . '/vendor/autoload.php';
                require $argv[2];
                $case = new \Tests\ChildTest('testValue');
                if (! $case instanceof \Illuminate\Foundation\Testing\TestCase) {
                    throw new \RuntimeException('The source lifecycle lost its Laravel parent');
                }
                $app = new \Illuminate\Foundation\Application(dirname($argv[2]));
                $app->instance('events', new \Illuminate\Events\Dispatcher());
                (new \ReflectionProperty($case, 'app'))->setValue($case, $app);
                (new \ReflectionMethod($case, 'setUp'))->invoke($case);
                if (! (new \ReflectionProperty($case, 'setUpHasRun'))->getValue($case) || ! $case->ready) {
                    throw new \RuntimeException('Laravel or trait setup did not run');
                }
                echo json_encode(['laravel' => \Illuminate\Foundation\Application::VERSION, 'setup' => true, 'traitReady' => $case->ready], JSON_THROW_ON_ERROR);
            }
            PHP);
        foreach (['input' => $input, 'output' => $output] as $side => $files) {
            $path = $tmp . '/' . $side . '.php';
            file_put_contents($path, '<?php ' . implode("\n", array_map(static fn(string $file): string => substr($file, 5), $files)));
            $lint = new Process([PHP_BINARY, '-l', $path], $root, timeout: 30.0);
            $lint->run();
            Assert::same($lint->getExitCode(), 0, $lint->getOutput() . $lint->getErrorOutput());
            $process = new Process([PHP_BINARY, $tmp . '/runtime.php', $root, $path], $root, timeout: 30.0);
            $process->run();
            Assert::same($process->getExitCode(), 0, $process->getOutput() . $process->getErrorOutput());
            file_put_contents($tmp . '/' . $side . '.json', $process->getOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            Assert::true($result['setup']);
            Assert::true($result['traitReady']);
        }
    }

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
