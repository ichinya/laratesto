<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

final class PendingArtisanPreflightTest extends LaravelTestCase
{
    public function test_pending_command_signatures(): void
    {
        $this->artisan('about')->assertSuccessful()->expectsOutputToContain('Environment');

        $pending = $this->artisan('demo');
        $pending->assertExitCode();
        $pending->expectsQuestion('Name?', 'Ada');

        $this->expectsQuestion('not a pending command');
    }

    private function expectsQuestion(string $message): void
    {
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): assertExitCode() expects 1..1 common-path arguments; got 0 */
/* laratesto-residual(code=ARTISAN_INTERACTION_UNSUPPORTED, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): expectsQuestion() is an interactive Pending Artisan API; $pending retains a Pending Artisan command across statements or control flow; Laravel executes it on release, while Laratesto executes it eagerly */
final class PendingArtisanPreflightTest extends LaravelTestCase
{
    public function test_pending_command_signatures(): void
    {
        $this->artisan('about')->assertSuccessful()->expectsOutputToContain('Environment');

        $pending = $this->artisan('demo');
        $pending->assertExitCode();
        $pending->expectsQuestion('Name?', 'Ada');

        $this->expectsQuestion('not a pending command');
    }

    private function expectsQuestion(string $message): void
    {
    }
}
