# Testo Laravel Bridge

Native [Testo](https://github.com/php-testo/testo) plugin that boots [Laravel](https://laravel.com) around every test — without PHPUnit.

The bridge is a standalone Composer package. It does not require any changes to Testo or to your application: it registers a `TestRunInterceptor` through the standard plugin API (the same mechanism `testo/bridge-mockery` uses).

> The `testo` vendor namespace on Packagist belongs to the framework author, so this package is published as `ichinya/laratesto`. If the bridge is ever adopted upstream, it can move to `testo/bridge-laravel` following the ecosystem convention.

## Features

- Boots the framework the same way `artisan` does: `bootstrap/app.php` + Console kernel bootstrap.
- A **fresh application per test** with guaranteed cleanup of Laravel static state afterwards.
- `.env.testing` support via the standard `APP_ENV` mechanism.
- Service container, facades, global `app()` helper, Artisan.
- HTTP requests through the HTTP kernel (`get`, `post`, `postJson`, …) with a Testo-native `LaravelResponse` wrapper (`assertOk`, `assertStatus`, `assertJson`, `assertJsonPath`, `assertJsonStructure`, `assertRedirect`, … — no PHPUnit).
- `actingAs`, `actingAsGuest`, `assertAuthenticated`, `assertGuest`, `assertAuthenticatedAs`.
- `assertDatabaseHas`, `assertDatabaseMissing`, `assertDatabaseCount`.
- `assertSessionHas`, `assertSessionMissing`, `assertSessionHasErrors`.
- `assertExitCode` for Artisan commands.
- `withoutMiddleware` (all or specific middleware classes).
- Session cookies are automatically bridged across requests within the same test.
- `#[RefreshDatabase]`, `#[DatabaseMigrations]` and `#[DatabaseTransactions]`
  attributes with Laravel-compatible lifecycle ordering.
- Safe `php artisan laratesto:migrate-phpunit` source converter with dry-run,
  conflict protection and explicit diagnostics for manual-only constructs.
- Unified `php artisan test` entry point: Testo first, then Pest or PHPUnit when
  a legacy-compatible runner is still installed.

## Requirements

- PHP 8.2+ (the same minimum as Testo)
- Laravel 12 or 13
- Testo `^0.10.42`

## Installation

```bash
composer require --dev ichinya/laratesto
```

Register the plugin for a suite in `testo.php`:

```php
<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;
use Laratesto\Config\LaravelConfig;
use Laratesto\LaravelPlugin;
use Testo\Convention\NamingConventionPlugin;

return new ApplicationConfig(
    suites: [
        new SuiteConfig(
            name: 'Unit',
            location: ['tests/Unit'],
        ),
        new SuiteConfig(
            name: 'Laravel',
            location: ['tests/Feature'],
            plugins: [
                new NamingConventionPlugin(),
                new LaravelPlugin(
                    new LaravelConfig(
                        basePath: __DIR__,
                    ),
                ),
            ],
        ),
    ],
);
```

`NamingConventionPlugin` is optional; with it, `*Test.php` classes and `test*()` methods are discovered automatically and no `#[Test]` attributes are needed.

Run with:

```bash
vendor/bin/testo run --suite=Laravel
```

## Running all application tests

Package discovery replaces Laravel Collision's `test` command with a unified
runner:

```bash
php artisan test
```

The command always runs `php vendor/bin/testo run` first. It then runs Pest when
`vendor/bin/pest` exists, otherwise PHPUnit when `vendor/bin/phpunit` exists. It
never runs Pest and PHPUnit separately because Pest already uses PHPUnit as its
test engine; doing both would execute the same legacy suite twice. If neither is
installed, a successful Testo run is the complete result.

Every started runner contributes to the final exit code. By default the next
runner still executes after a failure so one CI job reports the full picture;
`--fail-fast` stops before the next stage instead.

```bash
# Testo only, useful after the migration is complete.
php artisan test --testo-only

# Only the detected Pest/PHPUnit runner.
php artisan test --legacy-only

# Common selectors are translated for each runner.
php artisan test --filter=User --testsuite=Feature --path=tests/Feature

# Runner-specific arguments are passed literally without a shell.
php artisan test --testo-arg=--json --legacy-arg=--colors=always
```

Supported shared options are `--filter`, `--group`, `--suite` / `--testsuite`,
`--path`, `--coverage` and `--no-coverage`. Positional paths are accepted as
well. `--without-tty` remains available for Laravel CI command compatibility;
the child processes are always streamed without an interactive TTY.

## Migrating PHPUnit tests

Laravel package discovery registers a conservative migration command:

```bash
# Preview the default tests/Unit -> tests/Testo/Unit migration.
php artisan laratesto:migrate-phpunit --dry-run

# Convert a directory while keeping the PHPUnit sources.
php artisan laratesto:migrate-phpunit tests/Unit

# Move sources only after every generated target has been verified on disk.
php artisan laratesto:migrate-phpunit tests/Unit --remove-source
```

Pass `--target=tests/Testo/Custom` for a non-standard layout. Existing targets
are never overwritten unless `--force` is present. `--dry-run` performs the
same parsing and compatibility checks but does not write or remove anything.

The command converts the common mechanical surface: PHPUnit assertion argument
order, `TestCase` removal, Laravel's `Tests\TestCase` bridge, database traits,
`setUp()` / `tearDown()` hooks, exception expectations, skips and Laravel
response accessors. It refuses a file when it finds data providers, dependencies,
coverage/group metadata, PHPUnit mocks, constraints, regex exception matching,
class-level lifecycle hooks or another construct without a faithful Testo
equivalent. Those files need an explicit human migration; the command will not
write a partial target for them.

The converter intentionally does not rewrite `phpunit.xml`: reproduce its
environment overrides and suite boundaries in `testo.php`. Generated `test*()`
methods also require `NamingConventionPlugin` in the target suite.

After conversion, run the exact Testo scope before removing PHPUnit:

```bash
vendor/bin/testo run --suite=Unit
```

## Migrating with Rector

For full Laravel test suites the bridge ships a Rector-based migrator. The
command wraps the pinned Rector binary with the public
`Laratesto\Rector\Set\LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO`
set, prints the residual table and writes a deterministic `laratesto-residuals.json`:

```bash
# Dry-run (default): sources stay untouched, report is written.
php artisan laratesto:migrate-rector

# Process specific paths and write to a custom report location.
php artisan laratesto:migrate-rector --path=tests/Feature --report=storage/residuals.json

# Rewrite the sources in place (refuses dirty processed paths unless --allow-dirty).
php artisan laratesto:migrate-rector --apply

# Choose the conversion target and additional project base classes.
# Forward slashes are accepted everywhere; quote the backslash form on POSIX shells.
php artisan laratesto:migrate-rector --target-mode=trait --base-class=Tests/ApiTestCase
```

`--base-class` values are canonicalized before use: surrounding whitespace is
trimmed, forward slashes become backslashes, and a leading separator is accepted
zero or exactly one time — `Tests/ApiTestCase`, `\Tests\ApiTestCase`,
`/Tests/ApiTestCase` and `' Tests\ApiTestCase '` all become `Tests\ApiTestCase` —
duplicates are removed. Anything that cannot be a PHP class name is rejected
with exit `1`: an empty value, repeated or mixed leading separators (`//Tests`,
`\\Tests`), an empty internal segment such as the double backslash of a wrongly
escaped `Tests\\\\ApiTestCase`, a trailing separator, dots, colons or embedded
whitespace. Pass forward slashes, or quote the backslash form: in POSIX shells
an unquoted `Tests\ApiTestCase` silently loses the backslash.

Exit contract: `0` — no manual residuals; `1` — execution, guard or report
failure; `2` — manual residuals present (Rector's dry-run "changes found" exit
is a successful execution here).

Every external process is bounded: Git guard calls and the Rector run must
finish within 10 minutes (`SymfonyProcessRunner::DEFAULT_TIMEOUT_SECONDS`),
after which the process is killed and the run fails with exit `1` — never an
unbounded hang. A process that cannot start (e.g. Git is not installed for the
`--apply` guard, or the pinned Rector binary is missing — the binary is checked
explicitly first) fails the command with exit `1` and its stderr diagnostics
instead of a raw exception trace.

### Target modes

- `base_class` (default) — the project base `Tests\TestCase` is converted
  exactly once: it extends `Laratesto\Testing\LaravelTestCase` and keeps all of
  its custom helpers and setup. Classes extending the Laravel foundation
  `TestCase` directly are converted the same way. **Descendants of the project
  base keep their `extends` untouched** — the converted base carries the
  Laravel binding, and the descendant only receives lifecycle, HTTP and
  database conversions.
- `trait` — the project base drops its parent and uses
  `Laratesto\Testing\InteractsWithLaravel`; concrete classes extending the
  foundation `TestCase` directly do the same. Descendants keep inheriting from
  the project base.

### Fail-closed residuals

When the safety of a transformation cannot be proven, the migrator leaves the
construct untouched and attaches a stable `laratesto-residual(code=…)` marker
comment. The full catalog:

- `CLASS_UNSAFE_HIERARCHY`
- `LIFECYCLE_UNSUPPORTED`
- `DATABASE_UNSUPPORTED_CONFIGURATION`
- `HTTP_UNSUPPORTED_SIGNATURE`
- `RESPONSE_UNSUPPORTED_API`
- `ARTISAN_INTERACTION_UNSUPPORTED`
- `LARAVEL_FAKE_UNSUPPORTED`
- `LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY`

The marker is keyed by `code`: one class carries at most one marker comment per
code, and every rule that fails that code contributes its own `rule=…` segment
into the single comment, sorted by rule class-string. Later rules never overwrite
or lose earlier reasons, and the scanner reports one finding per contribution.

Re-running the migrator reconciles the markers: a rule refreshes only its own
segment when its constructs changed, unchanged runs stay byte-identical (repeat
applies are idempotent), and a fixed construct loses its marker only when you
delete the marker comment together with the fix — a marker left in place keeps
being reported.

### Database trait conversion

`RefreshDatabase`, `DatabaseTransactions`, `DatabaseMigrations` and
`DatabaseTruncation` convert to their Laratesto attributes when the option
properties are non-static literals with the supported shape, are not read by
class code, and the class defines no database lifecycle hooks
(`before/afterRefreshingDatabase`, `before/afterTruncatingDatabase`,
`migrateFreshUsing`, …). Multi-property declarations keep their unrelated
siblings (`protected bool $seed = true, $keepMe = false;` loses only `$seed`).
Everything else fails closed with `DATABASE_UNSUPPORTED_CONFIGURATION`.
An option property declared on a resolved project ancestor (a configured base
such as `Tests\TestCase`, or any resolvable class between it and the trait) stays
live for the trait machinery through `property_exists()`, so a descendant's
conversion fails closed with `DATABASE_UNSUPPORTED_CONFIGURATION` instead of
dropping it; move the option into a literal attribute argument on the class that
carries the trait. An option the class itself redeclares is the exception: PHP
resolves the `property_exists()` gate and the value read to the most-derived
declaration, so only the class's own (already lifted) literal is live and the
ancestor's same-named declaration is inert at every chain depth. Trait machinery
methods on the same ancestor are deliberately not flagged: a trait import in the
child overrides same-named inherited methods, so such an override never executed.
A project reader above the class bounds that relaxation: the redeclare is what
every project method higher up observes, so lifting the class's own declaration
would silently repoint their reads at the nearest remaining declaration. The
preflight fails the lift closed for readers in resolved ancestors, in the traits
those ancestors compose, and in the project traits the converting class itself
composes (trait methods execute with the consumer's scope) — except the proven
inert shape where the reader's own scope declares the option privately: a
more-derived redeclare never shadows it there, so the private slot answers
identically before and after the lift. Statically named instance reads
(`$this->seed`, `$this->{'seed'}`, nullsafe fetches) count; a dynamic property
name (`$this->{$option}`) cannot be proven either way; static property fetches
never resolve an instance declaration.

When a class re-declares the same database trait a project ancestor already
uses, that is a duplicate of one strategy, not a second one — Laravel collapsed
it to a single behavior through `class_uses_recursive()`. The conversion
therefore emits no second attribute for the duplicate: it removes the trait use
(and any identical option declarations) and the class keeps inheriting the
ancestor's single attribute, so the database interceptor runs exactly once. The
merge is only taken when the effective option configuration below the topmost
duplicate ancestor is provably identical to the configuration that ancestor
carries — same names, same literal values, or nothing on both sides. Anything
else fails closed with `DATABASE_UNSUPPORTED_CONFIGURATION`; remove the
duplicated trait and its options, or align them with the ancestor. An ancestor
that already carries the migrated attribute counts the same way, so migrating
files one at a time cannot stack a second interceptor either.

The same applies to option properties supplied by non-Laravel project traits:
traits flatten their whole composition tree into every consuming class, so `use
RefreshDatabase` plus `use ProjectOptions { protected bool $seed = true; }`
seeds today, yet no class-level scan sees a trait property. The preflight
resolves the directly used traits of the class and of every resolvable ancestor
(same-file first, then reflection), inspects their composition trees, and fails
the conversion closed with `DATABASE_UNSUPPORTED_CONFIGURATION` instead. A used
trait that cannot be resolved cannot be proven option-free and blocks as well.
Private members of a directly used trait are live (they flatten into the
consumer's own scope); private members of an ancestor-used trait stay private to
the ancestor and are not flagged; static members are never flagged in either
scope — the traits read their options through `$this`, which never resolves to a
static declaration. Trait uses inside nested class-likes belong to those
class-likes and are not flagged.

### Dry-run report contract

`laratesto-residuals.json` is deterministic: stable schema version, sorted
residuals (file, line as an integer, code, rule), no timestamps. Dry-run and
apply report residuals identically: every processed PHP file is scanned exactly
once, and in a dry-run a machine-JSON diff overlays the reconstructed new-side
content for its file — freshly added markers are reported, markers a change
would remove are not, and markers already on disk (from an earlier apply) stay
reported even when the second run is a no-op. The report is only replaced
after a fully successful run — a Rector, Git or JSON failure preserves the
previous report.

## Writing tests

### Base class

```php
use Laratesto\Testing\LaravelTestCase;

final class UserControllerTest extends LaravelTestCase
{
    public function testIndexReturnsUsers(): void
    {
        $this->get('/users')
            ->assertOk()
            ->assertJson(['users' => []]);
    }
}
```

### Trait (no inheritance)

```php
use Laratesto\Testing\InteractsWithLaravel;

final class UserServiceTest
{
    use InteractsWithLaravel;

    public function testCreatesUser(): void
    {
        $service = $this->make(UserService::class);

        $service->create(...);

        // ...
    }
}
```

### Setup and teardown hooks

`LaravelTestCase` calls hooks after application injection and before final state
cleanup. PHPUnit lifecycle methods migrate as follows:

```php
protected function setUpLaravel(): void
{
    // Laravel application is available here.
}

protected function tearDownLaravel(): void
{
    // Runs after the test while Laravel is still available.
}
```

### Function style

After the framework is booted, global helpers work in plain test functions too:

```php
use Testo\Assert;

function testResolvesUserService(): void
{
    Assert::instanceOf(app(UserService::class), UserService::class);
}
```

## Configuration

`LaravelConfig` options:

| Option         | Default     | Description                                                                    |
| -------------- | ----------- | ------------------------------------------------------------------------------ |
| `basePath`     | —           | Laravel project root; `bootstrap/app.php` is loaded from it.                   |
| `environment`  | `testing`   | Forced into `APP_ENV` before boot, so `.env.testing` is loaded when present.   |
| `config`       | `[]`        | Config overrides applied after boot (`config()->set($key, $value)`).           |

## Database attributes

```php
use Laratesto\Attribute\DatabaseMigrations;
use Laratesto\Attribute\DatabaseTransactions;
use Laratesto\Attribute\RefreshDatabase;

final class BillingTest
{
    use InteractsWithLaravel;

    #[RefreshDatabase]          // migrate:fresh before the test (seed: true to run seeders)
    public function testInvoicesAreCreated(): void { /* ... */ }

    #[DatabaseMigrations]       // migrate:fresh before, migrate:rollback afterwards
    public function testLegacyMigrationFlow(): void { /* ... */ }

    #[DatabaseTransactions]     // wrap the test in a transaction, roll back afterwards
    public function testPaymentIsProcessed(): void { /* ... */ }
}
```

Both attributes can be combined; migrations run first, then the wrapping transaction. The interceptors resolve the application booted by the plugin, so they require the `LaravelPlugin` in the suite.

## HTTP helpers

Available via `LaravelTestCase` or the `InteractsWithLaravel` trait:

| Method                                              | Description                              |
| --------------------------------------------------- | ---------------------------------------- |
| `$this->app()`                                      | The application booted for current test. |
| `$this->make($abstract)`                            | Resolve a service.                       |
| `$this->get($uri, $headers)`                        | GET request.                             |
| `$this->post($uri, $parameters, $headers)`          | POST with form parameters.               |
| `$this->postJson($uri, $payload, $headers)`         | POST with a JSON body.                   |
| `$this->sendRequest($method, $uri, ...)`            | Arbitrary method.                        |
| `$this->artisan($command, $parameters)`             | Run an Artisan command, returns exit code. |
| **Authentication**                                  |                                          |
| `$this->actingAs($user, $guard)`                    | Set the authenticated user on the guard. |
| `$this->actingAsGuest($guard)`                      | Clear the authenticated user.            |
| `$this->assertAuthenticated($guard)`                | Assert the user is authenticated.        |
| `$this->assertGuest($guard)`                        | Assert the user is not authenticated.    |
| `$this->assertAuthenticatedAs($user, $guard)`       | Assert the current user is the given one.|
| **Database**                                        |                                          |
| `$this->assertDatabaseHas($table, $data, $conn)`    | Assert a row exists.                     |
| `$this->assertDatabaseMissing($table, $data, $conn)`| Assert a row does not exist.             |
| `$this->assertDatabaseCount($table, $count, $conn)` | Assert the row count.                    |
| **Session**                                         |                                          |
| `$this->assertSessionHas($key, $value)`             | Assert a session key exists (and value). |
| `$this->assertSessionMissing($key)`                 | Assert a session key is absent.          |
| `$this->assertSessionHasErrors(array $fields)`      | Assert validation errors.                |
| `$this->session()`                                  | The session store.                       |
| **Artisan**                                         |                                          |
| `$this->assertExitCode($code, $command, $params)`   | Assert an Artisan command exit code.     |

`LaravelResponse` methods:
`status()`, `getStatusCode()`, `headers()`, `header()`, `body()`, `getContent()`, `json()`, `response()`, `getSession()`,
`assertOk()`, `assertStatus()`, `assertCreated()`, `assertBadRequest()`, `assertUnauthorized()`, `assertForbidden()`, `assertNotFound()`,
`assertUnprocessable()`, `assertFound()`, `assertMethodNotAllowed()`, `assertConflict()`, `assertGone()`, `assertInternalServerError()`,
`assertTooManyRequests()`, `assertServiceUnavailable()`, `assertHeader()`, `assertHeaderMissing()`, `assertSee()`, `assertDontSee()`, `assertContent()`,
`assertJson()`, `assertExactJson()`, `assertJsonPath()` (dot-path, closure support), `assertJsonStructure()` (`'*'` wildcard; array structure required),
`assertJsonMissingPath()`, `assertJsonValidationErrors()`, `assertRedirect(?string $uri)`, `assertViewHas()` (closure support),
`assertSessionHas()`, `assertSessionMissing()`, `assertSessionHasErrors()`.

## Mockery

For suites that need [Mockery](https://github.com/mockery/mockery), use the
[`testo/bridge-mockery`](https://packagist.org/packages/testo/bridge-mockery)
plugin: it calls `Mockery::close()` automatically after every test (verifying
expectations and preventing state leaks), so no teardown boilerplate is needed.

```bash
composer require --dev mockery/mockery testo/bridge-mockery
```

```php
use Testo\Bridge\Mockery\MockeryPlugin;

new SuiteConfig(
    name: 'Laravel',
    location: ['tests/Feature'],
    plugins: [
        new NamingConventionPlugin(),
        new MockeryPlugin(),
        new LaravelPlugin(new LaravelConfig(basePath: __DIR__)),
    ],
);
```

This works with Laravel facade mocking too:

```php
use Illuminate\Support\Facades\Log;

public function testLogsWarningOnFailure(): void
{
    Log::shouldReceive('warning')->once()->with('something went wrong');

    // ... run the code under test ...
}
```

## How it works

1. `LaravelTestInterceptor` runs before attribute interceptors for every test:
   it forces `APP_ENV`, requires `bootstrap/app.php`, bootstraps the Console kernel
   and applies config overrides.
2. The booted application is injected, database attributes prepare their state,
   and only then `setUpLaravel()` runs, matching Laravel's PHPUnit lifecycle.
3. After the test, `tearDownLaravel()` runs first; database transactions or
   migrations are rolled back next; finally connections are closed, the
   container is flushed, facades are cleared and framework static state resets.
4. A new application instance is created for every test, which is what makes the
   cleanup sufficient: container-level state dies with the old application.
5. Session cookies collected from each response are automatically bridged to the
   next request inside the same test, so multi-step flows (login → redirect →
   follow-up) work out of the box.

## Limitations

- **This is not a runner for existing PHPUnit/Pest Laravel tests.** Laravel's
  `TestCase` extends PHPUnit, and Laravel assertions (`Queue::assertPushed`, etc.)
  call PHPUnit under the hood. Tests must be written against Testo assertions
  (`Testo\Assert`) — hence the PHPUnit-free `LaravelResponse` wrapper.
- **Facade fakes (`Queue::fake()`, `Event::fake()`, …) require `phpunit/phpunit`
  as a library** if you need their `assert*` methods. The fake setup itself works
  without PHPUnit — the facades resolve on the booted application with no bridge
  involvement — but every official Laravel fake uses `PHPUnit\Framework\Assert`
  internally. If your project already has `phpunit/phpunit` in `require-dev` (as
  most do), the fakes work transparently. In a pure Testo project without PHPUnit,
  the `assert*` methods on fakes will throw class-not-found errors.
- **The following Laravel TestCase conveniences are not (yet) ported** and have
  simple workarounds:
  - `withoutExceptionHandling()` — set `APP_DEBUG=true` in `.env.testing` or
    configure the exception handler directly.
  - `withoutVite()` / `withoutMix()` — set `VITE_BYPASS=true` / `MIX_BYPASS=true`
    in your environment, or configure the entry point resolution in the config.
  - `$this->seed()` — call `Artisan::call('db:seed', ['--force' => true])`
    or `DB::table(...)->insert(...)` directly.
- Laravel keeps a lot of state in process-global statics, so a Laravel suite must
  run tests **sequentially**. Do not enable fiber-based concurrency for it.
- `PluginConfigurator::configure()` currently receives Testo's internal container
  (`Internal\Container\Container`). The supported `testo/testo` range is pinned in
  `composer.json` and tested in CI against the released versions.

## Development

```bash
composer update
composer test
```

The package's own test suite runs under Testo: `tests/Unit` covers the response
wrapper, `tests/Integration` boots the fixture application in `tests/Fixture/laravel`
(SQLite in-memory) and exercises HTTP requests, the database attributes and
cross-test isolation.

### Rector bridge development

The migration rules live in the standalone package `packages/rector`
(`ichinya/laratesto-rector`). The root repository wires it in as a Composer
path repository in symlink mode, so `composer install` junctions the package
into `vendor/` and the suite always exercises the live repo source — no
duplicate `Laratesto\Rector\` autoload mapping is needed (or allowed).

`rector/rector` is pinned to an exact version (`2.6.2`) in both the consuming
application and the package: the rules depend on internals of that Rector
generation (the fixture-test bridge and the machine-JSON output contract), and
Rector minor releases routinely rename them. Bump the pin only together with a
green fixture suite and the parity end-to-end gate.

CI (`.github/workflows/ci.yml`) runs on Linux and Windows, PHP 8.2, 8.3 and
8.4: `composer validate --strict`, a locked install, a whitespace check over
the changed lines of the triggering range (pull requests: merge base to head;
pushes: the pushed commits, or the whole tree when the previous SHA is
unavailable), the rector package's composer validation (non-strict — the
deliberate Rector pin triggers a warning) and the full `composer test` suite,
which includes the migration end-to-end gate. The root lock is resolved with
Composer's PHP 8.2 platform so the same lock remains installable on every
supported PHP version.

## License

BSD 3-Clause. See [LICENSE](LICENSE).
