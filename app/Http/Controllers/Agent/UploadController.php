<?php

namespace App\Http\Controllers\Agent;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Dateien für Antworten/Notizen hochladen und Anhänge herunterladen */
class UploadController
{
    public static function dir(User $user): string
    {
        return 'uploads-tmp/agent-'.$user->getKey();
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480']]);
        $file = $request->file('file');
        $id = (string) Str::uuid();
        $path = self::dir($request->user()).'/'.$id;

        Storage::disk('local')->putFileAs(self::dir($request->user()), $file, $id);
        Storage::disk('local')->put($path.'.json', json_encode([
            'name' => Str::limit($file->getClientOriginalName() ?: 'datei', 200, ''),
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
        ]));

        return response()->json(['id' => $id, 'name' => $file->getClientOriginalName(), 'size' => $file->getSize()], 201);
    }

    public function attachment(int $attachment): StreamedResponse
    {
        $file = Attachment::query()->findOrFail($attachment); // Mandantenfilter greift

        return Storage::disk($file->disk)->download($file->path, $file->filename, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
