# laratesto-rector

[Rector](https://getrector.com/) rules for migrating existing Laravel PHPUnit test
suites to [Laratesto](https://github.com/ichinya/laratesto) (Testo-based).

## Install

This package lives at `packages/rector` inside `ichinya/laratesto`; it is not a
separate GitHub repository. Until a matching package is published on Packagist,
clone the monorepo and add both path repositories to the consuming application's
`composer.json` (replace `/absolute/path/laratesto` with your checkout, using
forward slashes on Windows):

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/absolute/path/laratesto",
      "options": {
        "symlink": false,
        "versions": {"ichinya/laratesto": "dev-issue10"}
      }
    },
    {
      "type": "path",
      "url": "/absolute/path/laratesto/packages/rector",
      "options": {
        "symlink": false,
        "versions": {"ichinya/laratesto-rector": "dev-main"}
      }
    }
  ]
}
```

Merge these entries with existing repositories. Use a checkout containing the
`Laratesto\Testing\PhpUnitCompatibility` helper; generated code from this set is
not compatible with runtime 0.7.0 or earlier. The development version labels
above are local Composer labels, not remote branch names.

```bash
composer require --dev ichinya/laratesto:dev-issue10 ichinya/laratesto-rector:dev-main --with-all-dependencies
php artisan package:discover
php artisan laratesto:migrate-rector --help
php artisan laratesto:migrate-rector --path=tests
```

The subpackage installs `rector/rector` and `testo/bridge-rector` as dependencies.
Normal Laravel package discovery registers the command. If discovery is disabled,
register `Laratesto\Rector\LaratestoRectorServiceProvider` in the application.
With `symlink: false`, rerun `composer reinstall ichinya/laratesto ichinya/laratesto-rector`
after changing the source checkout to refresh the mirrored packages.

## Usage

Create `rector.php` in the project root:

```php
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->import(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO);
};
```

Commit the migration sources first, review the dry-run, then apply. Raw Rector
does not run the Artisan Git guards or create a backup. Keep rollback scoped to
the migration paths:

```bash
vendor/bin/rector process tests --dry-run   # native diff includes residual markers
git diff -- tests                           # review only the migration scope
vendor/bin/rector process tests             # apply in-place
vendor/bin/rector process tests             # second run must be a no-op
# restore committed sources after review, if required: git restore --source=HEAD -- tests
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

**Report side effect**: a successful dry-run never touches processed sources, but
atomically replaces `laratesto-residuals.json` (schema 1: `schema_version`, `mode`,
`paths`, `residuals[]` with `code`, `severity`, `rule`, `file`, `line`, `reason`;
stable sort by file/line/code/rule, no timestamp — identical runs produce identical
bytes). Only whole canonical PHP marker comments count, so quoted marker examples
in strings or comment prose do not produce residuals. Configuration, Git, Rector,
JSON, source-read and report-write failures return `1` and preserve any previous
report. A run with manual residuals still writes its report and returns `2`.
Add the report to `.gitignore` if you do not want it committed.

**Safety**: `--apply` refuses non-Git environments, staged or unstaged changes in
processed paths, and untracked or ignored PHP files inside those paths. Commit
the source files first. Untracked/ignored non-PHP files and untracked/ignored
files outside the scope do not block it. `--allow-dirty` bypasses the Git checks
with an explicit warning; back up the originals yourself before using it.
No automatic rollback runs, and a failed apply can leave partial source edits
even when the previous report is preserved. After review, restore only the
processed paths (`git restore --source=HEAD -- tests`, substituting your paths).
This restores committed sources; it cannot recover local edits or files absent
from HEAD. Avoid broad destructive commands for rollback.

## What is converted

