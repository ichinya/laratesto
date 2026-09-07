<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Tests\Support\DatabasePipeline;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

final class DatabaseDescendantOptionsPipelineTest
{
    #[Test]
    public function inheritedOptionsPreserveTheSharedSourceAndReportEachDescendant(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/Fixture/DatabaseDescendantOptions/refresh.php.inc');
        $output = DatabasePipeline::run(['Tests.php' => $source], [
            'Tests\DescendantRefresh\Base', 'Tests\DescendantRefresh\Intermediate',
        ])['Tests.php'];
        Assert::string($output)->contains('extends \\Illuminate\\Foundation\\Testing\\TestCase');
        Assert::string($output)->contains('use \\Illuminate\\Foundation\\Testing\\RefreshDatabase;');
        Assert::string($output)->notContains('#[\\Laratesto\\Attribute\\RefreshDatabase');
        foreach (['$seed', '$connectionsToTransact', '$dropViews', '$dropTypes', 'shouldSeed()'] as $option) {
            Assert::string($output)->contains($option);
        }
        Assert::string($output)->contains('database option $connectionsToTransact changes the inherited attribute configuration');
        Assert::string($output)->contains('database override shouldSeed() requires manual migration');
        Assert::string($output)->contains('migrate the shared database strategy manually');
        Assert::true(substr_count($output, 'code=DATABASE_UNSUPPORTED_CONFIGURATION') >= 6);
    }

    #[Test]
    public function unchangedInheritedOptionsKeepOneAttributeAndRetainedReaders(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/Fixture/DatabaseDescendantOptions/positive.php.inc');
        $output = DatabasePipeline::run(['Tests.php' => $source], [
            'Tests\DescendantPositive\Base', 'Tests\DescendantPositive\Intermediate',
            'Tests\DescendantPositive\TransactionsBase',
            'Tests\DescendantPositive\TruncationBase',
        ])['Tests.php'];
        Assert::string($output)->notContains('laratesto-residual');
        Assert::same(1, substr_count($output, '#[\\Laratesto\\Attribute\\RefreshDatabase'));
        Assert::same(1, substr_count($output, '#[\\Laratesto\\Attribute\\DatabaseTransactions'));
        Assert::same(1, substr_count($output, '#[\\Laratesto\\Attribute\\DatabaseTruncation'));
        Assert::string($output)->contains('RefreshDatabase(seed: true)');
        Assert::string($output)->contains("DatabaseTransactions(connections: ['audit'])");
        Assert::string($output)->contains('protected bool $seed = TRUE;');
        Assert::string($output)->contains('protected bool $seed = true;');
        Assert::string($output)->contains('\\Testo\\Assert::true($this->seed)');
        Assert::same(
            $this->runtimeOptions($source, 'Tests\\DescendantPositive\\SameOptionsTest', false),
            $this->runtimeOptions($output, 'Tests\\DescendantPositive\\SameOptionsTest', true),
        );
    }

    #[Test]
    public function crossFileOptionsAndReadersPreserveBasesRegardlessOfFileOrder(): void
    {
        foreach ([false, true] as $childFirst) {
            $baseFile = $childFirst ? 'ZBase.php' : 'ABase.php';
            $childFile = $childFirst ? 'AChild.php' : 'ZChild.php';
            $output = DatabasePipeline::run([
                $baseFile => <<<'PHP'
                    <?php
                    namespace Tests\CrossDescendant;
                    abstract class TransactionsBase extends \Illuminate\Foundation\Testing\TestCase
                    { use \Illuminate\Foundation\Testing\DatabaseTransactions; }
                    abstract class MigrationsBase extends \Illuminate\Foundation\Testing\TestCase
                    { use \Illuminate\Foundation\Testing\DatabaseMigrations; protected bool $seed = true; }
                    abstract class TruncationBase extends \Illuminate\Foundation\Testing\TestCase
                    { use \Illuminate\Foundation\Testing\DatabaseTruncation; }
                    PHP,
                $childFile => <<<'PHP'
                    <?php
                    namespace Tests\CrossDescendant;
                    final class TransactionsTest extends TransactionsBase
                    { protected array $connectionsToTransact = ['audit']; public function testValue(): void { $this->assertTrue(true); } }
                    final class MigrationsTest extends MigrationsBase
                    { public function testValue(): void { $this->assertTrue($this->seed); } }
                    final class TruncationTest extends TruncationBase
                    { protected array $tablesToTruncate = ['users']; public function testValue(): void { $this->assertTrue(true); } }
                    PHP,
            ], ['Tests\CrossDescendant\TransactionsBase', 'Tests\CrossDescendant\MigrationsBase', 'Tests\CrossDescendant\TruncationBase']);
            Assert::same(3, substr_count($output[$baseFile], 'extends \\Illuminate\\Foundation\\Testing\\TestCase'));
            Assert::string($output[$baseFile])->notContains('#[\\Laratesto\\Attribute\\');
            Assert::string($output[$baseFile])->contains('protected bool $seed = true;');
            Assert::string($output[$childFile])->contains('database option $seed is read below the ancestor that lifts it');
            Assert::string($output[$childFile])->contains('database option $tablesToTruncate changes the inherited attribute configuration');
            Assert::same(3, substr_count($output[$childFile], 'code=DATABASE_UNSUPPORTED_CONFIGURATION'));
        }
    }

