<?php

declare(strict_types=1);

namespace PHPUnit\Metadata;

/**
 * Stub for PHPUnit metadata collections.
 */
final class MetadataCollection implements \Countable
{
    /**
     * @param list<Metadata> $metadata
     */
    private function __construct(private readonly array $metadata)
    {
    }

    /**
     * @param class-string $className
     */
    public static function forClass(string $className): self
    {
        $metadata = [];

        $reflection = new \ReflectionClass($className);

        if (\count($reflection->getAttributes(\PHPUnit\Framework\Attributes\DisableReturnValueGenerationForTestDoubles::class)) > 0) {
            $metadata[] = new DisableReturnValueGenerationForTestDoubles();
        }

        return new self($metadata);
    }

    public function count(): int
    {
        return \count($this->metadata);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    public function isDisableReturnValueGenerationForTestDoubles(): self
    {
        return new self(\array_values(\array_filter(
            $this->metadata,
            static fn (Metadata $metadata): bool => $metadata->isDisableReturnValueGenerationForTestDoubles(),
        )));
    }
}
