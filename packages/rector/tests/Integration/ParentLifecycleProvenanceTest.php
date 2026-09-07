<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Analysis\HttpCompatibilityAnalyzer;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Rector\DependencyInjection\LazyContainerFactory;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

final class ParentLifecycleProvenanceTest
{
    #[Test]
    public function generatedCallsUseOriginalNodesWithoutTrustingArbitraryTargetNames(): void
    {
        require_once dirname(__DIR__, 4) . '/vendor/rector/rector/vendor/autoload.php';
        $container = (new LazyContainerFactory())->create();
        $container->boot();
        $analyzer = $container->make(HttpCompatibilityAnalyzer::class);
        foreach ([
            ['setUp', 'setUp', false, true, true],
            ['tearDown', 'tearDown', false, true, true],
            ['setUpLaravel', 'setUpLaravel', false, false, false],
            ['setUp', 'tearDown', false, true, false],
            ['setUp', 'setUp', true, true, false],
            ['execute', 'setUp', false, true, false],
        ] as [$methodName, $callName, $nested, $rename, $supported]) {
            $call = 'parent::' . $callName . '();';
            $body = $nested ? 'if (true) {' . $call . '}' : $call;
            $source = '<?php namespace LifecycleProof; abstract class Base extends \\Illuminate\\Foundation\\Testing\\TestCase {'
                . 'protected function setUp(): void {} protected function tearDown(): void {} }'
                . 'class Child extends Base { protected function ' . $methodName . '(): void {' . $body . '} }';
            $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
            $nodes = (new NodeTraverser(new NameResolver()))->traverse($nodes);
            $nodes = (new NodeTraverser(new CloningVisitor()))->traverse($nodes);
            $classes = (new NodeFinder())->findInstanceOf($nodes, Node\Stmt\Class_::class);
            $child = $classes[1];
            $method = $child->getMethods()[0];
            if ($rename) {
                if (in_array($methodName, ['setUp', 'tearDown'], true)) {
                    $method->name = new Node\Identifier($methodName . 'Laravel');
                }
                $parentCall = (new NodeFinder())->findFirstInstanceOf($method, Node\Expr\StaticCall::class);
                $parentCall->name = new Node\Identifier($callName . 'Laravel');
            }
            // The parent intentionally retains its original methods, as a worker's
            // reflection cache can while another worker converts the parent file.
            $analysis = $analyzer->analyze($child, $classes);
            Assert::same($analysis->safe(), $supported, $methodName . '/' . $callName . '/' . ($nested ? 'nested' : 'direct'));
        }
    }

    #[Test]
    public function parallelHierarchyHasNoFalseLifecycleResidualAndIsStable(): void
    {
        $root = dirname(__DIR__, 4);
        $temporary = sys_get_temp_dir() . '/laratesto-parent-provenance-' . bin2hex(random_bytes(8));
        Assert::true(mkdir($temporary));
        try {
            foreach (range(1, 3) as $attempt) {
                $directory = $temporary . '/' . $attempt;
                Assert::true(mkdir($directory . '/corpus', 0777, true));
                $files = [
                    'Base.php' => '<?php namespace Tests; abstract class TestCase extends \\Illuminate\\Foundation\\Testing\\TestCase { protected function setUp(): void { parent::setUp(); } protected function tearDown(): void { parent::tearDown(); } }',
                    'Child.php' => '<?php namespace Tests; final class ChildTest extends TestCase { protected function setUp(): void { parent::setUp(); } protected function tearDown(): void { parent::tearDown(); } public function testValue(): void { $this->assertTrue(true); } }',
                ];
                foreach ($files as $file => $source) {
                    file_put_contents($directory . '/corpus/' . $file, $source);
                    self::run([PHP_BINARY, '-l', $directory . '/corpus/' . $file], $root);
                }
                $set = var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
                $cache = var_export($directory . '/cache', true);
                file_put_contents($directory . '/rector.php', '<?php return \\Rector\\Config\\RectorConfig::configure()'
                    . '->withSets([' . $set . '])->withCache(cacheDirectory:' . $cache . ')'
                    . '->withParallel(timeoutSeconds:120,maxNumberOfProcess:2,jobSize:1);');
                $command = [PHP_BINARY, $root . '/vendor/rector/rector/bin/rector', 'process', $directory . '/corpus',
                    '--config', $directory . '/rector.php', '--no-progress-bar', '--no-ansi', '--clear-cache'];
                self::run($command, $root);
                $first = [];
                foreach ($files as $file => $_source) {
                    $first[$file] = file_get_contents($directory . '/corpus/' . $file);
                    self::run([PHP_BINARY, '-l', $directory . '/corpus/' . $file], $root);
                    Assert::string($first[$file])->notContains('laratesto-residual');
                    Assert::string($first[$file])->contains('function setUpLaravel');
                    Assert::string($first[$file])->contains('function tearDownLaravel');
                }
                Assert::string($first['Child.php'])->contains('#[\\Testo\\Test]');
                self::run($command, $root);
                foreach ($first as $file => $bytes) {
                    Assert::same($bytes, file_get_contents($directory . '/corpus/' . $file));
                }
            }
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temporary, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($temporary);
        }
    }

    /** @param list<string> $command */
    private static function run(array $command, string $root): void
    {
        $process = new Process($command, $root, timeout: 180);
        $process->run();
        Assert::same(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }
}
