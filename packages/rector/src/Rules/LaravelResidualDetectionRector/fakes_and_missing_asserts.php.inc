<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

final class SignupTest extends TestCase
{
    public function test_signup_sends_mail(): void
    {
        Mail::fake();
        Bus::fake();

        $this->withoutExceptionHandling();

        $this->postJson('/signup', ['email' => 'a@b.c'])
            ->assertStatus(201)
            ->assertJsonFragment(['email' => 'a@b.c']);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

/* laratesto-residual(code=RESPONSE_UNSUPPORTED_API, rule=Laratesto\Rector\Rules\LaravelResidualDetectionRector, severity=manual): assertJsonFragment() — no automatic conversion; migrate manually */
final class SignupTest extends TestCase
{
    public function test_signup_sends_mail(): void
    {
        Mail::fake();
        Bus::fake();

        $this->withoutExceptionHandling();

        $this->postJson('/signup', ['email' => 'a@b.c'])
            ->assertStatus(201)
            ->assertJsonFragment(['email' => 'a@b.c']);
    }
}