    #[Test]
    public function inheritedMetadataKeepsVersionGuardAndNearestSeederPrecedence(): void
    {
        $output = DatabasePipeline::run([
            'Tests.php' => <<<'PHP'
                <?php
                namespace Tests\DescendantMetadata;
                #[\Illuminate\Foundation\Testing\Attributes\Seed]
                #[\Illuminate\Foundation\Testing\Attributes\Seeder(\stdClass::class)]
                abstract class Base extends \Illuminate\Foundation\Testing\TestCase
                { use \Illuminate\Foundation\Testing\RefreshDatabase; }
                final class SameTest extends Base
                { protected bool $seed = false; protected string $seeder = \ArrayIterator::class; public function testValue(): void { $this->assertTrue(true); } }
                PHP,
        ], ['Tests\DescendantMetadata\Base'])['Tests.php'];
        if (! class_exists(\Illuminate\Foundation\Testing\Attributes\Seed::class)) {
            Assert::string($output)->contains('require proven Laravel 13 source semantics');
            Assert::string($output)->contains('use \\Illuminate\\Foundation\\Testing\\RefreshDatabase;');
            Assert::string($output)->notContains('#[\\Laratesto\\Attribute\\RefreshDatabase');
        } else {
            Assert::string($output)->notContains('laratesto-residual');
            Assert::same(1, substr_count($output, '#[\\Laratesto\\Attribute\\RefreshDatabase'));
            Assert::string($output)->contains('RefreshDatabase(seed: true, seeder: \\stdClass::class)');
        }
        Assert::string($output)->contains('protected bool $seed = false;');
        Assert::string($output)->contains('protected string $seeder = \\ArrayIterator::class;');
    }

    #[Test]
    public function inheritedHooksAndDynamicOptionsCannotLookLikeUnchangedDefaults(): void
    {
        $output = DatabasePipeline::run(['Tests.php' => <<<'PHP'
            <?php
            namespace Tests\DescendantDynamic;
            abstract class Base extends \Illuminate\Foundation\Testing\TestCase
            { use \Illuminate\Foundation\Testing\RefreshDatabase; }
            trait SeedChoice { protected function chooseSeed(): bool { return true; } }
            final class AliasTest extends Base
            {
                use SeedChoice { chooseSeed as shouldSeed; }
                public function testValue(): void { $this->assertTrue(true); }
            }
            final class WriteTest extends Base
            {
                protected bool $seed = false;
                public function configureSeed(): void { $this->seed = true; }
                public function testValue(): void { $this->assertTrue(true); }
            }
            final class ArrayWriteTest extends Base
            {
                protected array $connectionsToTransact = [null];
                public function configureConnections(): void { $this->connectionsToTransact[] = 'audit'; }
                public function testValue(): void { $this->assertTrue(true); }
            }
            final class NullConnectionsTest extends Base
            {
                protected $connectionsToTransact = null;
                public function testValue(): void { $this->assertTrue(true); }
            }
            PHP,
        ], ['Tests\DescendantDynamic\Base'])['Tests.php'];
        Assert::string($output)->contains('database hook alias shouldSeed() requires manual migration');
        Assert::string($output)->contains('database option $seed is written by descendant code');
        Assert::string($output)->contains('database option $connectionsToTransact is written by descendant code');
        Assert::string($output)->contains('database option $connectionsToTransact has an unsupported inherited literal shape');
        Assert::string($output)->notContains('#[\\Laratesto\\Attribute\\RefreshDatabase');
    }