| Area | Automatic (AUTO) | Left for manual follow-up (RESIDUAL) |
| --- | --- | --- |
| Test class | `Tests\TestCase` / `Illuminate\Foundation\Testing\TestCase` → `LaravelTestCase`; `setUp/tearDown` → `setUpLaravel/tearDownLaravel` | unresolved/custom or skipped parents, parameterized/static or trait-provided lifecycle/bootstrap (`CLASS_UNSAFE_HIERARCHY`, `LIFECYCLE_UNSUPPORTED`) |
| Database | trait → attribute (`RefreshDatabase`, `DatabaseTransactions`, `DatabaseMigrations`, `DatabaseTruncation`) with literal options | overrides of hooks live for the selected strategy, dynamic options, strategies hidden inside project traits, multiple traits/adaptations (`DATABASE_UNSUPPORTED_CONFIGURATION`); the lazy `LazilyRefreshDatabase` strategy is never converted — the trait use stays and needs manual migration |
| HTTP / responses | common request, header, session, cookie, database and response assertions keep working unchanged | unknown helpers/signatures, unsupported response API (`HTTP_UNSUPPORTED_SIGNATURE`, `RESPONSE_UNSUPPORTED_API`) |
| Fakes | Laravel facade `assert*` calls keep their framework behavior and record assertions in Testo | retain `phpunit/phpunit` in `require-dev` for these framework assertions |
| Outside a convertible class | — | Laravel constructs in classes whose base does not resolve (`LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY`) |
| Artisan chains | immediate chains, terminal literal expectations, and straight-line local variables migrated to deferred `pendingArtisan()` | interactive forms, retained commands in loops or catch/finally, and escaping variables (`ARTISAN_INTERACTION_UNSUPPORTED`) |
| PHPUnit helpers | `createStub()` uses the installed PHPUnit generator; `expectOutputString()` checks exact setup/test output before teardown | keep PHPUnit installed for stubs and deferred console assertions; other unsupported helpers remain explicit residuals |

Public project `createApplication()` methods with no required arguments are
preserved and invoked once before database setup and user lifecycle hooks.
Delegation to `parent::createApplication()`, other custom bootstrap contracts,
and unresolved or excluded bases still require a manual decision. `WithFaker`
is supported, including initialization before the user setup hook.

The HTTP analysis accepts inferred types and named arguments whose parameter
names match the runtime. It keeps nested classes and callback parameters in
their own scope. Inertia `assertInertia()` / `inertiaPage()` and additional
framework response assertions preserve the package implementation; keep
`phpunit/phpunit` as an assertion-library dependency. Callbacks retain their
original Inertia type and import alias.

Other shared traits are not rewritten: PHPUnit test discovery in traits, source
testing APIs in trait helpers, and Laravel `setUp<Trait>`/`tearDown<Trait>` hooks
require manual migration. A processed descendant with an unsupported trait
dependency also keeps its shared source base unchanged. A database-rule `withSkip` exclusion for a strategy owner
likewise preserves the hierarchy, rather than leaving an inactive Laravel
database trait on a converted class.

Descendants that inherit a database strategy without repeating its trait are
checked against the ancestor attribute's fixed options. Changed options, live
hooks and dynamic configuration preserve the shared source strategy with a
database residual; proven unchanged inherited defaults remain supported. Review
the complete hierarchy within the migration inputs.

Recognized unsupported constructs receive a RESIDUAL — the marker
`/* laratesto-residual(code=…, rule=…, severity=…): reason */` stays next to the
untouched construct and is reported by the Artisan table/JSON. A resolved residual is
deleted together with its marker by hand. The analysis is bounded to supported
syntax patterns; an empty report does not prove complete behavioral equivalence.
Review the full diff and run the migrated tests.
Residual-marked classes may retain their original PHPUnit parent or database
strategy trait. Finish their manual migration before treating them as executable
Laratesto tests.

Response construction converts only when `new TestResponse(...)` has exactly one
proven non-null Symfony `Response` value (positional or named `response:`),
including inferred variables and typed parameters. Static factories such as
`TestResponse::fromBaseResponse()`, unknown/nullable values and extra arguments
remain on the source type with `RESPONSE_UNSUPPORTED_API`; they can also block a
sibling's file-wide response type swap. Direct `$this->app` writes and references
remain on the source class with `HTTP_UNSUPPORTED_SIGNATURE`, including calls to
resolved by-reference parameters. Unresolved call signatures also receive a
residual because by-value passing cannot be proven. Ordinary app reads, member
accesses and calls with proven by-value parameters remain convertible.

Methods with Laravel-like names on a provably unrelated native DTO type remain
unmarked. Unknown receivers, broad `object` types and unions that may contain a
Laravel response still receive conservative residuals.

Laravel's retained Pending Artisan commands execute on release, even after an
`assertExitCode()` call; Laratesto executes them eagerly. Supported terminal cases
(including branches and independent closures) must keep the command local,
without escaping through references or static/global storage. Retention across
continuing control flow requires manual migration.

Upstream `PHPUNIT_TO_TESTO` covers generic PHPUnit constructs as part of the same
set: assertions, data providers, group/coverage metadata and similar are converted
there and are **not** manual-only. Local compatibility rules preserve PHPUnit exception substring matching (including literal regex characters and repeated message replacement), caller-dependent string coercion, emptiness, array checks and strict membership. Negative assertion controls remain failing after conversion.

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

