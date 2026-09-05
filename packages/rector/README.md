# laratesto-rector

[Rector](https://getrector.com/) rules for migrating existing Laravel PHPUnit test
suites to [Laratesto](https://github.com/ichinya/laratesto) (Testo-based).

## Install

```bash
composer require --dev ichinya/laratesto-rector --with-all-dependencies
```

## Usage

Create `rector.php` in the project root:

```php
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->import(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO);
};
```

Review first and apply second. Keep rollback scoped to the migration paths:

```bash
vendor/bin/rector process tests --dry-run   # native diff includes residual markers
git diff -- tests                           # review only the migration scope
vendor/bin/rector process tests             # apply in-place
vendor/bin/rector process tests             # second run must be a no-op
# restore only after review, if required: git restore -- tests
```

Raw Rector does not create a residual report file. It exposes
`laratesto-residual(...)` comments in its native diff (and in source after apply). Use
`php artisan laratesto:migrate-rector` when a table and JSON report are required.

## Artisan workflow

The same set, wrapped with reporting and safety guards:

```bash
git switch -c migrate-to-laratesto            # clean branch first
php artisan laratesto:migrate-rector          # dry-run: sources untouched
# → residuals table in the console + laratesto-residuals.json in the project root
php artisan laratesto:migrate-rector --apply  # in-place rewrite
git diff -- tests                             # review the migration scope only
# fix manual residuals, delete their markers, then:
testo run
```

Options: repeatable `--path` (default `tests`), `--base-class` (adds a project base to
the defaults; accepted spellings `Tests/ApiTestCase`, `'\Tests\ApiTestCase'` and
`' Tests\ApiTestCase '` are canonicalized to `Tests\ApiTestCase`, duplicates are
removed, empty or malformed names are rejected), `--target-mode=base_class|trait`,
`--report=<file>`, `--allow-dirty`.

**Exit codes**: `0` — success, no manual residuals; `1` — config/process/JSON/report
or safety failure; `2` — success, manual residuals present. Rector's native dry-run
`2` ("changes found") is normalized as success.

**Report side effect**: a dry-run never touches processed sources, but always
atomically replaces `laratesto-residuals.json` (schema 1: `schema_version`, `mode`,
`paths`, `residuals[]` with `code`, `severity`, `rule`, `file`, `line`, `reason`;
stable sort by file/line/code/rule, no timestamp — identical runs produce identical
bytes). Add it to `.gitignore` if you do not want it committed.

**Safety**: `--apply` refuses modified (non-untracked) processed paths and non-Git
environments; `--allow-dirty` overrides this with an explicit warning and no promised
rollback. Rollback is always scoped to the processed paths
(`git restore --source=HEAD -- tests`); do not use broad destructive commands
(`git checkout -- .`, reset, stash drops) for this.

## What is converted

| Area | Automatic (AUTO) | Left for manual follow-up (RESIDUAL) |
| --- | --- | --- |
| Test class | `Tests\TestCase` / `Illuminate\Foundation\Testing\TestCase` → `LaravelTestCase`; `setUp/tearDown` → `setUpLaravel/tearDownLaravel` | unresolved/custom parents, parameterized or static lifecycle (`CLASS_UNSAFE_HIERARCHY`, `LIFECYCLE_UNSUPPORTED`) |
| Database | trait → attribute (`RefreshDatabase`, `DatabaseTransactions`, `DatabaseMigrations`, `DatabaseTruncation`) with literal options | hooks, dynamic options, multiple traits/adaptations (`DATABASE_UNSUPPORTED_CONFIGURATION`); the lazy `LazilyRefreshDatabase` strategy is never converted — the trait use stays and needs manual migration |
| HTTP / responses | common request, header, session, cookie, database and response assertions keep working unchanged | unknown helpers/signatures, unsupported response API (`HTTP_UNSUPPORTED_SIGNATURE`, `RESPONSE_UNSUPPORTED_API`) |
| Fakes | — | `Mail/Queue/Bus/Event/Notification/Storage/Http::fake()` (`LARAVEL_FAKE_UNSUPPORTED`) |
| Outside a convertible class | — | Laravel constructs in classes whose base does not resolve (`LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY`) |
| Artisan chains | `assertExitCode`, `expectsOutput`, … | interactive `expectsQuestion`/choice/search forms (`ARTISAN_INTERACTION_UNSUPPORTED`) |

Anything not listed defaults to RESIDUAL — the marker
`/* laratesto-residual(code=…, rule=…, severity=…): reason */` stays next to the
untouched construct and is reported by the Artisan table/JSON. A resolved residual is
deleted together with its marker by hand.

Upstream `PHPUNIT_TO_TESTO` covers generic PHPUnit constructs as part of the same
set: assertions, data providers, group/coverage metadata and similar are converted
there and are **not** manual-only.

Project base classes and the target strategy can be overridden after importing the
set. Rector keeps one instance of the rule and merges the later associative
configuration:

```php
use Laratesto\Rector\Rules\LaravelBaseClassRector;

$rectorConfig->ruleWithConfiguration(LaravelBaseClassRector::class, [
    LaravelBaseClassRector::BASE_CLASSES => [Tests\TestCase::class],
    LaravelBaseClassRector::TARGET_MODE => LaravelBaseClassRector::TARGET_MODE_BASE_CLASS,
]);
```

## What the set contains

`LARAVEL_PHPUNIT_TO_LARATESTO` composes:

1. Upstream `PHPUNIT_TO_TESTO` from `testo/bridge-rector` for generic PHPUnit
   constructs.
2. Laravel-specific rules for test-class/lifecycle conversion, database strategies,
   HTTP/TestResponse compatibility classification, and residual markers.

Unsupported constructions stay semantically untouched and receive
`laratesto-residual` markers for manual follow-up.

## Compatibility baseline

This package requires PHP `>=8.2` — the same minimum as Testo and the runtime. It
intentionally pins Rector to `2.6.2` and supports `testo/bridge-rector` `0.2.4`.
Bridge `0.2.4` still uses Rector's former `Container::tagged()` integration, which
is absent from newer Rector versions tested for this release. Remove or widen the
pin only after the public-set, configured-rule, and double-run corpus tests pass
against the candidate Rector version.

The Laratesto runtime these rules migrate to supports PHP `>=8.2`,
Laravel `^12.0 || ^13.0` and Testo `^0.10.42`. This package declares no Composer
dependency on the runtime and only suggests it: the rules rewrite database traits
into the multi-connection attributes (`connections`, `tables`, `exceptTables` on
`#[DatabaseTruncation]`; `connections` on `#[RefreshDatabase]`), which the released
runtime `0.6.9` does not ship. Install `ichinya/laratesto` `dev-main` — or the first
release that includes those attributes — before running migrated tests; against
`0.6.9` the generated code cannot run.

## Autoloading

Class-hierarchy detection needs the project's test classes to be autoloadable. A
standard Laravel test namespace already provides this. If a project test base lives
outside that map, add the corresponding bootstrap/autoload configuration in
`rector.php`.
