# Migrating a Laravel PHPUnit suite to Laratesto

A walkthrough of both entry points over one repository. Everything below is
copy-pasteable; the fixtures it describes are the ones the package itself is tested
with.

## 0. Preconditions

- A Git work tree with the migration sources committed and processed paths clean
  (`git status --untracked-files=all --ignored -- <paths>`): Artisan `--apply`
  refuses staged/unstaged changes there, plus untracked or ignored PHP files.
  Untracked/ignored non-PHP files and files outside the scope do not block it.
- `testo/testo`, `ichinya/laratesto` and `ichinya/laratesto-rector` installed. The
  `ichinya/laratesto` runtime must ship the multi-connection database attributes
  (`connections`/`tables`/`exceptTables` on `DatabaseTruncation` and
  `RefreshDatabase`) — use `dev-main` until that release: the generated
  attributes do not exist in the released runtime 0.6.9.
- The project's test classes autoloadable (a standard Laravel `Tests\` namespace is).
- PHP 8.2+ for Laravel 12, or PHP 8.3+ for Laravel 13.

## 1. Artisan (recommended)

```bash
git switch -c migrate-to-laratesto
php artisan laratesto:migrate-rector          # dry-run by default
```

After successful processing and scanning, the dry-run prints the residuals table
and atomically replaces `laratesto-residuals.json` in the project root; processed
sources keep their hashes. Exit `2` here means
"manual residuals exist", not a failure.

Review, then apply:

```bash
php artisan laratesto:migrate-rector --apply
git diff -- tests            # review the migration scope only
```

Work through the residuals (section 3), delete each marker together with its fix, and
finish with a green run:

```bash
testo run
git commit
```

Useful flags: `--path` (repeatable, default `tests`), `--base-class` (add a project
base; `Tests/ApiTestCase`, a leading separator and surrounding whitespace are
canonicalized to `Tests\ApiTestCase`, empty or malformed names are rejected),
`--target-mode=base_class|trait`, `--report=<file>`, `--allow-dirty`
(bypasses the Git guards with a warning — make your own backup first).
On POSIX shells quote the backslash form — an unquoted `Tests\ApiTestCase` silently
loses the backslash.

## 2. Raw Rector

```php
// rector.php in the project root
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->import(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO);
};
```

```bash
vendor/bin/rector process tests --dry-run   # native diff shows residual markers
vendor/bin/rector process tests             # apply in-place
vendor/bin/rector process tests             # second run must be a no-op
```

The raw path keeps Rector's native output and exit codes and does **not** create the
JSON report or a table; find leftovers with
`grep -rn "laratesto-residual" tests` after applying.
Raw Rector also does not run the Artisan Git guards or back up the source files.

## 3. Typical residuals and what to do

Every residual is a comment next to the untouched construct:

```php
/* laratesto-residual(code=LARAVEL_FAKE_UNSUPPORTED, rule=…, severity=manual): Mail::fake() — … */
final class SignupTest extends LaravelTestCase { … }
```

Several rules can fail the same code on one class: their segments merge into the
single `laratesto-residual(code=…)` comment, each keeping its own `rule=…` and
reason, sorted by rule.

