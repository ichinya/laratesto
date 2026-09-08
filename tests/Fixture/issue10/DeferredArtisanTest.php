<?php

namespace Tests;

final class DeferredEvents { public static array $events = []; }

final class DeferredArtisanTest extends \Illuminate\Foundation\Testing\TestCase
{
    private ?array $eventsAtTeardown = null;

    public function createApplication()
    {
        $app = require getenv('LARATESTO_ISSUE10_APP').'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new class extends \Illuminate\Console\Command {
            protected $signature = 'compat:deferred {label}';
            public function handle(): int
            {
                DeferredEvents::$events[] = $this->argument('label');
                $this->line('deferred output');
                return 0;
            }
        });
        return $app;
    }

    protected function setUp(): void { parent::setUp(); DeferredEvents::$events = []; $this->eventsAtTeardown = null; }

    protected function tearDown(): void
    {
        if ($this->eventsAtTeardown !== null) {
            $this->assertSame($this->eventsAtTeardown, DeferredEvents::$events);
        }
        parent::tearDown();
    }

    public function testCommandsRunAtMethodEndBeforeTeardown(): void
    {
        $pending = $this->artisan('compat:deferred', ['label' => 'method end']);
        $pending->assertExitCode(0);
        DeferredEvents::$events[] = 'before return';
        $this->eventsAtTeardown = ['before return', 'method end'];
        $this->assertSame(['before return'], DeferredEvents::$events);
    }

    public function testAssertionsDoNotExecuteBeforeRelease(): void
    {
        $pending = $this->artisan('compat:deferred', ['label' => 'command']);
        $pending->assertExitCode(0);
        DeferredEvents::$events[] = 'after assertion';
        $this->assertSame(['after assertion'], DeferredEvents::$events);
        unset($pending);
        $this->assertSame(['after assertion', 'command'], DeferredEvents::$events);
    }

    public function testOutputExpectationsArePreserved(): void
    {
        $pending = $this->artisan('compat:deferred', ['label' => 'output']);
        $pending->expectsOutput(output: 'deferred output');
        $pending->assertSuccessful();
        DeferredEvents::$events[] = 'before release';
        unset($pending);
        $this->assertSame(['before release', 'output'], DeferredEvents::$events);
    }

    public function testWrongExitCodeStillFailsOnRelease(): void
    {
        $pending = $this->artisan('compat:deferred', ['label' => 'failure']);
        $pending->assertExitCode(7);
        DeferredEvents::$events[] = 'before release';
        unset($pending);
    }

    public function testWrongOutputStillFailsOnRelease(): void
    {
        $pending = $this->artisan('compat:deferred', ['label' => 'failure']);
        $pending->expectsOutputToContain('missing output');
        DeferredEvents::$events[] = 'before release';
        unset($pending);
    }
}
