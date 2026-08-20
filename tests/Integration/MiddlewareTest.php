<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use App\Http\Middleware\SmokeGuard;
use Laratesto\Testing\LaravelTestCase;

final class MiddlewareTest extends LaravelTestCase
{
    public function testMiddlewareRunsByDefault(): void
    {
        $this->get('/guarded')->assertStatus(403);
    }

    public function testWithoutMiddlewareDisablesSpecificMiddleware(): void
    {
        $this->withoutMiddleware(SmokeGuard::class);

        $this->get('/guarded')
            ->assertOk()
            ->assertJson(['passed' => true]);
    }

    public function testWithoutMiddlewareDisablesAllMiddleware(): void
    {
        $this->withoutMiddleware();

        $this->get('/guarded')
            ->assertOk()
            ->assertJson(['passed' => true]);
    }
}
