<?php

use App\Http\Controllers\Agent\AuthController;
use App\Http\Controllers\Agent\OptionsController;
use App\Http\Controllers\Agent\TicketController;
use App\Http\Controllers\Agent\UploadController;
use App\Http\Middleware\AuthenticateAgentToken;
use Illuminate\Support\Facades\Route;

// Schnittstelle der Mitarbeiter-App (Android). Anleitung: docs/agent-api.md
Route::prefix('api/agent/v1')->middleware(['api'])->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::post('login/mfa', [AuthController::class, 'verifyMfa'])->middleware('throttle:20,1');

    Route::middleware([AuthenticateAgentToken::class, 'throttle:anliego-agent'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('push-token', [AuthController::class, 'pushToken']);
        Route::get('options', OptionsController::class);
        Route::get('tickets', [TicketController::class, 'index']);
        Route::get('tickets/{ticket}', [TicketController::class, 'show'])->whereNumber('ticket');
        Route::post('tickets/{ticket}/reply', [TicketController::class, 'reply'])->whereNumber('ticket');
        Route::post('tickets/{ticket}/note', [TicketController::class, 'note'])->whereNumber('ticket');
        Route::post('tickets/{ticket}/{action}', [TicketController::class, 'update'])->whereNumber('ticket')
            ->whereIn('action', ['take', 'assign', 'status', 'category', 'priority', 'time']);
        Route::post('uploads', [UploadController::class, 'store']);
        Route::get('attachments/{attachment}', [UploadController::class, 'attachment'])->whereNumber('attachment');
    });
});
