<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Analysis;

use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

/**
 * Migration-scope contract grounding the DatabaseTruncation
 * `$connectionsToTruncate` residual: the source trait scopes ONLY the table
 * truncation with the selection — its first migrate:fresh (through
 * `migrateFreshUsing()`, which passes no `--database`) and its later db:seed
 * calls always run against the DEFAULT connection — while the target
 * attribute's `connections` argument repoints BOTH the first migrate:fresh and
 * the later seeding at every selected connection
 * (`DatabaseTruncationInterceptor::migrateFresh()`/`seed()` issue
 * `--database=$name` per entry). Lifting a named selection therefore moves
 * migration and seeding to a different schema — the runtime delta the
 * `DatabaseConfigurationAnalyzer` refuses to introduce, pinned here against the
 * REAL framework trait and the REAL interceptor, without touching any database
 * (the artisan kernel is a recording double, the interceptor is invoked through
 * reflection exactly as the Testo pipeline would).
 *
 * If either side ever changes its migration-scope semantics, this contract fails
 * first and the analyzer's residual rationale must be re-evaluated.
 */
final class DatabaseTruncationMigrationScopeContractTest
{
    #[Test]
    public function theSourceTraitMigratesAndSeedsTheDefaultConnectionWhateverTheSelection(): void
    {
        $output = $this->runProbe(<<<'PHP'
<?php

declare(strict_types=1);
require $argv[1];

$probe = static function (array $connections, \Illuminate\Contracts\Foundation\Application $application) {
    return new class($connections, $application) {
        use \Illuminate\Foundation\Testing\DatabaseTruncation {
            truncateDatabaseTables as public go;
        }

        public function __construct(private readonly array $truncated, public $app)
        {
            $this->connectionsToTruncate = $truncated;
        }

        protected array $connectionsToTruncate = [];
        protected bool $seed = true;

        public array $calls = [];

        public function artisan($command, $parameters = [])
        {
            $this->calls[] = [$command, $parameters];
        }

        // Recording-only harness: suppress the table IO while executing the
        // actual outer lifecycle, including the kernel reset after the first
        // migrate:fresh.
        protected function truncateTablesForAllConnections(): void {}
    };
};

// The REAL trait's truncateDatabaseTables() calls
// $this->app[Kernel::class]->setArtisan(null) after the first migrate:fresh,
// so the harness needs a minimal application whose kernel binding is a
// no-op double.
$kernel = new class implements \Illuminate\Contracts\Console\Kernel {
    public function bootstrap(): void {}

    public function handle($input, $output = null) { return 0; }

    public function call($command, array $parameters = [], $outputBuffer = null) { return 0; }

    public function queue($command, array $parameters = []) {}

    public function all() { return []; }

    public function output() { return ''; }

    public function terminate($input, $status): void {}

    public function setArtisan(?\Illuminate\Console\Application $artisan): void {}
};

$application = new \Illuminate\Foundation\Application();
$application->instance('config', new \Illuminate\Config\Repository(['database' => ['default' => 'sqlite']]));
$application->instance(\Illuminate\Contracts\Console\Kernel::class, $kernel);

\Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;

$named = $probe(['secondary'], $application);
$named->go();
$named->go();

// The trait's migrate:fresh and db:seed never receive a --database parameter:
// the selection drives the truncation scope only, never the migration or
// seeding target.
echo $named->calls[0][0], ':', \array_key_exists('--database', $named->calls[0][1]) ? 'repointed' : 'default-only', "\n";
echo $named->calls[1][0], ':', \array_key_exists('--database', $named->calls[1][1]) ? 'repointed' : 'default-only', "\n";

\Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;

$empty = $probe([], $application);
$empty->go();
$empty->go();

echo $empty->calls[0][0], ':', \array_key_exists('--database', $empty->calls[0][1]) ? 'repointed' : 'default-only', "\n";
echo $empty->calls[1][0], ':', \array_key_exists('--database', $empty->calls[1][1]) ? 'repointed' : 'default-only', "\n";
PHP, [dirname(__DIR__, 4) . '/vendor/autoload.php']);

        Assert::same("migrate:fresh:default-only\ndb:seed:default-only\nmigrate:fresh:default-only\ndb:seed:default-only", $output);
    }

    #[Test]
    public function theAttributeRepointsMigrateFreshAtTheSelectedConnections(): void
    {
        $output = $this->runProbe($this->interceptorProbe('connections: [\'secondary\'], seed: true', "['secondary']", 'migrateFresh'));

        Assert::same("secondary\n1", $output);
    }

    #[Test]
    public function theAttributeRepointsSeedingAtTheSelectedConnections(): void
    {
        $output = $this->runProbe($this->interceptorProbe("connections: ['secondary'], seed: true", "['secondary']", 'seed'));

        Assert::same("secondary\n1", $output);
    }

    /**
     * Builds the interceptor probe for one attribute selection: boots a minimal
     * application with a recording console kernel, injects it into the factory
     * the pipeline uses, and runs the interceptor's migrate:fresh or seed for
     * the resolved connections. Prints the first --database parameter and the
     * recorded call count.
     */
    private function interceptorProbe(string $attributeArguments, string $connectionsArgument, string $method): string
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

\$interceptor = new \Laratesto\Pipeline\DatabaseTruncationInterceptor(
    new \Laratesto\Attribute\DatabaseTruncation({$attributeArguments}),
    \$factory,
);
(new \ReflectionMethod(\$interceptor, '{$method}'))->invoke(\$interceptor, {$connectionsArgument});

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
        $tmpDir = sys_get_temp_dir() . '/laratesto-truncation-scope-probe-' . getmypid();
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
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::recursiveRemove($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
