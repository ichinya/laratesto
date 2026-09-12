<?php

declare(strict_types=1);

namespace PHPUnit\TextUI\Configuration;

/**
 * Stub for PHPUnit TextUI configuration builder.
 */
final class Builder
{
    /**
     * @param list<string> $arguments
     */
    public function build(array $arguments): Configuration
    {
        return new Configuration();
    }
}
