<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/ping', static fn () => response()->json(['laravel' => 'ok']));

Route::post('/echo', static function (\Illuminate\Http\Request $request) {
    return response()->json([
        'received' => $request->input('message'),
        'content_type' => $request->header('Content-Type'),
    ]);
});
