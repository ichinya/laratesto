<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use Illuminate\Foundation\Application as FoundationApplication;
use Testo\Assert;

function testGlobalHelpersWorkInFunctionStyleTests(): void
{
    Assert::instanceOf(app(), FoundationApplication::class);
    Assert::same(config('database.default'), 'sqlite');
}
