<?php

use App\Http\Controllers\DeployController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

// Die Oberfläche (Filament) liegt direkt unter "/". Hier nur technische Routen.

Route::post('/_deploy/migrate', DeployController::class)
    ->middleware('throttle:5,1')
    ->withoutMiddleware(VerifyCsrfToken::class);
