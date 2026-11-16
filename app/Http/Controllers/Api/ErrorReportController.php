<?php

namespace App\Http\Controllers\Api;

use App\Api\ErrorReportService;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ErrorReportController
{
    public function store(Request $request, ErrorReportService $service): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');

        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'type' => ['nullable', 'string', 'max:191'],
            'level' => ['nullable', Rule::in(['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'fatal'])],
            'fingerprint' => ['nullable', 'string', 'max:191'],
            'file' => ['nullable', 'string', 'max:500'],
            'line' => ['nullable', 'integer', 'min:0'],
            'url' => ['nullable', 'string', 'max:2000'],
            'method' => ['nullable', 'string', 'max:10'],
            'user' => ['nullable', 'string', 'max:191'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'php_version' => ['nullable', 'string', 'max:50'],
            'source' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'trace' => ['nullable', 'string', 'max:50000'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        [$ticket, $created] = $service->report($key, $data);

        return response()->json([
            'reference' => $ticket->reference(),
            'new_ticket' => $created,
            'count' => $ticket->error_count,
        ], $created ? 201 : 200);
    }
}
