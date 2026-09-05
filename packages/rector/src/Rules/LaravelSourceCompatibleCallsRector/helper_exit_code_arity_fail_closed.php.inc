<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

final class ExitCodeArityBlockedTest extends LaravelTestCase
{
    public function test_arity_gaps(): void
    {
        $this->assertExitCode();
        $this->assertExitCode(0);
        $this->assertExitCode('0', 'parity:ping');
        $this->assertExitCode(0, 42);
        $this->assertExitCode(0, 'parity:ping', [], 'extra');
    }
}

final class ExitCodeArityAcceptedTest extends LaravelTestCase
{
    public function test_supported_forms(): void
    {
        $this->assertExitCode(0, 'parity:ping');
        $this->assertExitCode(1, 'parity:ping', ['--fail' => true]);
    }

    public function test_pending_command_chain(): void
    {
        $this->artisan('parity:ping')->assertExitCode(0);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): assertExitCode() expects 2..3 common-path arguments; got 0; assertExitCode() expects 2..3 common-path arguments; got 1; assertExitCode() argument 1 is not a statically supported int; assertExitCode() argument 2 is not a statically supported string; assertExitCode() expects 2..3 common-path arguments; got 4 */
final class ExitCodeArityBlockedTest extends LaravelTestCase
{
    public function test_arity_gaps(): void
    {
        $this->assertExitCode();
        $this->assertExitCode(0);
        $this->assertExitCode('0', 'parity:ping');
        $this->assertExitCode(0, 42);
        $this->assertExitCode(0, 'parity:ping', [], 'extra');
    }
}

final class ExitCodeArityAcceptedTest extends LaravelTestCase
{
    public function test_supported_forms(): void
    {
        $this->assertExitCode(0, 'parity:ping');
        $this->assertExitCode(1, 'parity:ping', ['--fail' => true]);
    }

    public function test_pending_command_chain(): void
    {
        $this->artisan('parity:ping')->assertExitCode(0);
    }
}
