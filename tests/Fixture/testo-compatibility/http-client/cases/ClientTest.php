<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\HttpClient;

use Laratesto\Laratesto;
use Laratesto\Testing\HttpClient;
use Laratesto\Testing\InteractsWithLaravel;
use Laratesto\Testing\LaravelResponse;
use Laratesto\Testing\LaravelTestCase;
use Testo\Assert;
use Testo\Test;

#[Test]
function plainFunction(): void
{
    State::fresh();
    Assert::same(getenv('LARATESTO_EXPECT_PHPUNIT') === '1', \Composer\InstalledVersions::isInstalled('phpunit/phpunit'));
    \Laratesto\Testing\PhpUnitCompatibility::run(static fn() => \PHPUnit\Framework\Assert::assertTrue(true));
    Laratesto::http()->postJson('/client-probe', ['value' => 42])->assertOk()->assertJsonPath('method', 'POST')->assertJsonPath('payload.value', 42);
    foreach (['post' => 'POST', 'put' => 'PUT', 'patch' => 'PATCH', 'delete' => 'DELETE'] as $method => $verb) {
        Laratesto::http()->$method('/client-probe', ['value' => $verb])->assertOk()->assertJsonPath('method', $verb)->assertJsonPath('payload.value', $verb);
    }
    Laratesto::http()->deleteJson('/client-probe', ['value' => 7])->assertJsonPath('payload.value', 7);
    Laratesto::http()->call('GET', '/client-probe', ['value' => 'low-level'])->assertJsonPath('payload.value', 'low-level');
}

#[Test]
final class BaseCase extends LaravelTestCase
{
    private int $requests = 0;

    protected function setUpLaravel(): void { State::fresh(); }
    protected function tearDownLaravel(): void { Assert::same(Laratesto::http(), $this->app()->make(HttpClient::class)); }

    public function mixedState(): void
    {
        Assert::same($this, $this->withHeader('X-Client', 'legacy'));
        Laratesto::http()->getJson('/client-probe')->assertJsonPath('header', 'legacy');
        Laratesto::http()->withCookie('flavor', 'vanilla')->withSession(['marker' => 'shared'])->withServerVariables(['REMOTE_ADDR' => '192.0.2.1']);
        $this->getJson('/client-probe')->assertJsonPath('cookie', 'vanilla')->assertJsonPath('session', 'shared')->assertJsonPath('address', '192.0.2.1');
        $this->withCookie('flavor', 'legacy-cookie')->withSession(['marker' => 'legacy-session']);
        Laratesto::http()->getJson('/client-probe')->assertJsonPath('cookie', 'legacy-cookie')->assertJsonPath('session', 'legacy-session')->assertJsonPath('seen', 'saved');
        Laratesto::http()->withoutHeader('x-client');
        $this->getJson('/client-probe')->assertJsonPath('header', null);
    }

    public function overridesAlsoApplyToRedirectedLegacyRequests(): void
    {
        $before = $this->requests;
        $this->followingRedirects()->get('/client-redirect')->assertOk()->assertJsonPath('override', 'legacy');
        Assert::same(2, $this->requests - $before);
    }

    protected function sendRequest(string $method, string $uri, array $parameters = [], array $headers = [], ?string $content = null, array $cookies = [], array $files = [], array $server = []): LaravelResponse
    {
        ++$this->requests;
        return parent::sendRequest($method, $uri, $parameters, $headers + ['X-Override' => 'legacy'], $content, $cookies, $files, $server);
    }
}

#[Test]
final class TraitCase
{
    use InteractsWithLaravel;
    protected function setUpLaravel(): void { State::fresh(); }
    public function traitAndFacadeUseTheSameDefaults(): void
    {
        Laratesto::http()->withHeader('X-Client', 'facade');
        $this->getJson('/client-probe')->assertJsonPath('header', 'facade');
    }
}

#[Test]
final class ExecutionsTest
{
    private int $retries = 0;

    #[\Testo\Retry(maxAttempts: 2)]
    public function retry(): void
    {
        State::fresh();
        Laratesto::http()->withHeader('X-Client', 'retry');
        Assert::true(++$this->retries > 1, 'first attempt');
    }

    #[\Testo\Repeat(times: 2)]
    public function repeat(): void
    {
        State::fresh();
        Laratesto::http()->withHeader('X-Client', 'repeat');
    }

    #[\Testo\Fiber\RunInFiber]
    public function fiber(): void
    {
        State::fresh();
        $client = Laratesto::http();
        \Fiber::suspend();
        Assert::same($client, Laratesto::http());
        $client->getJson('/client-probe')->assertOk();
    }
}
