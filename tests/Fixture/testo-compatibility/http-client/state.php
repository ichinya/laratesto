<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\HttpClient;

use Illuminate\Http\Request;
use Laratesto\Laratesto;
use Laratesto\Testing\HttpClient;
use Testo\Assert;

final class State
{
    public static array $clients = [];

    public static function fresh(): void
    {
        foreach (self::$clients as $old) {
            try {
                $old->withHeader('X-Stale', 'bad');
                Assert::fail('An old execution client must be revoked.');
            } catch (\LogicException $failure) {
                Assert::string($failure->getMessage())->contains('no longer active');
            }
        }
        $client = Laratesto::http();
        Assert::same($client, app(HttpClient::class));
        self::$clients[] = $client;
        app('router')->any('/client-probe', static fn(Request $request) => response()->json([
            'header' => $request->header('X-Client'), 'override' => $request->header('X-Override'),
            'cookie' => $request->cookie('flavor'), 'seen' => $request->cookie('seen'),
            'session' => $request->session()->get('marker'), 'address' => $request->server('REMOTE_ADDR'),
            'method' => $request->method(), 'payload' => $request->all(), 'application' => config('app.name'),
        ])->cookie('seen', 'saved'))->middleware('web');
        app('router')->get('/client-redirect', static fn() => redirect('/client-probe'));
        $client->getJson('/client-probe')->assertJsonPath('header', null)->assertJsonPath('cookie', null)->assertJsonPath('session', null);
    }
}
