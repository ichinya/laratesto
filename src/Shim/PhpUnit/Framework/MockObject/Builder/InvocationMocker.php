<?php

declare(strict_types=1);

namespace PHPUnit\Framework\MockObject\Builder;

/**
 * Minimal invocation mocker for the PHPUnit stub API.
 */
final class InvocationMocker
{
    /**
     * @param \Mockery\MockInterface $mock
     */
    public function __construct(
        private readonly object $mock,
        private readonly string $method,
    ) {
    }

    /**
     * @param list<mixed> $arguments
     */
    public function with(...$arguments): self
    {
        return $this;
    }

    public function willReturn(mixed $value, mixed ...$nextValues): self
    {
        $all = [$value, ...$nextValues];

        if ($this->mock instanceof \Mockery\MockInterface) {
            $expectation = $this->mock->shouldReceive($this->method);
            $expectation->andReturn(...$all);
        }

        return $this;
    }

    public function willThrowException(\Throwable $exception): self
    {
        if ($this->mock instanceof \Mockery\MockInterface) {
            $this->mock->shouldReceive($this->method)->andThrow($exception);
        }

        return $this;
    }

    /**
     * @param array<array{0: list<mixed>, 1: mixed}> $valueMap
     */
    public function willReturnValueMap(array $valueMap): self
    {
        if ($this->mock instanceof \Mockery\MockInterface) {
            $expectation = $this->mock->shouldReceive($this->method);

            foreach ($valueMap as [$arguments, $return]) {
                $expectation->with(...$arguments)->andReturn($return);
            }
        }

        return $this;
    }

    /**
     * @param list<mixed> $arguments
     */
    public function willReturnArgument(int $argumentIndex): self
    {
        if ($this->mock instanceof \Mockery\MockInterface) {
            $this->mock->shouldReceive($this->method)->andReturnUsing(
                static fn (mixed ...$arguments) => $arguments[$argumentIndex] ?? null,
            );
        }

        return $this;
    }

    public function willReturnSelf(): self
    {
        if ($this->mock instanceof \Mockery\MockInterface) {
            $this->mock->shouldReceive($this->method)->andReturn($this->mock);
        }

        return $this;
    }

    public function willReturnReference(mixed &$reference): self
    {
        if ($this->mock instanceof \Mockery\MockInterface) {
            $this->mock->shouldReceive($this->method)->andReturn($reference);
        }

        return $this;
    }

    /**
     * @param callable(mixed ...$args): mixed $callback
     */
    public function willReturnCallback(callable $callback): self
    {
        if ($this->mock instanceof \Mockery\MockInterface) {
            $this->mock->shouldReceive($this->method)->andReturnUsing($callback);
        }

        return $this;
    }

    public function willReturnOnConsecutiveCalls(mixed ...$values): self
    {
        if ($this->mock instanceof \Mockery\MockInterface) {
            $this->mock->shouldReceive($this->method)->andReturn(...$values);
        }

        return $this;
    }
}
