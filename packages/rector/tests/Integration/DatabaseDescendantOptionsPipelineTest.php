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

    /** Read the actual Laravel methods and the actual Testo hierarchy metadata. */
    private function runtimeOptions(string $source, string $class, bool $target): array
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
                    foreach (['shouldSeed', 'seeder', 'shouldDropViews', 'shouldDropTypes', 'connectionsToTransact'] as $method) {
                        $options[] = (new \ReflectionMethod($instance, $method))->invoke($instance);
                    }
                }
                echo json_encode($options, JSON_THROW_ON_ERROR);
            }
            PHP;
        $root = dirname(__DIR__, 4);
        $process = new Process([PHP_BINARY, '-r', $probe, $root . '/vendor/autoload.php',
            base64_encode($source), $class, $target ? 'target' : 'source'], $root, timeout: 60.0);
        $process->run();
        Assert::same(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
