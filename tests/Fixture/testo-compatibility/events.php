<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility;

function event(string $name, array $values = []): void
{
    if (($file = getenv('LARATESTO_COMPAT_EVENTS')) !== false) {
        file_put_contents($file, json_encode(['event' => $name] + $values, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
