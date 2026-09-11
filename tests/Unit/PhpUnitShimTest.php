<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Testing\PhpUnitCompatibility;
use Testo\Assert;
use Testo\Test;

final class PhpUnitShimTest
{
    #[Test]
    public function shimProvidesPHPUnitAssertBackedByTesto(): void
    {
        Assert::same(0, \PHPUnit\Framework\Assert::getCount());

        \PHPUnit\Framework\Assert::assertTrue(true);
        \PHPUnit\Framework\Assert::assertSame('a', 'a');
        \PHPUnit\Framework\Assert::assertCount(2, [1, 2]);

        Assert::same(3, \PHPUnit\Framework\Assert::getCount());
    }

    #[Test]
    public function shimProvidesCreateStubWithoutPHPUnit(): void
    {
        $stub = PhpUnitCompatibility::createStub(StubService::class);

        $stub->method('value')->willReturn('stubbed');
        $stub->method('count')->willReturn(7);

        Assert::same('stubbed', $stub->value());
        Assert::same(7, $stub->count());

        $named = PhpUnitCompatibility::createStub(originalClassName: StubService::class);
        $named->method('value')->willReturn('named');
        Assert::same('named', $named->value());
    }

    #[Test]
    public function shimCreateStubCanThrowFromConfiguredMethod(): void
    {
        $stub = PhpUnitCompatibility::createStub(StubService::class);

        $stub->method('count')->willThrowException(new \RuntimeException('stub exception'));

        $caught = false;
        try {
            $stub->count();
        } catch (\RuntimeException $e) {
            $caught = $e->getMessage() === 'stub exception';
        }

        Assert::true($caught, 'Configured stub method must throw the configured exception.');
    }
}

class StubService
{
    public function __construct() {}
    public function value(): string { return 'original'; }
    public function count(): int { return 99; }
}
