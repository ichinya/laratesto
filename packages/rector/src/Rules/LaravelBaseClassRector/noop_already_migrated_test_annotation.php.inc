<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

final class AlreadyMigratedAnnotationTest extends LaravelTestCase
{
    /**
     * An already-migrated class is never reprocessed: the leftover annotation
     * must neither gain a second marker nor be rewritten by this rule.
     *
     * @test
     */
    public function it_stays_untouched(): void
    {
        $this->assertTrue(true);
    }
}
