<?php

declare(strict_types=1);

namespace Laratesto\Testing\Internal;

/** @internal Preserve the source test class's return-value generation policy. */
#[\PHPUnit\Framework\Attributes\DisableReturnValueGenerationForTestDoubles]
final class PhpUnitStubWithoutReturnValuesFactory extends \PHPUnit\Framework\TestCase
{
    public static function make(string $type): \PHPUnit\Framework\MockObject\Stub
    {
        return parent::createStub($type);
    }
}
