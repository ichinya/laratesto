<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

trait LogsQuietly
{
    protected string $channel = 'test';

    public function log(string $message): void
    {
        echo $message;
    }
}

final class AliasAdaptationTest extends TestCase
{
    use LogsQuietly {
        log as private write;
    }

    public function test_ok(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

trait LogsQuietly
{
    protected string $channel = 'test';

    public function log(string $message): void
    {
        echo $message;
    }
}

/* laratesto-residual(code=CLASS_UNSAFE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): trait adaptations cannot be preserved by automatic class conversion */
final class AliasAdaptationTest extends TestCase
{
    use LogsQuietly {
        log as private write;
    }

    public function test_ok(): void {}
}
