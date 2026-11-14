<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ApiUpload extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'api_key_id', 'filename', 'mime_type', 'size', 'path', 'sha256'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime', 'size' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (ApiUpload $u) => $u->public_id ??= 'att_'.Str::lower(Str::ulid()));
    }

    /** Hängt die Datei an eine Nachricht (Datei wird verschoben, nicht kopiert). */
    public function attachTo(TicketMessage $message): Attachment
    {
        $target = sprintf('tenants/%d/attachments/%s/%s', $this->tenant_id, now()->format('Y/m'), Str::uuid());
        Storage::disk('local')->move($this->path, $target);

        $attachment = $message->attachments()->create([
            'tenant_id' => $this->tenant_id,
            'filename' => $this->filename,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'disk' => 'local',
            'path' => $target,
            'sha256' => $this->sha256,
        ]);

        $this->forceFill(['used_at' => now(), 'path' => $target])->save();

        return $attachment;
    }
}
