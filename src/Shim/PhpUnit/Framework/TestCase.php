<?php

declare(strict_types=1);

namespace PHPUnit\Framework;

/**
 * Minimal PHPUnit TestCase shim.
 *
 * This class is only used when phpunit/phpunit is not installed. It provides
 * enough of the TestCase surface for Laravel's PendingCommand and the
 * InteractsWithConsole trait, and uses Mockery as a fallback for createStub().
 */
class TestCase
{
    /**
     * @var array<int, array{0: string, 1: mixed}>
     */
    public $expectedQuestions = [];

    /**
     * @var array<string, array{expected: mixed, strict: bool, actual?: mixed}>
     */
    public $expectedChoices = [];

    /**
     * @var list<string>
     */
    public $expectedOutput = [];

    /**
     * @var list<string>
     */
    public $expectedOutputSubstrings = [];

    /**
     * @var array<string, bool>
     */
    public $unexpectedOutput = [];

    /**
     * @var array<string, bool>
     */
    public $unexpectedOutputSubstrings = [];

    /**
     * @var list<mixed>
     */
    public $expectedTables = [];

    public $mockConsoleOutput = true;

    public $expectsOutput = null;

    private string $name = '';

    private int $assertionCount = 0;

    public function __construct(?string $name = null)
    {
        $this->name = $name ?? '';
    }

    public function fail(string $message = ''): never
    {
        throw new AssertionFailedError($message);
    }

    public function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        Assert::assertEquals($expected, $actual, $message);
        ++$this->assertionCount;
    }

    public function assertNotEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        Assert::assertNotEquals($expected, $actual, $message);
        ++$this->assertionCount;
    }

    public function getCount(): int
    {
        return $this->assertionCount;
    }

    public function addToAssertionCount(int $count): void
    {
        $this->assertionCount += $count;
    }

    /**
     * @param class-string $originalClassName
     * @return object&MockObject\Stub
     */
    public static function createStub(string $originalClassName): MockObject\Stub
    {
        if (!\class_exists(\Mockery::class)) {
            throw new \LogicException('createStub requires either phpunit/phpunit or mockery/mockery to be installed.');
        }

        $mock = \Mockery::mock($originalClassName . ', ' . MockObject\Stub::class);

        if (!self::returnValueGenerationIsDisabledForCaller()) {
            $mock->shouldIgnoreMissing();
        }

        $mock->shouldReceive('method')->andReturnUsing(
            static fn (string $method): MockObject\Builder\InvocationMocker => new MockObject\Builder\InvocationMocker($mock, $method),
        );

        return $mock;
    }

    private static function returnValueGenerationIsDisabledForCaller(): bool
    {
        $trace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        foreach ($trace as $frame) {
            if (!isset($frame['class'])) {
                continue;
            }

            $reflection = new \ReflectionClass($frame['class']);
            if (\count($reflection->getAttributes(\PHPUnit\Framework\Attributes\DisableReturnValueGenerationForTestDoubles::class)) > 0) {
                return true;
            }
        }

        return false;
    }
}
