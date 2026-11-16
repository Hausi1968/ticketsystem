<?php

use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\ErrorReportController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

// Ticket-Schnittstelle für Anwendungen. Anleitung: docs/api.md
Route::prefix('v1')->middleware([AuthenticateApiKey::class, 'throttle:anliego-api'])->group(function () {
    Route::post('attachments', [AttachmentController::class, 'store']);
    Route::post('tickets', [TicketController::class, 'store']);
    Route::get('tickets', [TicketController::class, 'index']);
    Route::get('tickets/{reference}', [TicketController::class, 'show']);
    Route::post('tickets/{reference}/messages', [TicketController::class, 'reply']);
    Route::post('errors', [ErrorReportController::class, 'store']);
});
