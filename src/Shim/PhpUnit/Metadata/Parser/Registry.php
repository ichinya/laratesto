<?php

declare(strict_types=1);

namespace PHPUnit\Metadata\Parser;

use PHPUnit\Metadata\MetadataCollection;

/**
 * Stub for PHPUnit metadata parser registry.
 */
final class Registry
{
    private static ?Parser $instance = null;

    public static function parser(): Parser
    {
        return self::$instance ?? self::$instance = new class implements Parser
        {
            public function forClass(string $className): MetadataCollection
            {
                return MetadataCollection::forClass($className);
            }

            public function forMethod(string $className, string $methodName): MetadataCollection
            {
                return new MetadataCollection([]);
            }

            public function forClassAndMethod(string $className, string $methodName): MetadataCollection
            {
                return $this->forClass($className);
            }
        };
    }
}
