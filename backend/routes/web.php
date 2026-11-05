<?php

use Illuminate\Support\Facades\Route;

// SiteX Post Cloud is API-only; the SPAs are served separately (see DEPLOYMENT.md).
Route::get('/', fn () => response()->json([
    'name' => 'SiteX Post Cloud API',
    'docs' => 'docs/API-CONTRACTS.md',
]));
