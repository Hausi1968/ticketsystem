<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'ticket_message_id', 'filename', 'mime_type', 'size', 'disk', 'path', 'content_id', 'inline', 'sha256'];

    protected function casts(): array
    {
        return ['inline' => 'boolean', 'size' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleted(fn (Attachment $a) => Storage::disk($a->disk)->delete($a->path));
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id');
    }

    public function contents(): ?string
    {
        return Storage::disk($this->disk)->get($this->path);
    }

    public function humanSize(): string
    {
        $size = $this->size;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($size < 1024 || $unit === 'GB') {
                return ($unit === 'B' ? $size : number_format($size, 1, ',', '.')).' '.$unit;
            }
            $size /= 1024;
        }

        return '';
    }
}
