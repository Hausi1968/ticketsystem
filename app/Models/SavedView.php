<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Gespeicherte Ansicht der Ticketliste; gehört genau einem Mitarbeiter. */
class SavedView extends Model
{
    protected $fillable = ['user_id', 'name', 'base_tab', 'filters', 'search', 'sort', 'position'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOwnedBy(Builder $query, User|int|null $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user)->orderBy('position')->orderBy('id');
    }

    public function tabKey(): string
    {
        return 'view_'.$this->getKey();
    }
}
