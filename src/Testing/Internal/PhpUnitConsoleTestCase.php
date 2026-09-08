<?php

declare(strict_types=1);

namespace Laratesto\Testing\Internal;

/** @internal Assertion/expectation storage required by Laravel's PendingCommand. */
final class PhpUnitConsoleTestCase extends \PHPUnit\Framework\TestCase
{
    use \Illuminate\Foundation\Testing\Concerns\InteractsWithConsole;

    public function consoleCompatibility(): void {}
}
