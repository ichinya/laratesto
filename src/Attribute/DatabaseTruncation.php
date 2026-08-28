<?php

declare(strict_types=1);

namespace Laratesto\Attribute;

use Laratesto\Pipeline\DatabaseTruncationInterceptor;
use Testo\Pipeline\Attribute\FallbackInterceptor;
use Testo\Pipeline\Attribute\Interceptable;

/**
 * Truncate the database tables before the test instead of re-running migrations.
 *
 * ```php
 * #[DatabaseTruncation]
 * public function testReportListsUsers(): void { ... }
 *
 * #[DatabaseTruncation(connections: ['replica'], tables: ['users', 'orders'])]
 * public function testOrdersAreRebuilt(): void { ... }
 * ```
 *
 * The Testo counterpart of Laravel's PHPUnit
 * `Illuminate\Foundation\Testing\DatabaseTruncation` trait. When the schema is not
 * there yet (e.g. an in-memory database), it runs `migrate:fresh` once and truncates
 * nothing — mirroring the trait's first-run behaviour; afterwards every test starts
 * from truncated tables, which is faster than {@see RefreshDatabase}.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_CLASS)]
#[FallbackInterceptor(DatabaseTruncationInterceptor::class)]
final readonly class DatabaseTruncation implements Interceptable
{
    /**
     * @param bool $seed Run the database seeder after truncating.
     * @param string|null $seeder Use a specific seeder class instead of the default one.
     * @param list<non-empty-string|null>|null $connections Connections to truncate.
     * @param list<non-empty-string>|array<string, list<non-empty-string>>|null $tables
     * @param list<non-empty-string>|array<string, list<non-empty-string>>|null $exceptTables
     * @param bool $dropViews Drop views during the initial migrate:fresh, when it runs.
     * @param bool $dropTypes Drop types during the initial migrate:fresh, when it runs.
     */
    public function __construct(
        public bool $seed = false,
        public ?string $seeder = null,
        public bool $dropViews = false,
        public bool $dropTypes = false,
        public ?array $connections = null,
        public ?array $tables = null,
        public ?array $exceptTables = null,
    ) {}
}
