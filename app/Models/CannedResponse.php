<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Textbaustein für Antworten, entweder für einen Mandanten oder (ohne Mandant) für alle. */
class CannedResponse extends Model
{
    public const PLACEHOLDERS = '{name}, {nummer}, {betreff}, {mitarbeiter}, {mandant}';

    protected $attributes = ['sort' => 100, 'active' => true];

    protected $fillable = ['tenant_id', 'title', 'body', 'sort', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort' => 'integer'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where(fn (Builder $query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId));
    }

    /** Text mit ausgefüllten Platzhaltern für dieses Ticket */
    public function renderFor(Ticket $ticket, ?User $agent): string
    {
        return strtr($this->body, [
            '{name}' => (string) $ticket->contact?->name,
            '{nummer}' => $ticket->reference(),
            '{betreff}' => (string) $ticket->subject,
            '{mitarbeiter}' => (string) $agent?->name,
            '{mandant}' => (string) $ticket->tenant?->name,
        ]);
    }
}
