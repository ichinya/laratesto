<?php

declare(strict_types=1);

namespace Tests\Feature\Parity\Unsupported;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Uri;
use Tests\TestCase;

/**
 * Ticket 07 unsupported corpus: every construct here must stay semantically
 * unchanged and carry exactly one residual marker (code from the compatibility
 * contract). It must never be migrated, partially converted or silently dropped.
 */
final class DynamicDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionsToTransact = [\env('PARITY_CONNECTION', 'sqlite')];
    }

    protected function beforeRefreshingDatabase(): void
    {
        \touch(\sys_get_temp_dir() . '/parity-refresh-marker');
    }

    public function test_dynamic_options_and_hooks(): void
    {
        $this->assertTrue(true);
    }
}

final class HttpSignatureEdgesTest extends TestCase
{
    public function test_fourth_options_argument(): void
    {
        $this->postJson('/parity/echo', ['a' => 1], [], ['timeout' => 5])->assertStatus(200);
    }

    public function test_uri_object_instead_of_string(): void
    {
        $this->get(Uri::of('/parity/ping'))->assertOk();
    }

    public function test_fake_and_unknown_response_api(): void
    {
        Http::fake(['*/parity/*' => Http::response(['ok' => true])]);

        $this->get('/parity/ping')
            ->assertDownload('report.csv');
    }
}

final class InteractiveArtisanTest extends TestCase
{
    public function test_expects_question_is_interactive(): void
    {
        $this->artisan('parity:ask')
            ->expectsQuestion('Your name', 'A')
            ->expectsOutputToContain('Hello, A')
            ->assertSuccessful();
    }
}
