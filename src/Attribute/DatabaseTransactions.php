<?php

declare(strict_types=1);

namespace Testo\Bridge\Laravel\Attribute;

use Testo\Bridge\Laravel\Pipeline\DatabaseTransactionsInterceptor;
use Testo\Pipeline\Attribute\FallbackInterceptor;
use Testo\Pipeline\Attribute\Interceptable;

/**
 * Wrap the test in a database transaction that is rolled back afterwards.
 *
 * ```php
 * #[DatabaseTransactions]
 * public function testCreatesUser(): void { ... }
 * ```
 *
 * Faster than {@see RefreshDatabase} because migrations are not re-run,
 * but requires the database schema to exist already.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_CLASS)]
#[FallbackInterceptor(DatabaseTransactionsInterceptor::class)]
final readonly class DatabaseTransactions implements Interceptable {}
