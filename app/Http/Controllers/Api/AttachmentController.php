<?php

namespace App\Http\Controllers\Api;

use App\Models\ApiKey;
use App\Models\ApiUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Anhänge vorab hochladen; die ID wird dann beim Ticket mitgegeben. */
class AttachmentController
{
    public function store(Request $request): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');
        $maxKb = (int) config('anliego.api_max_upload_mb', 100) * 1024;

        $request->validate(['file' => ['required', 'file', "max:{$maxKb}"]]);
        $file = $request->file('file');

        $path = $file->storeAs(
            sprintf('tenants/%d/api-uploads', $key->tenant_id),
            (string) Str::uuid(),
            'local',
        );

        $upload = ApiUpload::create([
            'tenant_id' => $key->tenant_id,
            'api_key_id' => $key->getKey(),
            'filename' => mb_substr(str_replace(['/', '\\', "\0"], '_', $file->getClientOriginalName() ?: 'anhang'), 0, 200),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
            'sha256' => hash_file('sha256', $file->getRealPath()),
        ]);

        return response()->json([
            'id' => $upload->public_id,
            'filename' => $upload->filename,
            'size' => $upload->size,
            'mime_type' => $upload->mime_type,
        ], 201);
    }
}
