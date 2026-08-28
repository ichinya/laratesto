<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Configuration;

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Testo\Assert;
use Testo\Test;

/**
 * Exercises Rector's real container so this proves both set wiring and configuration
 * precedence. Rector de-duplicates a rule class registered by the set and by the user;
 * the later associative configuration must reach that single instance.
 */
final class ConfigurableSetTest
{
    #[Test]
    public function publicSetAllowsAProjectBaseOverrideWithoutRunningTheDefaultRuleToo(): void
    {
        $rootDir = dirname(__DIR__, 4);
        $tmpDir = sys_get_temp_dir() . '/laratesto-rector-config-' . getmypid();
        Assert::true(mkdir($tmpDir . '/corpus', 0777, true) || is_dir($tmpDir . '/corpus'));

        try {
            file_put_contents($tmpDir . '/ProjectTestCase.php', <<<'PHP'
<?php

namespace Illuminate\Foundation\Testing {
    abstract class TestCase {}
}

namespace Acme\Testing {
    abstract class ProjectTestCase extends \Illuminate\Foundation\Testing\TestCase {}
}
PHP);
            file_put_contents($tmpDir . '/corpus/CustomTest.php', <<<'PHP'
<?php

namespace Tests\Feature;

final class CustomTest extends \Acme\Testing\ProjectTestCase
{
    public function test_custom(): void {}
}
PHP);
            file_put_contents($tmpDir . '/corpus/FrameworkTest.php', <<<'PHP'
<?php

namespace Tests\Feature;

final class FrameworkTest extends \Illuminate\Foundation\Testing\TestCase
{
    public function test_framework(): void {}
}
PHP);

            $paths = var_export($tmpDir . '/corpus', true);
            $projectBase = var_export($tmpDir . '/ProjectTestCase.php', true);
            $set = var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
            file_put_contents($tmpDir . '/rector.php', <<<PHP
<?php

declare(strict_types=1);

require_once {$projectBase};

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([{$paths}])
    ->withSets([{$set}])
    ->withConfiguredRule(LaravelBaseClassRector::class, [
        LaravelBaseClassRector::BASE_CLASSES => ['Acme\\Testing\\ProjectTestCase'],
    ]);
PHP);

            $this->runRector($rootDir, $tmpDir);

            $custom = (string) file_get_contents($tmpDir . '/corpus/CustomTest.php');
            $framework = (string) file_get_contents($tmpDir . '/corpus/FrameworkTest.php');

            Assert::string($custom)->contains('extends \Laratesto\Testing\LaravelTestCase');
            Assert::string($framework)->contains('extends \Illuminate\Foundation\Testing\TestCase');
            Assert::string($framework)->notContains('Laratesto\Testing\LaravelTestCase');
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    #[Test]
    public function targetModeTraitRemovesOnlyTheDirectFrameworkParent(): void
    {
        $rootDir = dirname(__DIR__, 4);
        $tmpDir = sys_get_temp_dir() . '/laratesto-rector-trait-mode-' . getmypid();
        Assert::true(mkdir($tmpDir . '/corpus', 0777, true) || is_dir($tmpDir . '/corpus'));

        try {
            file_put_contents($tmpDir . '/corpus/TraitModeTest.php', <<<'PHP'
<?php

namespace Tests\Feature;

final class TraitModeTest extends \Illuminate\Foundation\Testing\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_trait_mode(): void {}
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
            $source = (string) file_get_contents($tmpDir . '/corpus/TraitModeTest.php');

            Assert::string($source)->contains('use \Laratesto\Testing\InteractsWithLaravel;');
            Assert::string($source)->notContains('extends \Illuminate\Foundation\Testing\TestCase');
            Assert::string($source)->notContains('Laratesto\Testing\LaravelTestCase');
            Assert::same(1, substr_count($source, 'InteractsWithLaravel'));
            Assert::string($source)->contains('function setUpLaravel(): void');
            Assert::string($source)->notContains('parent::setUp()');
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
