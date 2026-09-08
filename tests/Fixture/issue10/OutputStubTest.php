<?php

namespace Tests;

class StubService
{
    public function __construct() { throw new \RuntimeException('Constructor must not run'); }
    public function value(): string { throw new \RuntimeException('Original method must not run'); }
    public function count(): int { return 99; }
}

class MagicStubProvider
{
    public function __call(string $method, array $arguments): string { return 'custom stub'; }
    public function value(): string { return $this->createStub('domain value'); }
}

final class OutputStubTest extends \Illuminate\Foundation\Testing\TestCase
{
    public function createApplication()
    {
        $app = require getenv('LARATESTO_ISSUE10_APP').'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }

    protected function setUp(): void { parent::setUp(); echo 'setup:'; }
    protected function tearDown(): void { echo 'teardown'; parent::tearDown(); }

    public function testStubKeepsPhpUnitBehavior(): void
    {
        $this->assertSame('custom stub', (new MagicStubProvider())->value());
        $stub = $this->createStub(StubService::class);
        $stub->method('value')->willReturn('stubbed');
        $this->assertSame('stubbed', $stub->value());
        $this->assertSame(0, $stub->count());
        $stub->method('count')->willThrowException(new \RuntimeException('stub exception'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stub exception');
        $stub->count();
    }

    public function testNamedStubArgumentsAndBalancedOutputBuffers(): void
    {
        $stub = self::createStub(originalClassName: StubService::class);
        $this->assertSame(0, $stub->count());
        ob_start();
        echo 'nested';
        ob_end_flush();
        $this->expectOutputString(expectedString: 'setup:nested');
    }

    public function testOutputIncludesTextBeforeTheExpectation(): void
    {
        echo 'before';
        $this->expectOutputString('setup:beforeafter');
        echo 'after';
    }

    public function testWrongOutputFails(): void
    {
        $this->expectOutputString('setup:right');
        echo 'wrong';
    }

    public function testFollowingOutputExpectationStartsFresh(): void
    {
        $this->expectOutputString('setup:');
    }
}
