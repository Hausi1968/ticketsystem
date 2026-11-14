<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Liefert Anhänge aus. Der Mandantenfilter greift automatisch: Wer den Mandanten
 * nicht betreut, bekommt 404.
 */
class AttachmentController
{
    /** Bildtypen, die im Browser angezeigt werden dürfen (kein SVG – das kann Skripte enthalten). */
    private const INLINE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'];

    public function __invoke(Request $request, int $attachment): StreamedResponse
    {
        $file = Attachment::query()->findOrFail($attachment);
        $inline = $request->boolean('inline') && in_array($file->mime_type, self::INLINE_TYPES, true);

        $headers = [
            'Content-Type' => $inline ? $file->mime_type : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
        ];

        return $inline
            ? Storage::disk($file->disk)->response($file->path, $file->filename, $headers, 'inline')
            : Storage::disk($file->disk)->download($file->path, $file->filename, $headers);
    }
}
