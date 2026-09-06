<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Tests\Support\DatabasePipeline;
use Testo\Assert;
use Testo\Test;

final class DatabaseInheritedSeedPipelineTest
{
    #[Test]
    public function inheritedAttributesKeepTheirPrecedenceAcrossFiles(): void
    {
        $snapshot = DatabasePipeline::run([
            'Base.php' => <<<'PHP'
                <?php
                namespace Tests\SeedHierarchy;
                use Illuminate\Foundation\Testing\Attributes\Seed;
                use Illuminate\Foundation\Testing\Attributes\Seeder;
                #[Seed]
                #[Seeder(\stdClass::class)]
                abstract class GrandBase extends \Illuminate\Foundation\Testing\TestCase {}
                #[Seeder(\ArrayIterator::class)]
                abstract class NearestBase extends GrandBase {}
                PHP,
            'Child.php' => <<<'PHP'
                <?php
                namespace Tests\SeedConsumer;
                use Illuminate\Foundation\Testing\RefreshDatabase;
                final class InheritedTest extends \Tests\SeedHierarchy\NearestBase
                {
                    use RefreshDatabase;
                    protected bool $seed = false;
                    protected string $seeder = \stdClass::class;
                    public function testWorks(): void { $this->assertTrue(true); }
                }
                PHP,
            'Own.php' => <<<'PHP'
                <?php
                namespace Tests\SeedConsumer;
                use Illuminate\Foundation\Testing\RefreshDatabase;
                #[\Illuminate\Foundation\Testing\Attributes\Seeder(\SplObjectStorage::class)]
                final class OwnTest extends \Tests\SeedHierarchy\NearestBase
                {
                    use RefreshDatabase;
                    public function testWorks(): void { $this->assertTrue(true); }
                }
                PHP,
            'Plain.php' => <<<'PHP'
                <?php
                namespace Tests\SeedConsumer;
                final class PlainTest extends \Illuminate\Foundation\Testing\TestCase
                {
                    use \Illuminate\Foundation\Testing\RefreshDatabase;
                    public function testWorks(): void { $this->assertTrue(true); }
                }
                PHP,
        ], ['Tests\SeedHierarchy\GrandBase', 'Tests\SeedHierarchy\NearestBase']);

        Assert::string($snapshot['Child.php'])->contains('RefreshDatabase(seed: true, seeder: \ArrayIterator::class)');
        Assert::string($snapshot['Child.php'])->notContains('protected bool $seed');
        Assert::string($snapshot['Child.php'])->notContains('protected string $seeder');
        Assert::string($snapshot['Own.php'])->contains('RefreshDatabase(seed: true, seeder: \SplObjectStorage::class)');
        Assert::string($snapshot['Plain.php'])->contains('#[\Laratesto\Attribute\RefreshDatabase]');
        foreach ($snapshot as $source) {
            Assert::string($source)->notContains('laratesto-residual');
        }
        // Ancestor metadata remains available to other descendants.
        Assert::string($snapshot['Base.php'])->contains('#[Seed]');
        Assert::string($snapshot['Base.php'])->contains('#[Seeder(\ArrayIterator::class)]');
    }

    #[Test]
    public function inheritedInvalidMetadataStaysVisibleInThePublicSet(): void
    {
        $files = [];
        $bases = [];
        foreach ([
            'DuplicateSeed' => '#[Seed, Seed]',
            'SeedArguments' => '#[Seed(true)]',
            'DuplicateSeeder' => '#[Seeder(\stdClass::class), Seeder(\ArrayIterator::class)]',
            'SeederValue' => '#[Seeder(42)]',
        ] as $name => $attributes) {
            $files[$name . 'Base.php'] = '<?php namespace Tests\\SeedNegative;'
                . ' use Illuminate\\Foundation\\Testing\\Attributes\\Seed;'
                . ' use Illuminate\\Foundation\\Testing\\Attributes\\Seeder;'
                . $attributes . ' abstract class ' . $name . 'Base extends \\Illuminate\\Foundation\\Testing\\TestCase {}';
            $files[$name . 'Test.php'] = '<?php namespace Tests\\SeedNegative; final class ' . $name . 'Test extends ' . $name . 'Base'
                . ' { use \\Illuminate\\Foundation\\Testing\\RefreshDatabase; public function testWorks(): void {} }';
            $bases[] = 'Tests\\SeedNegative\\' . $name . 'Base';
        }
        $snapshot = DatabasePipeline::run($files, $bases);
        foreach (array_keys($files) as $name) {
            if (str_ends_with($name, 'Test.php')) {
                Assert::string($snapshot[$name])->contains('code=DATABASE_UNSUPPORTED_CONFIGURATION');
                Assert::string($snapshot[$name])->contains('use \\Illuminate\\Foundation\\Testing\\RefreshDatabase;');
                Assert::string($snapshot[$name])->notContains('#[\\Laratesto\\Attribute\\RefreshDatabase');
            }
        }
    }
}
