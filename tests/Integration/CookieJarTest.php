<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use Laratesto\Testing\LaravelTestCase;

final class CookieJarTest extends LaravelTestCase
{
    public function testCookiesAreForwardedBetweenRequests(): void
    {
        $this->get('/session/write')->assertOk();

        $this->get('/session/read')
            ->assertOk()
            ->assertJson(['key' => 'value-from-session']);
    }

    public function testManySequentialRequestsKeepTheSessionAlive(): void
    {
        $this->get('/session/write')->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->get('/session/read')
                ->assertOk()
                ->assertJson(['key' => 'value-from-session']);
        }
    }
}
