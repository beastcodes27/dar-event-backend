<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'DarEvent API',
    'version' => '1.0.0',
    'docs' => 'See backend/README.md for the endpoint reference.',
]));
