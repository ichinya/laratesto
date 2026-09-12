<?php

declare(strict_types=1);

namespace PHPUnit\Metadata\Parser;

use PHPUnit\Metadata\MetadataCollection;

/**
 * Stub for PHPUnit metadata parser.
 */
interface Parser
{
    /**
     * @param class-string $className
     */
    public function forClass(string $className): MetadataCollection;

    /**
     * @param class-string $className
     */
    public function forMethod(string $className, string $methodName): MetadataCollection;

    /**
     * @param class-string $className
     */
    public function forClassAndMethod(string $className, string $methodName): MetadataCollection;
}
