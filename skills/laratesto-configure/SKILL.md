---
name: laratesto-configure
description: Configure the Laratesto Laravel bridge in testo.php. Use when registering LaravelPlugin, choosing basePath or testing environment overrides, setting up LaravelTestCase or trait mode, or diagnosing application bootstrap and unsupported Fiber scheduling.
---

# Configure the Laravel bridge

Check the project's instructions and installed Composer versions first. This
package supports PHP 8.3+, Laravel 13 and Testo `^0.10.56`. Resolve package paths
through `Composer\InstalledVersions::getInstallPath()` if the project uses a
custom vendor directory. The installed `Laratesto\Config\LaravelConfig` and
`Laratesto\Testing\` classes are authoritative.

Use Testo's `testo-configure` skill for general suites, discovery and output
plugins when available. Otherwise read it in the installed Testo package's
`skills/testo-configure/SKILL.md`. Preserve the application's existing suites.

## Register the application

Install `ichinya/laratesto` as a development dependency. Put LaravelPlugin on the
suite that needs the application; ordinary unit suites can run without it.
`basePath` is the Laravel project root containing `bootstrap/app.php`, not the
test directory. In a root-level `testo.php`, a minimal suite is:

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
            location: ['tests/Feature'],
            plugins: [
                new LaravelPlugin(new LaravelConfig(
                    basePath: __DIR__,
                    environment: 'testing',
                    config: [
                        'database.default' => 'sqlite',
                        'database.connections.sqlite.database' => ':memory:',
                        'cache.default' => 'array',
                        'session.driver' => 'array',
                    ],
                )),
            ],
        ),
    ],
);
```

Use class-level `#[Test]` when all public `void`/`never` methods are tests, or
method-level attributes to select individual tests. LaravelPlugin excludes its
own helper implementations in both base-class and trait mode. If the project
uses `*Test.php`/`test*()` discovery, retain or add
`Testo\Convention\NamingConventionPlugin`. Do not assume naming alone discovers
tests in a suite without that plugin.

`environment` defaults to `testing` and is forced into `APP_ENV` before Laravel
bootstrap, including `.env.testing` loading. `config` overrides are applied
after Console kernel bootstrap; settings needed by a provider during bootstrap
belong in the testing environment/config files. Use a disposable testing database;
database attributes can migrate or truncate it. Do not alter production settings.

## Bind a test class

For application helpers, extend `Laratesto\Testing\LaravelTestCase`, or use
`Laratesto\Testing\InteractsWithLaravel` in a plain class. Use the latter when
inheritance is already needed for another local base. LaravelPlugin injects the
application; the base class or trait alone does not boot it. The runtime does not
need `phpunit/phpunit`.

Setup that needs the application belongs in `setUpLaravel()`, and cleanup while
Laravel is available belongs in `tearDownLaravel()`. Constructors execute before
injection. Database attributes prepare state before setup. Testo reuses the case
instance, so initialize per-test instance properties in setup.

## Verify configuration

Run a known application test, then the whole configured suite:

```bash
php vendor/bin/testo run --suite=Laravel --json
```

Require a nonzero discovered count and the native report's `status: passed`;
inspect failures, skips and assertion counts. Use Testo's `testo-run-tests` skill
for filtering/report semantics. Package discovery also supplies
`php artisan laratesto:test --testo-only`; the default command runs installed
legacy Pest/PHPUnit too, so choose it only when that combined run is intended.

Keep Laravel tests sequential or in native Fiber `Schedule::Solo`. Effective
class `RoundRobin`/`Random` scheduling is rejected before application bootstrap.
Each Retry attempt and Repeat iteration gets a fresh application. Benchmark
loops and generated property trials share their enclosing execution's Laravel
state; do not promise per-loop/trial isolation.

Consult the installed package's README for additional compatibility boundaries,
or the [versioned README](https://github.com/ichinya/laratesto/blob/v0.8.0/README.md).