| Code | Typical trigger | Manual fix |
| --- | --- | --- |
| `LARAVEL_FAKE_UNSUPPORTED` | `Mail::fake()`, `Queue::fake()`, `Http::fake()` … | keep the fake for now and run the test under PHPUnit semantics, or replace with Testo-native doubles |
| `RESPONSE_UNSUPPORTED_API` | `assertJsonFragment()`, `TestResponse::fromBaseResponse()` or another static factory, construction from an unknown/nullable value or with extra arguments, a sibling blocking the file-wide type swap | use a supported assertion or migrate the response manually; construction converts only with one proven non-null Symfony `Response` value, positional or named `response:` |
| `DATABASE_UNSUPPORTED_CONFIGURATION` | a custom override of a hook the used strategy actually runs (`beforeRefreshingDatabase()` with `RefreshDatabase`/`DatabaseMigrations`, `beforeTruncatingDatabase()` with `DatabaseTruncation`, `connectionsToTransact()` with `RefreshDatabase`/`DatabaseTransactions`, …), dynamic `$connectionsToTruncate`, a `tablesToTruncate`/`exceptTables` map keyed by connection name on `DatabaseTruncation` (the trait looks it up with the null default selector and falls back to the whole unmatched map, while the attribute truncates/excludes the listed tables on the resolved connection), an option property such as `$seed` or `$connectionsToTransact` declared on a resolved project ancestor (unless the class itself redeclares the same option and no project reader above it observes the declaration — a reader in an ancestor method, an ancestor trait or one of the class's own project traits fails the lift closed, except a reader whose own scope declares the option privately, which the redeclare never shadows) or supplied by a used project trait (directly or through an ancestor; still live there through `property_exists()`, but out of reach of the class-level attribute), the same database trait re-declared on a descendant of a class that already uses it with a different configuration, a `$connectionsToTransact`/`$connectionsToTruncate` selection on `RefreshDatabase`/`DatabaseTruncation` that names, multiplies, duplicates or empties connections (both traits always keep their first `migrate:fresh` on the default connection, `DatabaseTruncation` also its later `db:seed`, while the attribute would repoint `migrate:fresh` and `db:seed` at the selected ones), a used trait that cannot be resolved | move the hook body into the test or `setUpLaravel()`, express options as literal attribute arguments on the class that carries the trait, and drop the duplicated trait use so the hierarchy inherits the single attribute |
| `LazilyRefreshDatabase` (lazy refresh strategy) | a test class uses it directly, or through a resolved project trait, including across files | there is no lazy counterpart — the eager attribute would change refresh timing: keep the source trait, switch to the eager attribute manually once per-class refresh timing is acceptable, then delete the marker together with the fix |
| `HTTP_UNSUPPORTED_SIGNATURE` | `$this->postJson()` with extra, unpacked or named arguments, unknown/dynamic helpers, unsupported `parent::` assertions/lifecycle calls, direct app writes/references or app arguments passed to unresolved or by-reference signatures | use supported signatures or migrate manually; parent lifecycle calls convert only as direct statements of the matching class hook, while supported parent helpers and project-declared methods remain available; ordinary app reads and proven by-value calls still convert |
| `CLASS_UNSAFE_HIERARCHY` / `LIFECYCLE_UNSUPPORTED` | custom or skipped parent, parameterized lifecycle, trait-provided lifecycle or custom bootstrap (including nested uses and aliases) | include and convert the base class explicitly (add it via `--base-class`, check file/glob and base-rule `withSkip` exclusions), or move and review the lifecycle behavior in the class |
| `LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY` | helper class using `$this->app` or a database trait | decide whether the class should become a Laratesto test or drop the test constructs |
| `ARTISAN_INTERACTION_UNSUPPORTED` | interactive prompts, or a Pending Artisan command retained across later statements, loop iterations or catch/finally | review execution order: Laravel executes retained commands on release, while Laratesto is eager; supported immediate chains and terminal assignments followed only by literal expectations convert when the command does not escape through references or static/global storage |

An assertion-like method name on a proven unrelated native DTO type does not
create a response residual. Unknown receivers, broad `object` types and unions
that may contain a Laravel response remain conservative residuals.

Live constructor-promoted database options on the class, a project ancestor or a used
project trait receive `DATABASE_UNSUPPORTED_CONFIGURATION`. A child literal
redeclaration does not bypass an inherited constructor's assignment; review and
restructure the initialization before lifting options into attributes.

Database option reads through method-local `$this` aliases also block lifting,
including chained/reference assignments, captured closures and possible aliases
in conditional/coalescing expressions. These checks include project ancestors
and composed traits and keep possible aliases after reassignment. Unrelated DTO
reads and the ancestor reader's own unshadowed private slot remain exempt.

Laravel `Seed`/`Seeder` attributes lift only with a positively identified installed
Laravel 13 framework: inherited `Seed` enables seeding, the nearest `Seeder` wins
unless the class has its own, and attributes precede property fallback.
Unsupported metadata receives `DATABASE_UNSUPPORTED_CONFIGURATION`.
On Laravel 12 (which ignores these attributes) or an unknown framework version,
relevant metadata stays in source with the same code for manual migration.

Delete each marker comment together with the fix — a marker left in place keeps being
reported.
The analysis covers supported syntax patterns; review the complete diff and run
the migrated scope even when no markers remain.
Some residuals keep the entire class on its original PHPUnit hierarchy or retain
its source strategy trait. Complete that manual conversion before running it as
a Laratesto test.

## 4. Report and rollback hygiene

- `laratesto-residuals.json` is deterministic (stable sort, no timestamp); add it to
  `.gitignore` unless you want it reviewed in the PR.
- Only whole canonical PHP marker comments are residuals; quoted examples in
  strings, heredocs or surrounding comment prose do not count.
- Configuration, Git, Rector, JSON, unreadable source files, invalid diff hunks or
  report-write failures return `1` and preserve the previous report. That report
  may therefore describe an earlier run. Successful scans with residuals write
  the new report and return `2`.
- Apply writes in place and never rolls back automatically. A failure can leave
  partial source edits even if no new report was written. Review the diff first.
- For sources committed before migration, scope rollback to the processed paths:
  `git restore --source=HEAD -- tests` (substitute your paths). It cannot recover
  pre-existing local changes or untracked/ignored originals absent from HEAD;
  those need your own backup if you used `--allow-dirty` or raw Rector. Avoid broad
  destructive commands (`git checkout -- .`, reset, stash).

## 5. Deprecated string-based migrator

`php artisan laratesto:migrate-phpunit` still works and prints a deprecation warning
on every run, but it is no longer developed. Prefer the Rector path above; the old
command's removal is tracked separately.
