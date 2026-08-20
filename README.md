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
- `#[RefreshDatabase]` and `#[DatabaseTransactions]` attributes.

## Requirements

- PHP 8.3+
- Laravel 13
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
use Laratesto\Attribute\DatabaseTransactions;
use Laratesto\Attribute\RefreshDatabase;

final class BillingTest
{
    use InteractsWithLaravel;

    #[RefreshDatabase]          // migrate:fresh before the test (seed: true to run seeders)
    public function testInvoicesAreCreated(): void { /* ... */ }

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
`status()`, `header()`, `headers()`, `body()`, `json()`,
`assertOk()`, `assertStatus()`, `assertCreated()`, `assertBadRequest()`, `assertUnauthorized()`, `assertForbidden()`, `assertNotFound()`, `assertUnprocessable()`,
`assertHeader()`, `assertHeaderMissing()`,
`assertSee()`, `assertDontSee()`,
`assertJson()`, `assertJsonPath()` (dot-path, closure support), `assertJsonStructure()` (`'*'` wildcard),
`assertRedirect(?string $uri)`, `response()`.

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
2. The booted application is injected into test cases that use `LaravelTestCase`
   or the `InteractsWithLaravel` trait.
3. After the test — in a `finally` block — database connections are closed, the
   container is flushed, facades are cleared and the static-state resets from the
   framework's own test teardown are applied.
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

## License

BSD 3-Clause. See [LICENSE](LICENSE).