    #[Test]
    public function readersBelowDuplicateTraitsAndDefaultSelectionsKeepTheirProperties(): void
    {
        $output = DatabasePipeline::run(['Tests.php' => <<<'PHP'
            <?php
            namespace Tests\DescendantLifted;
            abstract class Base extends \Illuminate\Foundation\Testing\TestCase
            { use \Illuminate\Foundation\Testing\RefreshDatabase; protected bool $seed = true; }
            abstract class DuplicateBase extends Base
            { use \Illuminate\Foundation\Testing\RefreshDatabase; protected bool $seed = true; }
            final class ReaderTest extends DuplicateBase
            { public function testValue(): void { $this->assertTrue($this->seed); } }
            abstract class DefaultBase extends \Illuminate\Foundation\Testing\TestCase
            { use \Illuminate\Foundation\Testing\RefreshDatabase; protected array $connectionsToTransact = [null]; }
            final class DefaultReaderTest extends DefaultBase
            { public function testValue(): void { $this->assertSame([null], $this->connectionsToTransact); } }
            PHP,
        ], ['Tests\DescendantLifted\Base', 'Tests\DescendantLifted\DuplicateBase', 'Tests\DescendantLifted\DefaultBase'])['Tests.php'];
        Assert::string($output)->contains('database option $seed is read below the ancestor that lifts it');
        Assert::string($output)->contains('database option $connectionsToTransact is read below the ancestor that lifts it');
        Assert::same(3, substr_count($output, 'use \\Illuminate\\Foundation\\Testing\\RefreshDatabase;'));
        Assert::string($output)->notContains('#[\\Laratesto\\Attribute\\RefreshDatabase');
    }

    #[Test]
    public function positionalCliSourcesPreserveChangedOptionsAndConvertUnchangedOptions(): void
    {
        foreach ([false, true] as $explicitFiles) {
            foreach ([false, true] as $sameFile) {
                foreach ([false, true] as $seed) {
                    $base = <<<'PHP'
                        <?php
                        namespace Tests;
                        abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
                        { use \Illuminate\Foundation\Testing\RefreshDatabase; }
                        PHP;
                    $child = 'class ChildTest extends TestCase { protected bool $seed = '
                        . ($seed ? 'true' : 'false')
                        . '; public function testValue(): void { $this->assertTrue(true); } }';
                    $files = $sameFile
                        ? ['Tests.php' => $base . "\n" . $child]
                        : ['ABase.php' => $base, 'ZChild.php' => '<?php namespace Tests; ' . $child];
                    $output = $this->cliPipeline($files, $explicitFiles);
                    $combined = implode("\n", $output);
                    if ($seed) {
                        Assert::string($combined)->contains('extends \\Illuminate\\Foundation\\Testing\\TestCase');
                        Assert::string($combined)->contains('use \\Illuminate\\Foundation\\Testing\\RefreshDatabase;');
                        Assert::string($combined)->notContains('#[\\Laratesto\\Attribute\\RefreshDatabase');
                        Assert::same(2, substr_count($combined, 'code=DATABASE_UNSUPPORTED_CONFIGURATION'));
                        Assert::string($combined)->contains('migrate the shared database strategy manually');
                    } else {
                        Assert::string($combined)->notContains('code=DATABASE_UNSUPPORTED_CONFIGURATION');
                        Assert::string($combined)->notContains('use \\Illuminate\\Foundation\\Testing\\RefreshDatabase;');
                        Assert::same(1, substr_count($combined, '#[\\Laratesto\\Attribute\\RefreshDatabase'));
                    }
                    // Execute Laravel's inherited option readers, or the inherited target
                    // metadata when conversion is supported, against the same class.
                    $runtimeSource = static fn (array $sources): string => '<?php ' . implode("\n",
                        array_map(static fn (string $source): string => substr($source, strlen('<?php')), $sources));
                    Assert::same(
                        $this->runtimeOptions($runtimeSource($files), 'Tests\\ChildTest', false, seedOnly: true),
                        $this->runtimeOptions($runtimeSource($output), 'Tests\\ChildTest', ! $seed, seedOnly: true),
                    );
                }
            }
        }
    }

