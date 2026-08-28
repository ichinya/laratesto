<?php

declare(strict_types=1);

namespace Laratesto\Attribute;

use Laratesto\Pipeline\RefreshDatabaseInterceptor;
use Testo\Pipeline\Attribute\FallbackInterceptor;
use Testo\Pipeline\Attribute\Interceptable;

/**
 * Migrate once and wrap each selected connection in a transaction per test.
 *
 * ```php
 * #[RefreshDatabase]
 * public function testDashboardShowsUsers(): void { ... }
 *
 * #[RefreshDatabase(seed: true)]
 * public function testReportContainsSeedData(): void { ... }
 * ```
 *
 * Use {@see DatabaseTransactions} when migrations are slow and the schema
 * can be shared between tests.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_CLASS)]
#[FallbackInterceptor(RefreshDatabaseInterceptor::class)]
final readonly class RefreshDatabase implements Interceptable
{
    public function __construct(
        /**
         * Run the database seeder after migrating.
         */
        public bool $seed = false,
        public ?string $seeder = null,
        public bool $dropViews = false,
        public bool $dropTypes = false,
        /** @var list<non-empty-string|null>|null */
        public ?array $connections = null,
    ) {}
}
