<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility\InlineCallbacks;

use Testo\Assert;
use Testo\Inline\TestInline;
use function Laratesto\Tests\Fixture\Compatibility\event;

#[TestInline([42], static function (array $result) { Assert::same([42], $result); Assert::true($result[0] > 0); })]
#[TestInline([0], static function (array $result) { Assert::true(false, 'callback negative control'); })]
function wrapped(int $value): array
{
    event('body', ['owner' => app('compatibility.owner')]);
    if (app()->bound('inline.leak')) { throw new \RuntimeException('Callback application was reused'); }
    app()->instance('inline.leak', true);
    return [$value];
}