    #[Test]
    public function caseInsensitiveAncestorsKeepChangedOptionsAndAllowEqualOptions(): void
    {
        foreach ([false, true] as $sameFile) {
            foreach ([false, true] as $seed) {
                $base = <<<'PHP'
                    <?php
                    namespace Tests;
                    abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
                    { use \Illuminate\Foundation\Testing\RefreshDatabase; }
                    abstract class Middle extends tESTcASE {}
                    PHP;
                $child = 'class ChildTest extends mIDDLE { protected bool $seed = '
                    . ($seed ? 'true' : 'false')
                    . '; public function testValue(): void { $this->assertTrue(true); } }';
                $files = $sameFile ? ['Tests.php' => $base . "\n" . $child]
                    : ['ABase.php' => $base, 'ZChild.php' => '<?php namespace Tests; ' . $child];
                $output = $this->cliPipeline($files, explicitFiles: ! $sameFile);
                $combined = implode("\n", $output);
                if ($seed) {
                    Assert::string($combined)->contains('extends \\Illuminate\\Foundation\\Testing\\TestCase');
                    Assert::string($combined)->contains('use \\Illuminate\\Foundation\\Testing\\RefreshDatabase;');
                    Assert::string($combined)->contains('migrate the shared database strategy manually');
                    Assert::string($combined)->notContains('#[\\Laratesto\\Attribute\\RefreshDatabase');
                    Assert::string($combined)->contains('code=DATABASE_UNSUPPORTED_CONFIGURATION');
                } else {
                    Assert::string($combined)->notContains('code=DATABASE_UNSUPPORTED_CONFIGURATION');
                    Assert::same(1, substr_count($combined, '#[\\Laratesto\\Attribute\\RefreshDatabase'));
                }
                $runtimeSource = static fn (array $sources): string => '<?php ' . implode("\n",
                    array_map(static fn (string $source): string => substr($source, strlen('<?php')), $sources));
                Assert::same(
                    $this->runtimeOptions($runtimeSource($files), 'Tests\\ChildTest', false, seedOnly: true),
                    $this->runtimeOptions($runtimeSource($output), 'Tests\\ChildTest', ! $seed, seedOnly: true),
                );
            }
        }
    }

