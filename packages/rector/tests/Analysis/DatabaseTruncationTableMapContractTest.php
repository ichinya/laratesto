<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Analysis;

use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

/**
 * Table-selection contract grounding the DatabaseTruncation keyed-map
 * residual: the source trait's default selector is `[null]`, so the trait
 * looks `tablesToTruncate`/`exceptTables` maps up with the NULL key — the
 * lookup misses every literal connection name and falls back to the whole
 * map, whose array values match no table name, so the trait truncates nothing
 * (tablesToTruncate) or excludes nothing beyond the migrations table
 * (exceptTables). The target interceptor resolves the null selector to the
 * connection NAME first (`DatabaseRuntime::connectionNames()`), and
 * `selectionFor()` then returns exactly the listed tables for that name — so
 * `things` is truncated on the target while the source retains it. The lift
 * would silently flip that outcome, which the `DatabaseConfigurationAnalyzer`
 * refuses; pinned here against the REAL framework trait methods and the REAL
 * interceptor internals, without touching any database (the connection is an
 * inert interface double, no query runs).
 *
 * If either side ever changes its selection semantics, this contract fails
 * first and the analyzer's residual rationale must be re-evaluated.
 */
final class DatabaseTruncationTableMapContractTest
{
    #[Test]
    public function theSourceTraitSelectsWithTheNullKeyAndRetainsTheMappedTables(): void
    {
        $output = $this->runProbe(<<<'PHP'
<?php

declare(strict_types=1);
require $argv[1];

$connection = new class implements \Illuminate\Database\ConnectionInterface {
    public function table($table, $as = null) {}
    public function raw($value) {}
    public function selectOne($query, $bindings = [], $useReadPdo = true) {}
    public function scalar($query, $bindings = [], $useReadPdo = true) {}
    public function select($query, $bindings = [], $useReadPdo = true) {}
    public function cursor($query, $bindings = [], $useReadPdo = true) {}
    public function insert($query, $bindings = []) {}
    public function update($query, $bindings = []) {}
    public function delete($query, $bindings = []) {}
    public function statement($query, $bindings = []) {}
    public function affectingStatement($query, $bindings = []) {}
    public function unprepared($query) {}
    public function prepareBindings(array $bindings) { return $bindings; }
    public function transaction(\Closure $callback, $attempts = 1) {}
    public function beginTransaction() {}
    public function commit() {}
    public function rollBack() {}
    public function transactionLevel() { return 0; }
    public function pretend(\Closure $callback) {}
    public function getDatabaseName() { return 'memory'; }
    public function getTablePrefix() { return ''; }
};

// Minimal container double: the trait reads $this->app['config'] for the
// migrations table name.
$application = new class implements \ArrayAccess {
    public ?\Illuminate\Config\Repository $config = null;

    public function offsetExists(mixed $offset): bool { return $this->{$offset} !== null; }

    public function offsetGet(mixed $offset): mixed { return $this->{$offset}; }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}
};
$application->config = new \Illuminate\Config\Repository(['database' => ['migrations' => 'migrations']]);

$source = new class($application) {
    use \Illuminate\Foundation\Testing\DatabaseTruncation {
        connectionsToTruncate as public selectors;
        tablesToTruncate as public selectTables;
        exceptTables as public selectExcept;
        tableExistsIn as public matches;
    }

    public function __construct(public $app) {}

    protected array $tablesToTruncate = ['sqlite' => ['things']];
    protected array $exceptTables = ['sqlite' => ['audit_entries']];
};

$things = ['name' => 'things', 'schema' => null, 'schema_qualified_name' => 'things'];
$audit = ['name' => 'audit_entries', 'schema' => null, 'schema_qualified_name' => 'audit_entries'];

echo \json_encode([
    'selector' => $source->selectors(),
    'tables_selection' => $source->selectTables($connection, null),
    'tables_truncates_things' => $source->matches($things, $source->selectTables($connection, null)),
    'except_selection' => $source->selectExcept($connection, null),
    'except_excludes_audit' => $source->matches($audit, $source->selectExcept($connection, null)),
]), "\n";
PHP, [dirname(__DIR__, 4) . '/vendor/autoload.php']);

        Assert::same(
            '{"selector":[null],"tables_selection":{"sqlite":["things"]},"tables_truncates_things":false,'
            . '"except_selection":{"sqlite":["audit_entries"],"0":"migrations"},"except_excludes_audit":false}',
            $output,
        );
    }

    #[Test]
    public function theTargetResolvesTheSelectorNameAndTruncatesTheMappedTables(): void
    {
        $output = $this->runProbe($this->interceptorProbe());

        Assert::same(
            '{"selector":["sqlite"],"tables_selection":["things"],"tables_truncates_things":true,'
            . '"except_selection":["audit_entries"]}',
            $output,
        );
    }

    /**
     * Builds the target probe: boots a minimal application whose default
     * connection is `sqlite`, injects it into the factory the pipeline uses,
     * and runs the interceptor's selection internals for the same keyed maps
     * the source probe carries. Prints the resolved selector, both selections
     * and the things-table verdict.
     */
    private function interceptorProbe(): string
    {
        $autoload = var_export(dirname(__DIR__, 4) . '/vendor/autoload.php', true);
        $fixtureApp = var_export(dirname(__DIR__, 4) . '/tests/Fixture/laravel', true);

        return <<<PHP
<?php

declare(strict_types=1);

require {$autoload};

\$application = new \Illuminate\Foundation\Application();
\$application->instance('config', new \Illuminate\Config\Repository(['database' => ['default' => 'sqlite']]));

\$factory = new \Laratesto\Runtime\LaravelApplicationFactory(new \Laratesto\Config\LaravelConfig(basePath: {$fixtureApp}));
(new \ReflectionProperty(\$factory, 'application'))->setValue(\$factory, \$application);

\$interceptor = new \Laratesto\Pipeline\DatabaseTruncationInterceptor(
    new \Laratesto\Attribute\DatabaseTruncation(tables: ['sqlite' => ['things']], exceptTables: ['sqlite' => ['audit_entries']]),
    \$factory,
);

\$selectionFor = new \ReflectionMethod(\$interceptor, 'selectionFor');
\$existsIn = new \ReflectionMethod(\$interceptor, 'tableExistsIn');
\$things = ['name' => 'things', 'schema' => null, 'schema_qualified_name' => 'things'];

echo \json_encode([
    'selector' => \Laratesto\Pipeline\Internal\DatabaseRuntime::connectionNames(\$application, null),
    'tables_selection' => \$selectionFor->invoke(\$interceptor, ['sqlite' => ['things']], 'sqlite'),
    'tables_truncates_things' => \$existsIn->invoke(\$interceptor, \$things, \$selectionFor->invoke(\$interceptor, ['sqlite' => ['things']], 'sqlite')),
    'except_selection' => \$selectionFor->invoke(\$interceptor, ['sqlite' => ['audit_entries']], 'sqlite'),
]), "\n";
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
        $tmpDir = sys_get_temp_dir() . '/laratesto-table-map-probe-' . getmypid();
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
