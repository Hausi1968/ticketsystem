<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ticketkategorie, entweder für einen Mandanten oder (ohne Mandant) für alle. */
class TicketCategory extends Model
{
    protected $attributes = ['color' => 'gray', 'sort' => 0, 'active' => true];

    protected $fillable = ['tenant_id', 'name', 'color', 'sort', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort' => 'integer'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'category_id');
    }

    /** Kategorien, die für Tickets dieses Mandanten in Frage kommen. */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where(fn (Builder $query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId));
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('name');
    }

    public function appliesTo(int $tenantId): bool
    {
        return $this->tenant_id === null || $this->tenant_id === $tenantId;
    }
}
