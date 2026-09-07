<?php

declare(strict_types=1);

namespace Tests\Feature\Parity;

use Testo\Assert;
use Testo\Lifecycle\AfterClass;
use Tests\TestCase;

/**
 * Ticket 07 supported corpus: annotation-based discovery probe. Both test methods
 * carry a docblock test annotation on a non-test name, so the only route to
 * Testo discovery is the migrated Testo test attribute — the parity run below
 * fails unless every annotated method was found and executed after conversion.
 */
final class TestAnnotationDiscoveryTest extends TestCase
{
    public static int $executedMethods = 0;

    /**
     * The annotation probe keeps the project base alive.
     *
     * @test
     */
    public function it_runs_the_annotated_method(): void
    {
        self::$executedMethods++;

        $this->assertTrue(true);
    }

    /** @test */
    public function annotated_and_prefixed(): void
    {
        self::$executedMethods++;

        $this->assertSame('2026-01-01', $this->app->make('parity.clock')->format('Y-m-d'));
    }

    #[AfterClass]
    public static function assertAnnotationTestsExecuted(): void
    {
        // Discovery regression: BOTH annotated methods must have been found and
        // executed by Testo — a surviving annotation without the attribute would
        // discover neither, and a partial discovery trips the counter.
        Assert::same(2, self::$executedMethods);
    }
}
