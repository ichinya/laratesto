<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/session/write', static function () {
    session(['key' => 'value-from-session']);

    return response()->json(['stored' => session('key')]);
});

Route::get('/session/read', static function () {
    return response()->json(['key' => session('key')]);
});

Route::post('/session/flash', static function (Request $request) {
    $request->validate(['email' => 'required|email']);

    return response()->json(['ok' => true]);
})->name('session.flash');

Route::middleware('auth')->get('/auth/me', static function () {
    return response()->json(['id' => auth()->id()]);
});
