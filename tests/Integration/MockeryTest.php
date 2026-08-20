<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use Illuminate\Support\Facades\Log;
use Laratesto\Testing\LaravelTestCase;
use Mockery;
use Testo\Assert;

final class MockeryTest extends LaravelTestCase
{
    public function testFacadeMockExpectationsAreVerified(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('ping');

        Log::info('ping');

        // Mockery::close() runs automatically in the plugin's finally block
        // and verifies the expectation; an unmet expectation would error the test.
    }

    public function testMockeryContainerIsFreshAfterClose(): void
    {
        // Mockery::close() creates a new empty container (it does not set it
        // to null). A fresh container here proves the previous test's mock was
        // closed and its expectations were not leaked into this test.
        $container = Mockery::getContainer();

        Assert::notNull($container, 'Mockery must have a fresh container after the plugin closes the old one.');
        Assert::same($container->mockery_getExpectationCount(), 0, 'A fresh container must have no expectations.');
    }
}
