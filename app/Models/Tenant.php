<?php

namespace App\Models;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use HasFactory;

    protected $attributes = ['status' => 'active', 'color' => '#4f46e5', 'timezone' => 'Europe/Berlin', 'locale' => 'de'];

    protected $fillable = ['name', 'slug', 'short_code', 'color', 'status', 'timezone', 'locale', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            $tenant->slug ??= Str::slug($tenant->name);
            $tenant->short_code = Str::upper($tenant->short_code);
        });
    }

    /** Nur Mandanten, auf die der aktuelle Benutzer Zugriff hat. */
    public function scopeAccessible(Builder $query): Builder
    {
        $ids = app(TenantContext::class)->accessibleTenantIds();

        return $ids === null ? $query : $query->whereIn($this->qualifyColumn('id'), $ids ?: [0]);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(TenantMembership::class)->withPivot('role')->withTimestamps();
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
