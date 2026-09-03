<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Testo\Assert;
use Testo\Test;

/**
 * Full-set regression for the `target_mode=trait` pipeline: LaravelBaseClassRector
 * removes the extends and adds the InteractsWithLaravel trait BEFORE the later rules
 * run, so the shared ConfiguredHierarchy recognition must accept the trait form too.
 * Runs the real `rector` binary with the whole public set twice and proves the
 * converted class gains its database attribute, response rewrite and test marking
 * with no outside-hierarchy residual, an unrelated helper that merely carries
 * Laravel constructs stays fail-closed, and the second run is byte-identical.
 */
final class TraitModePipelineTest
{
    #[Test]
    public function traitModeRecognizesTheTargetTraitFormAndSparesUnrelatedHelpers(): void
    {
        $rootDir = dirname(__DIR__, 4);
        $tmpDir = sys_get_temp_dir() . '/laratesto-rector-trait-pipeline-' . getmypid();
        Assert::true(mkdir($tmpDir . '/corpus', 0777, true) || is_dir($tmpDir . '/corpus'));

        try {
            file_put_contents($tmpDir . '/corpus/UsersListTest.php', <<<'PHP'
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

final class UsersListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function visit(): TestResponse
    {
        return $this->postJson('/signup', ['email' => 'a@b.c'])
            ->assertStatus(201);
    }

    public function test_users_list_renders(): void {}
}
PHP);
            file_put_contents($tmpDir . '/corpus/MailerConcern.php', <<<'PHP'
<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

final class MailerConcern
{
    use RefreshDatabase;

    private ?Application $app = null;

    public function resolve(): ?Application
    {
        return $this->app;
    }

    public function probe(): TestResponse
    {
        return $this->postJson('/signup', ['email' => 'a@b.c']);
    }
}
PHP);

            $paths = var_export($tmpDir . '/corpus', true);
            $set = var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
            file_put_contents($tmpDir . '/rector.php', <<<PHP
<?php

declare(strict_types=1);

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([{$paths}])
    ->withSets([{$set}])
    ->withConfiguredRule(LaravelBaseClassRector::class, [
        LaravelBaseClassRector::TARGET_MODE => LaravelBaseClassRector::TARGET_MODE_TRAIT,
    ]);
PHP);

            $this->runRector($rootDir, $tmpDir);
            $snapshot = self::corpus($tmpDir . '/corpus');
            Assert::true($snapshot !== [], 'The corpus disappeared after the first run.');

            $this->runRector($rootDir, $tmpDir);
            Assert::same(self::corpus($tmpDir . '/corpus'), $snapshot, 'Second run modified an already-migrated file.');

            $target = $snapshot['UsersListTest.php'];
            $helper = $snapshot['MailerConcern.php'];

            // The target class converts as a whole in trait mode: trait form instead
            // of extends, database trait to attribute, response type rewritten,
            // lifecycle renamed, test marked — and no residual marker at all, in
            // particular none of the outside-hierarchy kind.
            Assert::string($target)->contains('use \Laratesto\Testing\InteractsWithLaravel;');
            Assert::string($target)->notContains('extends');
            Assert::string($target)->contains('#[\Laratesto\Attribute\RefreshDatabase]');
            Assert::string($target)->notContains('use Illuminate\Foundation\Testing\RefreshDatabase;');
            Assert::string($target)->contains('\Laratesto\Testing\LaravelResponse');
            Assert::string($target)->notContains('TestResponse');
            Assert::string($target)->contains('function setUpLaravel(): void');
            Assert::string($target)->notContains('parent::setUp()');
            Assert::string($target)->contains('#[\Testo\Test]');
            Assert::string($target)->notContains('laratesto-residual');

            // An unrelated helper that merely carries Laravel constructs is never
            // partially migrated: carrying Laravel source traits is not hierarchy
            // membership, so only the fail-closed residual diagnosis fires.
            Assert::string($helper)->notContains('InteractsWithLaravel');
            Assert::string($helper)->notContains('#[\Laratesto\Attribute\RefreshDatabase]');
            Assert::string($helper)->contains('use Illuminate\Foundation\Testing\RefreshDatabase;');
            Assert::string($helper)->contains('TestResponse');
            Assert::string($helper)->notContains('#[\Testo\Test]');
            Assert::string($helper)->contains('laratesto-residual(code=LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY');
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    private function runRector(string $rootDir, string $tmpDir): void
    {
        $rectorBin = $rootDir . '/vendor/rector/rector/bin/rector';
        Assert::true(is_file($rectorBin), 'rector binary not found at ' . $rectorBin);

        $process = proc_open(
            [PHP_BINARY, $rectorBin, 'process', '--config', $tmpDir . '/rector.php', '--no-progress-bar', '--no-ansi'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $rootDir,
        );
        Assert::true(is_resource($process), 'proc_open failed');
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        Assert::same(0, $exitCode, "rector failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
    }

    /**
     * @return array<string, string> Corpus file name => contents.
     */
    private static function corpus(string $dir): array
    {
        $contents = [];

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            $fileContents = file_get_contents($path);
            Assert::true(is_string($fileContents), 'Unable to read corpus file ' . $path);
            $contents[$entry] = $fileContents;
        }

        return $contents;
    }

    private static function recursiveRemove(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? self::recursiveRemove($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
