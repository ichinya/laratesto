<?php

declare(strict_types=1);

namespace Laratesto\Testing\Internal;

/** @internal Uses the installed PHPUnit version's own stub generator. */
final class PhpUnitStubFactory extends \PHPUnit\Framework\TestCase
{
    public static function make(string $type): \PHPUnit\Framework\MockObject\Stub
    {
        return parent::createStub($type);
    }
}
