<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->file(public_path('docs/index.html'));
});

// Swagger UI Documentation Routes
Route::get('/swagger', function () {
    return response()->file(public_path('docs/index.html'));
});

Route::get('/docs/api', function () {
    return response()->file(public_path('docs/index.html'));
});

Route::get('/api/documentation', function () {
    return response()->file(public_path('docs/index.html'));
});

Route::get('/docs/openapi.json', function () {
    return response()->file(public_path('docs/openapi.json'), [
        'Content-Type' => 'application/json',
        'Access-Control-Allow-Origin' => '*',
    ]);
});

