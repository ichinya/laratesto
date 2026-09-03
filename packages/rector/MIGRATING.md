# Migrating a Laravel PHPUnit suite to Laratesto

A walkthrough of both entry points over one repository. Everything below is
copy-pasteable; the fixtures it describes are the ones the package itself is tested
with.

## 0. Preconditions

- A Git work tree with the processed paths clean (`git status -- <paths>`): the
  Artisan `--apply` refuses modified paths by default.
- `testo/testo`, `ichinya/laratesto` and `ichinya/laratesto-rector` installed.
- The project's test classes autoloadable (a standard Laravel `Tests\` namespace is).

## 1. Artisan (recommended)

```bash
git switch -c migrate-to-laratesto
php artisan laratesto:migrate-rector          # dry-run by default
```

The dry-run prints the residuals table and replaces `laratesto-residuals.json` in the
project root — the only file it writes; sources keep their hashes. Exit `2` here means
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
(overrides the clean-paths guard — no automatic rollback is promised for that run).
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
| `RESPONSE_UNSUPPORTED_API` | `assertJsonFragment()`, `withoutExceptionHandling()` | use a supported assert (`assertJson`, `assertJsonStructure`, …) or assert manually on `->json()` |
| `DATABASE_UNSUPPORTED_CONFIGURATION` | custom `beforeRefreshingDatabase()` hooks, dynamic `$connectionsToTruncate` | move the hook body into the test or `setUpLaravel()`, express options as literal attribute arguments |
| `CLASS_UNSAFE_HIERARCHY` / `LIFECYCLE_UNSUPPORTED` | custom parent with its own `setUp`, parameterized lifecycle | convert the base class explicitly (add it via `--base-class`), or restructure |
| `LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY` | helper class using `$this->app` or a database trait | decide whether the class should become a Laratesto test or drop the test constructs |
| `ARTISAN_INTERACTION_UNSUPPORTED` | `expectsQuestion()`, choice/search prompts | split the command test or fake the interaction manually |

Delete each marker comment together with the fix — a marker left in place keeps being
reported.

## 4. Report and rollback hygiene

- `laratesto-residuals.json` is deterministic (stable sort, no timestamp); add it to
  `.gitignore` unless you want it reviewed in the PR.
- Rollback is always scoped to the processed paths:
  `git restore --source=HEAD -- tests`. Avoid broad destructive commands
  (`git checkout -- .`, reset, stash) — they can wipe unrelated work.

## 5. Deprecated string-based migrator

`php artisan laratesto:migrate-phpunit` still works and prints a deprecation warning
on every run, but it is no longer developed. Prefer the Rector path above; the old
command's removal is tracked separately.
