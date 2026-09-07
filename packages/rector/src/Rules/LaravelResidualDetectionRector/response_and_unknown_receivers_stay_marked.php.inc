<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse as ResponseAlias;

final class UncertainReceiverTest extends TestCase
{
    public function unknown($value): void
    {
        $value->assertDownload();
    }

    public function response(ResponseAlias $value): void
    {
        $alias = $value;
        $alias->assertDownload();
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse as ResponseAlias;

/* laratesto-residual(code=RESPONSE_UNSUPPORTED_API, rule=Laratesto\Rector\Rules\LaravelResidualDetectionRector, severity=manual): assertDownload() — no automatic conversion; migrate manually */
final class UncertainReceiverTest extends TestCase
{
    public function unknown($value): void
    {
        $value->assertDownload();
    }

    public function response(ResponseAlias $value): void
    {
        $alias = $value;
        $alias->assertDownload();
    }
}
