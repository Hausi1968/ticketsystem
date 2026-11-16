<?php

namespace App\Models;

use App\Enums\TicketPriority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regel für neue Tickets: Wenn Bedingungen zutreffen, Team, Bearbeiter, Kategorie oder Priorität setzen.
 * Gilt für einen Mandanten oder (tenant_id = null) für alle.
 */
class TicketRule extends Model
{
    public const FIELDS = [
        'subject' => 'Betreff',
        'body' => 'Text',
        'from' => 'Absender-Adresse',
        'to' => 'Empfänger-Postfach',
        'channel' => 'Eingang (email / api)',
    ];

    public const OPERATORS = [
        'contains' => 'enthält',
        'not_contains' => 'enthält nicht',
        'equals' => 'ist genau',
        'starts_with' => 'beginnt mit',
        'ends_with' => 'endet mit',
    ];

    protected $fillable = [
        'tenant_id', 'name', 'match', 'conditions', 'team_id', 'assign', 'assignee_id', 'category_id', 'priority',
        'stop_processing', 'sort', 'active',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'priority' => TicketPriority::class,
            'stop_processing' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId));
    }

    /** @param  array<string, string>  $values  Feldname => Wert des Tickets */
    public function matches(array $values): bool
    {
        $results = array_map(fn (array $c) => self::check(
            mb_strtolower((string) ($values[$c['field'] ?? ''] ?? '')),
            (string) ($c['operator'] ?? 'contains'),
            mb_strtolower(trim((string) ($c['value'] ?? ''))),
        ), $this->conditions ?? []);

        if ($results === []) {
            return false;
        }

        return $this->match === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    private static function check(string $haystack, string $operator, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        return match ($operator) {
            'not_contains' => ! str_contains($haystack, $needle),
            'equals' => $haystack === $needle,
            'starts_with' => str_starts_with($haystack, $needle),
            'ends_with' => str_ends_with($haystack, $needle),
            default => str_contains($haystack, $needle),
        };
    }
}
