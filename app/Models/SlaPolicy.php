<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Sla\BusinessHours;
use App\Sla\GermanHolidays;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fristen für Tickets eines Mandanten. Gilt die erste aktive Richtlinie (nach Reihenfolge),
 * deren Bedingungen (Team, Eingangskanal) zum Ticket passen.
 */
class SlaPolicy extends Model
{
    use BelongsToTenant;

    public const METRICS = [
        'first_response' => 'Erstreaktion',
        'next_response' => 'Folgereaktion',
        'resolution' => 'Lösung',
    ];

    public const CHANNELS = ['email' => 'E-Mail', 'api' => 'Anwendung (API)'];

    /** Vorschlag für neue Richtlinien, in Minuten (Geschäftszeit) */
    public const DEFAULT_TARGETS = [
        'urgent' => ['first_response' => 60, 'next_response' => 60, 'resolution' => 240],
        'high' => ['first_response' => 240, 'next_response' => 240, 'resolution' => 540],
        'normal' => ['first_response' => 480, 'next_response' => 480, 'resolution' => 1620],
        'low' => ['first_response' => 1080, 'next_response' => 1080, 'resolution' => 5400],
    ];

    public const DEFAULT_HOURS = [
        1 => ['open' => true, 'from' => '08:00', 'to' => '17:00'],
        2 => ['open' => true, 'from' => '08:00', 'to' => '17:00'],
        3 => ['open' => true, 'from' => '08:00', 'to' => '17:00'],
        4 => ['open' => true, 'from' => '08:00', 'to' => '17:00'],
        5 => ['open' => true, 'from' => '08:00', 'to' => '17:00'],
        6 => ['open' => false, 'from' => '09:00', 'to' => '13:00'],
        7 => ['open' => false, 'from' => '09:00', 'to' => '13:00'],
    ];

    protected $attributes = ['active' => true, 'sort' => 100, 'warn_percent' => 75, 'all_day' => false];

    protected $fillable = [
        'tenant_id', 'name', 'active', 'sort', 'team_id', 'channel', 'targets', 'all_day', 'business_hours',
        'holiday_region', 'extra_holidays', 'warn_percent',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'all_day' => 'boolean',
            'targets' => 'array',
            'business_hours' => 'array',
            'extra_holidays' => 'array',
            'warn_percent' => 'integer',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Gelöschte Richtlinie: laufende Fristen der Tickets entfallen (sonst würde weiter erinnert)
        static::deleting(fn (SlaPolicy $policy) => $policy->tickets()->update([
            'sla' => null, 'sla_due_at' => null, 'sla_next_check_at' => null,
        ]));
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }

    /** Die passende Richtlinie für ein (neues) Ticket */
    public static function forTicket(Ticket $ticket): ?self
    {
        return static::query()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('active', true)
            ->ordered()
            ->get()
            ->first(fn (self $policy) => $policy->matches($ticket));
    }

    public function matches(Ticket $ticket): bool
    {
        return ($this->team_id === null || $this->team_id === $ticket->team_id)
            && ($this->channel === null || $this->channel === $ticket->channel);
    }

    /** Ziel in Minuten oder null (für diese Priorität nicht gemessen) */
    public function target(TicketPriority|string|null $priority, string $metric): ?int
    {
        $priority = $priority instanceof TicketPriority ? $priority->value : ($priority ?: 'normal');
        $value = $this->targets[$priority][$metric] ?? null;

        return is_numeric($value) && $value > 0 ? (int) $value : null;
    }

    public function warnPercent(): int
    {
        return max(1, min(99, $this->warn_percent ?: 75));
    }

    public function calendar(): BusinessHours
    {
        $timezone = $this->tenant?->timezone ?: 'Europe/Berlin';

        if ($this->all_day) {
            return BusinessHours::allDay($timezone);
        }

        $week = [];
        foreach ($this->business_hours ?? self::DEFAULT_HOURS as $day => $hours) {
            if (! empty($hours['open']) && ! empty($hours['from']) && ! empty($hours['to'])) {
                $week[(int) $day] = [substr((string) $hours['from'], 0, 5), substr((string) $hours['to'], 0, 5)];
            }
        }

        if ($week === []) {
            return BusinessHours::allDay($timezone);
        }

        return new BusinessHours($timezone, $week, fn (int $year) => $this->holidaysIn($year));
    }

    /** @return array<string, true> */
    public function holidaysIn(int $year): array
    {
        $days = $this->holiday_region ? array_fill_keys(array_keys(GermanHolidays::for($year, $this->holiday_region)), true) : [];

        foreach ($this->extra_holidays ?? [] as $entry) {
            if ($date = self::parseHoliday((string) $entry, $year)) {
                $days[$date] = true;
            }
        }

        return $days;
    }

    /** "24.12." (jedes Jahr) oder "02.01.2027" (einmalig) → 'Y-m-d' im gewünschten Jahr, sonst null */
    public static function parseHoliday(string $entry, int $year): ?string
    {
        if (! preg_match('/^\s*(\d{1,2})\.(\d{1,2})\.(\d{4})?\s*$/', $entry, $m)) {
            return null;
        }

        $y = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $year;
        if ($y !== $year || ! checkdate((int) $m[2], (int) $m[1], $y)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]);
    }
}