    #[Test]
    public function sourceSnapshotsFollowLocatorResetsWithinOneContainer(): void
    {
        $tmp = sys_get_temp_dir() . '/laratesto-db-snapshot-' . bin2hex(random_bytes(8));
        Assert::true(mkdir($tmp));
        try {
            $probe = <<<'PHP'
                require $argv[1] . '/vendor/autoload.php';
                require $argv[1] . '/vendor/rector/rector/vendor/autoload.php';
                $container = (new \Rector\DependencyInjection\LazyContainerFactory())->create();
                $container->boot();
                $analyzer = $container->make(\Laratesto\Rector\Analysis\DatabaseConfigurationAnalyzer::class);
                $provider = $container->make(\Rector\NodeTypeResolver\Reflection\BetterReflection\SourceLocatorProvider\DynamicSourceLocatorProvider::class);
                $base = '<?php namespace Tests; abstract class TestCase extends \\Illuminate\\Foundation\\Testing\\TestCase { use \\Illuminate\\Foundation\\Testing\\RefreshDatabase; }';
                $unsafe = '<?php namespace Tests; class ChildTest extends TestCase { protected bool $seed = true; }';
                $baseFile = $argv[2] . '/Base.php';
                $childFile = $argv[2] . '/Child.php';
                file_put_contents($baseFile, $base);
                file_put_contents($childFile, $unsafe);
                // Configured paths remain constant while actual project inputs change.
                \Rector\Configuration\Parameter\SimpleParameterProvider::setParameter(\Rector\Configuration\Option::PATHS, [$argv[2]]);
                $parser = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
                $nodes = (new \PhpParser\NodeTraverser(new \PhpParser\NodeVisitor\NameResolver()))->traverse($parser->parse($base));
                $classes = (new \PhpParser\NodeFinder())->findInstanceOf($nodes, \PhpParser\Node\Stmt\Class_::class);
                $supported = static fn (): bool => $analyzer->analyze($classes[0], $classes)->supported();
                $provider->addFiles([$baseFile, $childFile]);
                $results = [$supported()];
                file_put_contents($childFile, str_replace('true', 'false', $unsafe));
                $results[] = $supported(); // Original declarations stay stable during a run.
                $provider->reset();
                $provider->addFiles([$baseFile, $childFile]);
                $results[] = $supported(); // Same filenames, fresh declarations after reset.
                file_put_contents($childFile, $unsafe);
                $provider->reset();
                $provider->addFiles([$baseFile]);
                $results[] = $supported(); // The excluded child must not enter this source set.
                $provider->reset();
                $provider->addFiles([$baseFile, $childFile]);
                $results[] = $supported();
                echo json_encode($results, JSON_THROW_ON_ERROR);
                PHP;
            $root = dirname(__DIR__, 4);
            $process = new Process([PHP_BINARY, '-r', $probe, $root, $tmp], $root, timeout: 60.0);
            $process->run();
            Assert::same(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            Assert::same([false, false, true, true, false], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            $this->removeTemporaryCorpus($tmp);
        }
    }

    /** @param array<string, string> $files @return array<string, string> */
    private function cliPipeline(array $files, bool $explicitFiles): array
    {
        $root = dirname(__DIR__, 4);
        $tmp = sys_get_temp_dir() . '/laratesto-db-cli-' . bin2hex(random_bytes(8));
        Assert::true(mkdir($tmp . '/corpus', 0777, true));
        $run = static function (array $command) use ($root): void {
            $process = new Process($command, $root, timeout: 300.0);
            $process->run();
            Assert::same(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        };
        try {
            foreach ($files as $file => $source) {
                Assert::true(file_put_contents($tmp . '/corpus/' . $file, $source) !== false);
                $run([PHP_BINARY, '-l', $tmp . '/corpus/' . $file]);
            }
            $set = var_export(\Laratesto\Rector\Set\LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
            $cache = var_export($tmp . '/cache', true);
            // Deliberately no withPaths: ConfigurationFactory reads positional CLI
            // inputs directly, without copying them into PATHS or SOURCE parameters.
            file_put_contents($tmp . '/rector.php', <<<PHP
                <?php
                return \Rector\Config\RectorConfig::configure()
                    ->withSets([{$set}])->withCache(cacheDirectory: {$cache})->withoutParallel();
                PHP);
            $inputs = $explicitFiles
                ? array_map(static fn (string $file): string => $tmp . '/corpus/' . $file, array_keys($files))
                : [$tmp . '/corpus'];
            $command = [PHP_BINARY, $root . '/vendor/rector/rector/bin/rector', 'process', ...$inputs,
                '--config', $tmp . '/rector.php', '--no-progress-bar', '--no-ansi', '--clear-cache'];
            $run($command);
            $snapshot = [];
            foreach ($files as $file => $_source) {
                $run([PHP_BINARY, '-l', $tmp . '/corpus/' . $file]);
                $snapshot[$file] = file_get_contents($tmp . '/corpus/' . $file);
            }
            Assert::true($files !== $snapshot, 'The public set must transform the corpus.');
            $run($command); // Clears the private cache again before the second pass.
            foreach ($snapshot as $file => $bytes) {
                Assert::same($bytes, file_get_contents($tmp . '/corpus/' . $file), $file . ' changed on the fresh-cache second pass');
            }
            return $snapshot;
        } finally {
            $this->removeTemporaryCorpus($tmp);
        }
    }

    private function removeTemporaryCorpus(string $directory): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }

    /** Read the actual Laravel methods and the actual Testo hierarchy metadata. */
    private function runtimeOptions(string $source, string $class, bool $target, bool $seedOnly = false): array
    {
        $probe = <<<'PHP'
            namespace PHPUnit\Framework { abstract class TestCase {} }
            namespace {
                require $argv[1];
                eval(substr(base64_decode($argv[2]), strlen('<?php')));
                if ($argv[4] === 'target') {
                    $attributes = \Testo\Common\Reflection::fetchClassAttributes(
                        $argv[3], attributeClass: \Laratesto\Attribute\RefreshDatabase::class,
                    );
                    if (count($attributes) !== 1) { throw new \RuntimeException('Expected exactly one inherited strategy'); }
                    $attribute = $attributes[0]->newInstance();
                    $options = [$attribute->seed, $attribute->seeder ?? false, $attribute->dropViews,
                        $attribute->dropTypes, $attribute->connections ?? [null]];
                } else {
                    $instance = (new \ReflectionClass($argv[3]))->newInstanceWithoutConstructor();
                    $options = [];
                    $methods = $argv[5] === 'seed' ? ['shouldSeed']
                        : ['shouldSeed', 'seeder', 'shouldDropViews', 'shouldDropTypes', 'connectionsToTransact'];
                    foreach ($methods as $method) {
                        $options[] = (new \ReflectionMethod($instance, $method))->invoke($instance);
                    }
                }
                echo json_encode($argv[5] === 'seed' ? [$options[0]] : $options, JSON_THROW_ON_ERROR);
            }
            PHP;
        $root = dirname(__DIR__, 4);
        $process = new Process([PHP_BINARY, '-r', $probe, $root . '/vendor/autoload.php',
            base64_encode($source), $class, $target ? 'target' : 'source', $seedOnly ? 'seed' : 'all'], $root, timeout: 60.0);
        $process->run();
        Assert::same(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
