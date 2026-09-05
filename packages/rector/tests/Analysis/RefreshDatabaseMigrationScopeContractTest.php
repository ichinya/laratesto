<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Analysis;

use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

/**
 * Migration-scope contract grounding the RefreshDatabase `$connectionsToTransact`
 * residual: the source trait runs its single migrate:fresh against the DEFAULT
 * connection no matter what the selection contains (the selection only picks the
 * per-test transaction scope), while the target attribute's `connections` argument
 * repoints migrate:fresh at every selected connection. Lifting a named selection
 * therefore changes which schema migrate:fresh wipes — the runtime delta the
 * `DatabaseConfigurationAnalyzer` refuses to introduce, pinned here against the
 * REAL framework trait and the REAL interceptor, without touching any database
 * (the artisan kernel is a recording double, the interceptor is invoked through
 * reflection exactly as the Testo pipeline would).
 *
 * If either side ever changes its migration-scope semantics, this contract fails
 * first and the analyzer's residual rationale must be re-evaluated.
 */
final class RefreshDatabaseMigrationScopeContractTest
{
    #[Test]
    public function theSourceTraitAlwaysMigratesTheDefaultConnectionWhateverTheSelection(): void
    {
        $output = $this->runProbe(<<<'PHP'
<?php

declare(strict_types=1);
require $argv[1];

$named = new class {
    use \Illuminate\Foundation\Testing\RefreshDatabase {
        migrateDatabases as public;
    }

    protected $connectionsToTransact = ['secondary'];

    public array $calls = [];

    public function artisan($command, $parameters)
    {
        $this->calls[] = [$command, $parameters];
    }
};

$empty = new class {
    use \Illuminate\Foundation\Testing\RefreshDatabase {
        migrateDatabases as public;
    }

    protected $connectionsToTransact = [];

    public array $calls = [];

    public function artisan($command, $parameters)
    {
        $this->calls[] = [$command, $parameters];
    }
};

$named->migrateDatabases();
$empty->migrateDatabases();

// The migrate:fresh command itself never receives a --database parameter: the
// selection drives the transaction scope only, never the migration target.
echo \array_key_exists('--database', $named->calls[0][1]) ? 'repointed' : 'default-only', "\n";
echo \array_key_exists('--database', $empty->calls[0][1]) ? 'repointed' : 'default-only', "\n";
echo \count($empty->calls), "\n";
PHP, [dirname(__DIR__, 4) . '/vendor/autoload.php']);

        Assert::same("default-only\ndefault-only\n1", $output);
    }

    #[Test]
    public function theAttributeRepointsMigrateFreshAtTheSelectedConnections(): void
    {
        $output = $this->runProbe($this->interceptorProbe('connections: [\'secondary\']', "['secondary']"));

        Assert::same("secondary\n1", $output);
    }

    #[Test]
    public function theAttributeMigratesNothingForAnEmptySelection(): void
    {
        $output = $this->runProbe($this->interceptorProbe('connections: []', '[]'));

        // The interceptor iterates the selection: an empty list issues no
        // migrate:fresh at all, while the trait would still have migrated the
        // default connection (see theSourceTraitAlwaysMigratesTheDefaultConnectionWhateverTheSelection).
        Assert::same("none\n0", $output);
    }

    /**
     * Builds the interceptor probe for one attribute selection: boots a minimal
     * application with a recording console kernel, injects it into the factory
     * the pipeline uses, and runs the interceptor's migrate:fresh for the
     * resolved connections. Prints the first --database parameter and the
     * recorded call count.
     */
    private function interceptorProbe(string $attributeArguments, string $connectionsArgument): string
    {
        $autoload = var_export(dirname(__DIR__, 4) . '/vendor/autoload.php', true);
        $fixtureApp = var_export(dirname(__DIR__, 4) . '/tests/Fixture/laravel', true);

        return <<<PHP
<?php

declare(strict_types=1);

require {$autoload};

\$kernel = new class implements \Illuminate\Contracts\Console\Kernel {
    public array \$calls = [];

    public function bootstrap(): void {}

    public function handle(\$input, \$output = null) { return 0; }

    public function call(\$command, array \$parameters = [], \$outputBuffer = null)
    {
        \$this->calls[] = [\$command, \$parameters];

        return 0;
    }

    public function queue(\$command, array \$parameters = []) {}

    public function all() { return []; }

    public function output() { return ''; }

    public function terminate(\$input, \$status): void {}

    public function setArtisan(?\Illuminate\Console\Application \$artisan): void {}
};

\$application = new \Illuminate\Foundation\Application();
\$application->instance('config', new \Illuminate\Config\Repository(['database' => ['default' => 'sqlite']]));
\$application->instance(\Illuminate\Contracts\Console\Kernel::class, \$kernel);

\$factory = new \Laratesto\Runtime\LaravelApplicationFactory(new \Laratesto\Config\LaravelConfig(basePath: {$fixtureApp}));
(new \ReflectionProperty(\$factory, 'application'))->setValue(\$factory, \$application);

\$interceptor = new \Laratesto\Pipeline\RefreshDatabaseInterceptor(
    new \Laratesto\Attribute\RefreshDatabase({$attributeArguments}),
    \$factory,
);
(new \ReflectionMethod(\$interceptor, 'migrateFresh'))->invoke(\$interceptor, {$connectionsArgument});

\$databases = [];
foreach (\$kernel->calls as [\$command, \$parameters]) {
    \$databases[] = \$parameters['--database'] ?? null;
}

echo \$databases[0] ?? 'none', "\n";
echo \count(\$kernel->calls), "\n";
PHP;
    }

    private function runProbe(string $script, array $args = []): string
    {
        [$exitCode, $output] = $this->runScript($script, $args);

        Assert::same(0, $exitCode, "The probe must run cleanly.\nOUTPUT:\n{$output}");

        return rtrim($output);
    }

    /**
     * @return array{int, string} Process exit code and combined output.
     */
    private function runScript(string $script, array $args = []): array
    {
        $tmpDir = sys_get_temp_dir() . '/laratesto-scope-probe-' . getmypid();
        Assert::true(mkdir($tmpDir, 0777, true) || is_dir($tmpDir));

        try {
            $path = $tmpDir . '/probe.php';
            Assert::true(file_put_contents($path, $script) !== false);

            $process = new Process([PHP_BINARY, $path, ...$args], $tmpDir, timeout: 30.0);
            $process->run();

            $output = $process->getOutput() . "\n" . $process->getErrorOutput();

            return [$process->getExitCode() ?? 1, $output];
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    private static function recursiveRemove(string $dir): void
    {
        $items = is_dir($dir) ? scandir($dir) : false;

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            if ($item === '.' || $item === '..') {
                continue;
            }
            is_dir($path) ? self::recursiveRemove($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
