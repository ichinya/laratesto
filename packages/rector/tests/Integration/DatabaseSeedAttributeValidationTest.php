<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Tests\Support\DatabasePipeline;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

final class DatabaseSeedAttributeValidationTest
{
    #[Test]
    public function malformedOwnAndInheritedArgumentsFailClosedInThePublicSet(): void
    {
        $files = [];
        $bases = [];
        foreach ([
            'SeedArguments' => '#[Seed(enabled: true)]',
            'DuplicateSeed' => '#[Seed, Seed]',
            'DuplicateSeeder' => '#[Seeder(\stdClass::class), Seeder(\ArrayIterator::class)]',
            'SeederMissing' => '#[Seeder]',
            'SeederExtra' => '#[Seeder(\stdClass::class, \ArrayIterator::class)]',
            'SeederWrongName' => '#[Seeder(seeder: \stdClass::class)]',
            'SeederNonClass' => '#[Seeder(42)]',
        ] as $name => $attribute) {
            $prefix = '<?php namespace Tests\\SeedBranches; use Illuminate\\Foundation\\Testing\\Attributes\\Seed; use Illuminate\\Foundation\\Testing\\Attributes\\Seeder; ';
            $files[$name . '.php'] = $prefix . $attribute . ' final class ' . $name . 'Test extends \\Illuminate\\Foundation\\Testing\\TestCase'
                . ' { use \\Illuminate\\Foundation\\Testing\\RefreshDatabase; public function testWorks(): void {} }';
            $files[$name . 'Base.php'] = $prefix . $attribute . ' abstract class ' . $name . 'Base extends \\Illuminate\\Foundation\\Testing\\TestCase {}';
            $files[$name . 'Child.php'] = $prefix . ' final class ' . $name . 'Child extends ' . $name . 'Base'
                . ' { use \\Illuminate\\Foundation\\Testing\\RefreshDatabase; public function testWorks(): void {} }';
            $bases[] = 'Tests\\SeedBranches\\' . $name . 'Base';
        }
        $files['Positive.php'] = <<<'PHP'
            <?php
            namespace Tests\SeedBranches;
            final class PositiveTest extends \Illuminate\Foundation\Testing\TestCase
            {
                use \Illuminate\Foundation\Testing\RefreshDatabase;
                protected bool $seed = true;
                protected string $seeder = \stdClass::class;
                public function testWorks(): void {}
            }
            PHP;
        $snapshot = DatabasePipeline::run($files, $bases);
        foreach ($snapshot as $name => $source) {
            if ($name === 'Positive.php') {
                Assert::string($source)->contains('RefreshDatabase(seed: true, seeder: \\stdClass::class)');
                Assert::string($source)->notContains('laratesto-residual');
            } elseif (! str_ends_with($name, 'Base.php')) {
                Assert::string($source)->contains('code=DATABASE_UNSUPPORTED_CONFIGURATION');
                Assert::string($source)->contains('use \\Illuminate\\Foundation\\Testing\\RefreshDatabase;');
                Assert::string($source)->notContains('#[\\Laratesto\\Attribute\\RefreshDatabase');
            }
        }
    }

    #[Test]
    public function unpackIsRejectedByPhpAndByTheDefensiveAstGuard(): void
    {
        // PHP-Parser accepts this AST, but native PHP forbids unpack in attributes.
        // Keep that distinction explicit: it is not a valid public-pipeline corpus.
        $root = dirname(__DIR__, 4);
        $tmp = sys_get_temp_dir() . '/laratesto-seed-unpack-' . bin2hex(random_bytes(8));
        Assert::true(mkdir($tmp));
        try {
            $source = '<?php #[\\Illuminate\\Foundation\\Testing\\Attributes\\Seeder(...\\stdClass::class)]'
                . ' class UnpackTest extends \\Illuminate\\Foundation\\Testing\\TestCase { use \\Illuminate\\Foundation\\Testing\\RefreshDatabase; }';
            file_put_contents($tmp . '/input.php', $source);
            $lint = new Process([PHP_BINARY, '-l', $tmp . '/input.php'], $root);
            $lint->run();
            Assert::string($lint->getOutput() . $lint->getErrorOutput())->contains('Cannot use unpacking in attribute argument list');

            file_put_contents($tmp . '/probe.php', <<<'PHP'
                <?php
                require $argv[1] . '/vendor/autoload.php';
                require $argv[1] . '/vendor/rector/rector/vendor/autoload.php';
                $container = (new \Rector\DependencyInjection\LazyContainerFactory())->create();
                $container->boot();
                $analyzer = $container->make(\Laratesto\Rector\Analysis\DatabaseConfigurationAnalyzer::class);
                $parser = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
                $sources = [file_get_contents($argv[2]),
                    '<?php #[\Illuminate\Foundation\Testing\Attributes\Seeder(...\stdClass::class)] class Base extends \Illuminate\Foundation\Testing\TestCase {} class Child extends Base { use \Illuminate\Foundation\Testing\RefreshDatabase; }'];
                foreach ($sources as $source) {
                    $nodes = $parser->parse($source);
                    $traverser = new \PhpParser\NodeTraverser(new \PhpParser\NodeVisitor\NameResolver());
                    $nodes = $traverser->traverse($nodes);
                    $classes = (new \PhpParser\NodeFinder())->findInstanceOf($nodes, \PhpParser\Node\Stmt\Class_::class);
                    $result = $analyzer->analyze($classes[array_key_last($classes)], $classes);
                    echo $result->unsupportedReason, "\n";
                }
                PHP);
            $process = new Process([PHP_BINARY, $tmp . '/probe.php', $root, $tmp . '/input.php'], $root, timeout: 60.0);
            $process->run();
            Assert::same(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            Assert::string($process->getOutput())->contains('Laravel Seeder attribute must contain one literal class');
            Assert::string($process->getOutput())->contains('Laravel Seeder attribute on ancestor Base must contain one literal class');
        } finally {
            foreach (glob($tmp . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            rmdir($tmp);
        }
    }
}
