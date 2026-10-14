---
name: laratesto-write-tests
description: Write or update Laravel tests running under Laratesto and Testo. Use for HTTP responses, container binding, database attributes, Laravel fakes, Artisan assertions, or Laravel setup and teardown in a Testo suite.
---

# Write Laravel tests under Testo

Check the installed package versions and project instructions. Follow Testo's
`testo-write-tests` and `testo-test-doubles` skills for general discovery,
assertions and doubles when available, or read them in the installed Testo
package's `skills/` directory. Laratesto adds the optional LaravelTestCase base
and application-aware trait to that workflow. Verify namespaces/API against
installed source rather than copying PHPUnit idioms.

## Application test example

Register LaravelPlugin for the suite and use a disposable test database. The
database example below needs the migration in
[references/database-migration.md](references/database-migration.md); read it
when running this example. Real project tests should use their existing schema.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laratesto\Attribute\RefreshDatabase;
use Laratesto\Testing\LaravelTestCase;
use Testo\Test;

final class BridgeSmokeTest extends LaravelTestCase
{
    protected function setUpLaravel(): void
    {
        Route::get('/laratesto-skill/ping', static fn() => response()->json(['pong' => true]));
    }

    #[Test]
    public function httpResponse(): void
    {
        $this->get('/laratesto-skill/ping')->assertOk()->assertJsonPath('pong', true);
    }

    #[Test]
    #[RefreshDatabase]
    public function persistsWidget(): void
    {
        DB::table('skill_widgets')->insert(['name' => 'example']);

        $this->assertDatabaseHas('skill_widgets', ['name' => 'example']);
        $this->assertDatabaseCount('skill_widgets', 1);
    }

    #[Test]
    public function frameworkFake(): void
    {
        Event::fake();
        event(new \stdClass());

        Event::assertDispatched(\stdClass::class);
    }

    #[Test]
    public function artisanCommand(): void
    {
        $this->artisan('list')->assertSuccessful()->expectsOutputToContain('Available commands');
    }
}
```

Keep meaningful application behavior/assertions when adapting the example;
fakes should verify the expected side effect. Laratesto supplies the framework
assertion shim, so using Laravel's fake assertions does not require installing
PHPUnit. Its shim is not a replacement PHPUnit test runner or full MockObject
implementation; use Testo's doubles guidance for unsupported mock builders.

For trait mode, a complete equivalent binding is:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\InteractsWithLaravel;
use Testo\Assert;
use Testo\Test;

final class TraitSmokeTest
{
    use InteractsWithLaravel;

    #[Test]
    public function containerIsAvailable(): void
    {
        Assert::same($this->app()->environment(), 'testing');
    }
}
```

## Lifecycle and database choices

Use method-level `#[Test]` for LaravelTestCase and InteractsWithLaravel classes.
Class-level discovery also includes their public bridge plumbing, which must
not be executed as tests. NamingConventionPlugin with `test*()` methods is an
alternative if the suite already uses that convention.

Initialize application-dependent state in protected `setUpLaravel()` after
injection; release it in protected `tearDownLaravel()`. Do not manually bootstrap
or inject an application, call PHPUnit lifecycle methods, or use constructors for
container/facade access. Testo can reuse instance fields across methods even
though LaravelPlugin supplies a fresh application per execution.

Choose native `Laratesto\Attribute\` strategies instead of Laravel's PHPUnit
database traits:

- `RefreshDatabase`: migrate and wrap the execution in a rollback transaction;
  supports `seed`, `seeder` and selected `connections`.
- `DatabaseTransactions`: rollback isolation with an already prepared schema.
- `DatabaseMigrations`: migrate before execution and rollback migrations after.
- `DatabaseTruncation`: migrate/truncate selected databases; review named
  connection behavior when porting Laravel's source trait.

Database preparation precedes Laravel setup; Laravel teardown precedes database
cleanup. `connections: null` selects the default, while `connections: []` selects
none. Avoid silently mixing strategies that express incompatible isolation.
Retry/Repeat have fresh Laravel state per attempt/iteration; Bench/PBT inner
loops share state. Only sequential/native Fiber Solo application tests are
supported. A failed setup releases bridge state without invoking user teardown.

Use `$this->get()`/`postJson()` and LaravelResponse assertions for HTTP, the
container via `$this->app()`/`make()` for services, and the native pending result
from `$this->artisan()` for command assertions. Assertions on that result execute
eagerly; migrated deferred Artisan assertions have a separate compatibility path.

Run the new test and its suite through `php vendor/bin/testo run --suite=Laravel
--json`. Verify discovery and native status, including skips and risky results.
The [Laratesto README](https://github.com/ichinya/laratesto/blob/v0.8.0/README.md)
lists the broader helper surface; installed source wins if names differ.