Laravel constructs classified as unsupported are preserved with
`laratesto-residual` markers for manual follow-up.

## Compatibility baseline

This package requires PHP `>=8.2` — the same minimum as Testo and the runtime. It
intentionally pins Rector to `2.6.2` and supports `testo/bridge-rector` `0.2.4`.
Bridge `0.2.4` still uses Rector's former `Container::tagged()` integration, which
is absent from newer Rector versions tested for this release. Remove or widen the
pin only after the public-set, configured-rule, and double-run corpus tests pass
against the candidate Rector version.

Processing-scope checks also read the pinned Rector run configuration through
`PrivatesAccessor`, because this version has no public getter for the effective
input paths. When upgrading Rector, verify positional CLI overrides, parallel
workers, and skipped or autoload-only bases as well as configured-path runs.

The Laratesto runtime these rules migrate to supports PHP `>=8.2`,
Laravel `^12.0 || ^13.0` and Testo `^0.10.42`. This package declares no Composer
dependency on the runtime and only suggests it. Laravel 12 supports PHP 8.2;
Laravel 13 requires PHP 8.3 or newer. CI tests locked Laravel 12 on PHP 8.2/8.3/8.4
and separately resolved Laravel 13 on PHP 8.3/8.4, on Linux and Windows, asserting
the actual installed framework major before running the suite.

The rules rewrite database traits into the multi-connection attributes
(`connections`, `tables`, `exceptTables` on
`#[DatabaseTruncation]`; `connections` on `#[RefreshDatabase]`), which the released
runtime `0.6.9` does not ship. The matching checkout also supplies the PHPUnit compatibility helpers introduced after runtime `0.7.0`. Install the matching `ichinya/laratesto` development checkout — or the first
release that includes those attributes — before running migrated tests; against
`0.6.9` the generated code cannot run.

Own and inherited Laravel `Seed`/`Seeder` attributes lift only with a positively
identified installed Laravel 13 framework: inherited `Seed` enables seeding before
property fallback, and the nearest `Seeder` wins unless the class supplies its
own. Literal seeder class names are rebuilt for the destination file; duplicate
declarations and unsupported argument shapes receive a database residual.
Laravel 12 ignores these attributes, so on Laravel 12 or an unknown framework
version the relevant metadata stays in source with
`DATABASE_UNSUPPORTED_CONFIGURATION` for manual migration.

Live constructor-promoted database options on the class, project ancestors or used
project traits remain residual-marked. A child literal redeclaration does not
make an inherited promoted option safe to lift: the inherited constructor can
still overwrite it. Restructure that initialization manually before replacing
the source configuration with attributes.

Reads of database options through method-local `$this` aliases also block the
lift, including chained/reference assignments and possible aliases in closures,
conditionals or coalescing expressions. The check covers the class, project
ancestors and composed traits; a later assignment does not erase a possible
alias. Unrelated DTO reads and ancestor readers of their own unshadowed private
slot do not independently block the lift.

Note that a `$connectionsToTransact` selection of a single `null` entry — the
provably default-only shape — converts to the bare `#[RefreshDatabase]`
attribute with no `connections` argument at all, which resolves to the same
default-only migration and transaction scope. Named, multiple or empty
selections keep the trait and receive a `DATABASE_UNSUPPORTED_CONFIGURATION`
residual instead, because the trait always migrates the default connection
while the attribute would repoint `migrate:fresh` at the selected connections.
The same applies to DatabaseTruncation's `$connectionsToTruncate`: the source
trait scopes only the table truncation with the selection, while its first
`migrate:fresh` and every later `db:seed` run on the default connection — the
attribute would migrate and seed the selected connections instead. Only the
single-`null` selection lifts, into the bare `#[DatabaseTruncation]` form;
named, multiple, duplicate or empty selections keep the trait and receive the
residual. An explicit `connections:` argument written by hand keeps working:
it is an author's deliberate target choice, not a lifted source property.

Table/exclusion selections keyed by connection name fail closed as well: the
trait looks the map up with the null default selector, misses every literal
name and falls back to the whole map that matches no table, while the
attribute resolves the selector to the connection name first — the two sides
would keep vs truncate opposite tables. Flat table lists and the empty list
select the same tables on both sides and keep converting.

## Autoloading

Class-hierarchy detection needs the project's test classes to be autoloadable. A
standard Laravel test namespace already provides this. If a project test base lives
outside that map, add the corresponding bootstrap/autoload configuration in
`rector.php`.
