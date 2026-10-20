# Laratesto

[Laravel](https://laravel.com) integration for [Testo](https://github.com/php-testo/testo).
Boot a fresh application for every test and use Laravel's HTTP, database,
authentication, session, fake and Artisan helpers without installing PHPUnit.

**Documentation:** [Русский](https://php-testo.github.io/ru/docs/bridge/laravel) · [English](https://php-testo.github.io/docs/bridge/laravel)

## Requirements

- PHP 8.3 or newer.
- Laravel 13.
- Testo `^0.10.56` (installed automatically with the bridge).

## Quick start

Install the package in your Laravel application:

```bash
composer require --dev ichinya/laratesto
```

Create `testo.php` in the application root:

```php
<?php

declare(strict_types=1);

use Laratesto\Config\LaravelConfig;
use Laratesto\LaravelPlugin;
use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;

return new ApplicationConfig(
    suites: [
        new SuiteConfig(
            name: 'Laravel',
            location: ['tests/Testo'],
            plugins: [
                new LaravelPlugin(new LaravelConfig(basePath: __DIR__)),
            ],
        ),
    ],
);
```

Create `tests/Testo/ApplicationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Testo;

use Laratesto\Testing\LaravelTestCase;
use Testo\Assert;
use Testo\Test;

#[Test]
final class ApplicationTest extends LaravelTestCase
{
    public function bootsTheApplication(): void
    {
        Assert::same($this->app()->environment(), 'testing');
    }
}
```

Run the suite:

```bash
vendor/bin/testo run --suite=Laravel
```

The separate `tests/Testo` directory lets existing PHPUnit/Pest suites keep
their own runner. You can also use the `InteractsWithLaravel` trait instead of
the base class. See the [guide](https://php-testo.github.io/ru/docs/bridge/laravel)
for HTTP tests, database isolation, configuration and lifecycle hooks.

To run Testo followed by an installed Pest/PHPUnit runner:

```bash
php artisan laratesto:test
```

## Migrating from PHPUnit

The Rector migrator is a separate package with its own documentation and releases:

```bash
composer require --dev ichinya/laratesto-rector

# Preview changes and write the residual report.
php artisan laratesto:migrate-rector

# Apply after committing the source tests.
php artisan laratesto:migrate-rector --apply
```

Review the diff, resolve residuals and run the migrated test scope before removing
PHPUnit. See the [migration guide](https://github.com/ichinya/laratesto-rector/blob/main/MIGRATING.md)
for supported conversions, target modes and report details.

<details>
<summary>Rector set and residual codes</summary>

For a custom Rector configuration, use
`Laratesto\Rector\Set\LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO`.

Unsupported constructs remain in source with a residual marker:

- `CLASS_UNSAFE_HIERARCHY`
- `LIFECYCLE_UNSUPPORTED`
- `DATABASE_UNSUPPORTED_CONFIGURATION`
- `HTTP_UNSUPPORTED_SIGNATURE`
- `RESPONSE_UNSUPPORTED_API`
- `ARTISAN_INTERACTION_UNSUPPORTED`
- `LARAVEL_FAKE_UNSUPPORTED`
- `LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY`

</details>

## AI agent skills

The package includes skills for [configuration](skills/laratesto-configure/SKILL.md),
[writing tests](skills/laratesto-write-tests/SKILL.md) and
[migration](skills/laratesto-migrate-from-phpunit/SKILL.md).
They complement Testo's general testing skills.

To sync them with the optional [llm/skills plugin](https://github.com/roxblnfk/skills),
choose your agent directory and trusted packages during initialization:

```bash
composer require --dev llm/skills
composer skills:init
composer skills:update ichinya/laratesto testo/testo --no-discovery
```

You can also copy the complete skill directories from the installed package's
`skills/` directory into your agent's skills directory.

## HTTP helpers

Both `LaravelTestCase` and `InteractsWithLaravel` provide protected request,
authentication, database, session and Artisan helpers. Responses expose
Testo-backed assertions such as `assertOk()`, `assertJson()` and `assertRedirect()`.

See the [usage guide](https://php-testo.github.io/ru/docs/bridge/laravel) and
the [helper signatures](src/Testing/InteractsWithLaravel.php).

<details>
<summary>LaravelResponse API</summary>

`LaravelResponse` methods:
`status()`, `getStatusCode()`, `headers()`, `header()`, `body()`, `getContent()`, `json()`, `response()`, `getSession()`,
`assertOk()`, `assertStatus()`, `assertCreated()`, `assertBadRequest()`, `assertUnauthorized()`, `assertForbidden()`, `assertNotFound()`,
`assertUnprocessable()`, `assertFound()`, `assertMethodNotAllowed()`, `assertConflict()`, `assertGone()`, `assertInternalServerError()`,
`assertTooManyRequests()`, `assertServiceUnavailable()`, `assertHeader()`, `assertHeaderMissing()`, `assertSee()`, `assertDontSee()`, `assertContent()`,
`assertJson()`, `assertExactJson()`, `assertJsonPath()` (dot-path, closure support), `assertJsonStructure()` (`'*'` wildcard; array structure required),
`assertJsonMissingPath()`, `assertJsonValidationErrors()`, `assertRedirect(?string $uri)`, `assertViewHas()` (closure support),
`assertSessionHas()`, `assertSessionMissing()`, `assertSessionHasErrors()`, `assertSessionHasNoErrors()`,
`assertInertia()`, `assertJsonMissing()`, `assertJsonCount()`, `assertCookie()`, `assertServerError()`, `viewData()`, `inertiaPage()`.

</details>

## SQL and application logs

Enable optional diagnostics in the suite's `LaravelConfig`:

```php
new LaravelConfig(
    basePath: __DIR__,
    captureSql: true,
    captureLogs: true,
    includeSqlBindings: false,
    includeLogContext: false,
);
```

The `sql` channel records SQL, connection name and duration; `laravel` records
application messages with their original severity. All four options default to
`false`. Bindings and log context are included only when explicitly enabled;
SQL literals and the application's message text are retained as written.

Capture starts after application bootstrap and covers database preparation,
setup, the test, teardown and cleanup. Messages belong to the current Testo test
and carry an execution counter for retries/repeats; Testo controls which repeated
executions remain in its output. Existing application logging stays active.

Testo displays channels for failures, or streams them with `-v` (except in Dots
mode). Its JSON failure output includes channel names and text; JUnit includes
only `stdout`/`stderr`, so these channels do not appear there.

## Limitations

- Laravel tests run sequentially because the framework keeps global state.
  Testo Fiber `Schedule::Solo` is supported; interleaving schedules are rejected.
- The bundled PHPUnit shim supports Laravel assertions and fakes, but is not a
  complete PHPUnit replacement. Use Testo's Mockery bridge for mocks.

## Development

```bash
composer install
composer test
```

Migration rules are maintained in
[ichinya/laratesto-rector](https://github.com/ichinya/laratesto-rector).
This repository keeps their runtime integration tests. See
[CI](.github/workflows/ci.yml) for the supported PHP matrix and coverage checks.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
