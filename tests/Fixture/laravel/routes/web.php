<?php

declare(strict_types=1);

use App\Http\Middleware\SmokeGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/session/write', static function () {
    session(['key' => 'value-from-session']);

    return response()->json(['stored' => session('key')]);
});

Route::get('/session/read', static function () {
    return response()->json(['key' => session('key')]);
});

Route::get('/session/seeded', static function () {
    return response()->json(['seeded' => session('seeded')]);
});

Route::get('/session/redirect', static function () {
    return redirect('/redirect/end')->with('notice', 'saved');
});

Route::get('/redirect/start', static function () {
    return redirect('/redirect/end');
});

Route::get('/redirect/end', static function () {
    return response()->json(['redirected' => true]);
});

Route::get('/cookie/read', static function (Request $request) {
    return response()->json(['cookie' => $request->cookie('manual')]);
});

Route::get('/headers', static function (Request $request) {
    return response()->json(['x-test-header' => $request->header('X-Test-Header')]);
});

Route::delete('/api/delete', static function (Request $request) {
    return response()->json(['deleted' => $request->input('id')]);
});

Route::get('/view', static function () {
    return response()->view('test', ['value' => 'bound']);
});

Route::post('/session/flash', static function (Request $request) {
    $request->validate(['email' => 'required|email']);

    return response()->json(['ok' => true]);
})->name('session.flash');

Route::middleware('auth')->get('/auth/me', static function () {
    return response()->json(['id' => auth()->id()]);
});

Route::middleware(SmokeGuard::class)->get('/guarded', static function () {
    return response()->json(['passed' => true]);
});
