<?php

declare(strict_types=1);

namespace Laratesto\Attribute;

use Laratesto\Pipeline\DatabaseMigrationsInterceptor;
use Testo\Pipeline\Attribute\FallbackInterceptor;
use Testo\Pipeline\Attribute\Interceptable;

/**
 * Run fresh migrations before the test and roll them back afterwards.
 *
 * This is the Testo counterpart of Laravel's PHPUnit
 * `Illuminate\Foundation\Testing\DatabaseMigrations` trait.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_CLASS)]
#[FallbackInterceptor(DatabaseMigrationsInterceptor::class)]
final readonly class DatabaseMigrations implements Interceptable
{
    public function __construct(
        public bool $seed = false,
        public ?string $seeder = null,
        public bool $dropViews = false,
        public bool $dropTypes = false,
    ) {}
}
