<?php

namespace App\Sla;

use App\Enums\StatusBehaviour;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketStatus;
use Carbon\CarbonImmutable;

/**
 * Führt die Fristen eines Tickets. Wird beim Speichern des Tickets aufgerufen und reagiert auf
 * Änderungen: neues Ticket, Status (wartet = Lösungszeit pausiert, geschlossen = alles erledigt),
 * Priorität (Ziele neu), erste Antwort, Kunden- und Mitarbeiterantworten.
 *
 * Je Kennzahl steht in tickets.sla:
 *   target   Ziel in Minuten Geschäftszeit
 *   used     bereits verbrauchte Minuten (vor einer Pause)
 *   since    läuft seit (null = steht)
 *   paused   true, solange das Ticket wartet
 *   due_at / warn_at   vorausberechnete Zeitpunkte
 *   warned / breached  Warnung bzw. Überschreitung schon gemeldet
 *   met_at   erledigt am
 */
class SlaClock
{
    private const WATCHED = ['status_id', 'priority', 'first_response_at', 'last_customer_message_at', 'last_agent_message_at'];

    /** Neues Ticket: Fristen nach der passenden Richtlinie starten */
    public function creating(Ticket $ticket): void
    {
        $this->start($ticket, CarbonImmutable::now());
        $this->refreshColumns($ticket);
    }

    public function updating(Ticket $ticket): void
    {
        $now = CarbonImmutable::now();

        if (! $ticket->sla_policy_id || ! $ticket->isDirty(self::WATCHED)) {
            return;
        }

        // Nicht über die (evtl. ohne Mandantenkontext geladene) Beziehung, sondern frisch
        $policy = SlaPolicy::query()->find($ticket->sla_policy_id);
        if (! $policy) {
            return;
        }

        $state = $ticket->sla ?? [];
        $calendar = $policy->calendar();

        if ($ticket->isDirty('status_id')) {
            $state = $this->statusChanged($ticket, $policy, $calendar, $state, $now);
        }

        if ($ticket->isDirty('priority')) {
            foreach (array_keys(SlaPolicy::METRICS) as $metric) {
                if (isset($state[$metric]) && empty($state[$metric]['met_at'])) {
                    $state[$metric] = $this->retarget($state[$metric], $policy->target($ticket->priority, $metric), $policy, $calendar, $now);
                }
            }
            $state = array_filter($state);
        }

        if ($ticket->isDirty('first_response_at') && $ticket->first_response_at !== null) {
            $state = $this->finish($state, 'first_response', $calendar, $now);
        }

        if ($ticket->isDirty('last_agent_message_at') && $ticket->last_agent_message_at !== null) {
            $state = $this->finish($state, 'next_response', $calendar, $now);
        }

        // Neue Kundennachricht nach der ersten Antwort: Folgereaktion läuft (falls nicht schon)
        $alreadyAnswered = $ticket->getOriginal('first_response_at') !== null;
        if ($ticket->isDirty('last_customer_message_at') && $alreadyAnswered && ! $this->isClosed($ticket)
            && ! $this->running($state, 'next_response')) {
            $state = $this->begin($state, 'next_response', $policy->target($ticket->priority, 'next_response'), $policy, $calendar, $now);
        }

        $ticket->sla = $state ?: null;
        $this->refreshColumns($ticket);
    }

    /** Fristen eines neuen Tickets nach der passenden Richtlinie */
    private function start(Ticket $ticket, CarbonImmutable $now): void
    {
        $policy = SlaPolicy::forTicket($ticket);

        if (! $policy) {
            return;
        }

        $calendar = $policy->calendar();
        $state = [];
        foreach (['first_response', 'resolution'] as $metric) {
            $state = $this->begin($state, $metric, $policy->target($ticket->priority, $metric), $policy, $calendar, $now);
        }

        $ticket->sla_policy_id = $policy->getKey();
        $ticket->setRelation('slaPolicy', $policy);
        $ticket->sla = $state ?: null;
    }

    private function statusChanged(Ticket $ticket, SlaPolicy $policy, BusinessHours $calendar, array $state, CarbonImmutable $now): array
    {
        $before = TicketStatus::query()->find($ticket->getOriginal('status_id'))?->behaviour;
        $after = TicketStatus::query()->find($ticket->status_id)?->behaviour;

        if ($after === StatusBehaviour::Closed) {
            foreach (array_keys(SlaPolicy::METRICS) as $metric) {
                $state = $this->finish($state, $metric, $calendar, $now);
            }

            return $state;
        }

        // Wieder geöffnet: Lösungszeit beginnt neu
        if ($before === StatusBehaviour::Closed) {
            unset($state['resolution']);
            $state = $this->begin($state, 'resolution', $policy->target($ticket->priority, 'resolution'), $policy, $calendar, $now);
        }

        if ($after === StatusBehaviour::Pending) {
            $state = $this->pause($state, 'resolution', $calendar, $now);
        } elseif ($after === StatusBehaviour::Open) {
            $state = $this->resume($state, 'resolution', $policy, $calendar, $now);
        }

        return $state;
    }

    // ---------------------------------------------------------------- einzelne Kennzahl

    private function begin(array $state, string $metric, ?int $target, SlaPolicy $policy, BusinessHours $calendar, CarbonImmutable $now): array
    {
        if ($target === null) {
            unset($state[$metric]);

            return $state;
        }

        $state[$metric] = $this->schedule([
            'target' => $target, 'used' => 0, 'since' => $now->toIso8601String(), 'paused' => false,
            'warned' => false, 'breached' => false, 'met_at' => null,
        ], $policy, $calendar);

        return $state;
    }

    private function finish(array $state, string $metric, BusinessHours $calendar, CarbonImmutable $now): array
    {
        $m = $state[$metric] ?? null;
        if (! $m || ! empty($m['met_at'])) {
            return $state;
        }

        if (! empty($m['due_at']) && CarbonImmutable::parse($m['due_at']) < $now) {
            $m['breached'] = true;
        }

        $m['met_at'] = $now->toIso8601String();
        $m['since'] = null;
        $m['paused'] = false;
        $m['warn_at'] = null;
        $state[$metric] = $m;

        return $state;
    }

    private function pause(array $state, string $metric, BusinessHours $calendar, CarbonImmutable $now): array
    {
        $m = $state[$metric] ?? null;
        if (! $m || ! empty($m['met_at']) || empty($m['since'])) {
            return $state;
        }

        $m['used'] = (int) $m['used'] + $calendar->minutesBetween(CarbonImmutable::parse($m['since']), $now);
        $m['since'] = null;
        $m['paused'] = true;
        $m['due_at'] = null;
        $m['warn_at'] = null;
        $state[$metric] = $m;

        return $state;
    }

    private function resume(array $state, string $metric, SlaPolicy $policy, BusinessHours $calendar, CarbonImmutable $now): array
    {
        $m = $state[$metric] ?? null;
        if (! $m || ! empty($m['met_at']) || ! empty($m['since'])) {
            return $state;
        }

        $m['since'] = $now->toIso8601String();
        $m['paused'] = false;
        $state[$metric] = $this->schedule($m, $policy, $calendar);

        return $state;
    }

    private function retarget(array $m, ?int $target, SlaPolicy $policy, BusinessHours $calendar, CarbonImmutable $now): ?array
    {
        if ($target === null) {
            return null;
        }

        $m['target'] = $target;

        if (empty($m['since'])) {
            return $m; // pausiert: neue Zeiten beim Fortsetzen
        }

        $m = $this->schedule($m, $policy, $calendar);

        // Ziel liegt wieder in der Zukunft: erneut warnen bzw. melden können
        if (CarbonImmutable::parse($m['due_at']) > $now) {
            $m['breached'] = false;
        }
        if (CarbonImmutable::parse($m['warn_at']) > $now) {
            $m['warned'] = false;
        }

        return $m;
    }

    /** Fälligkeit und Warnzeitpunkt aus Ziel, Verbrauch und Start berechnen */
    private function schedule(array $m, SlaPolicy $policy, BusinessHours $calendar): array
    {
        $since = CarbonImmutable::parse($m['since']);
        $left = (int) $m['target'] - (int) $m['used'];
        $warnLeft = (int) floor($m['target'] * $policy->warnPercent() / 100) - (int) $m['used'];

        $m['due_at'] = $calendar->add($since, max(0, $left))->toIso8601String();
        $m['warn_at'] = $calendar->add($since, max(0, $warnLeft))->toIso8601String();

        return $m;
    }

    private function running(array $state, string $metric): bool
    {
        return ! empty($state[$metric]['since']);
    }

    private function isClosed(Ticket $ticket): bool
    {
        return TicketStatus::query()->find($ticket->status_id)?->closes() ?? false;
    }

    // ---------------------------------------------------------------- Spalten für Abfragen

    /** sla_due_at (nächste laufende Frist), sla_next_check_at (nächste Meldung) und sla_breached */
    public function refreshColumns(Ticket $ticket): void
    {
        $due = null;
        $check = null;
        $breached = false;

        foreach ($ticket->sla ?? [] as $m) {
            $breached = $breached || ! empty($m['breached']);

            if (empty($m['since'])) {
                continue;
            }

            $dueAt = CarbonImmutable::parse($m['due_at']);
            $due = $due === null ? $dueAt : $due->min($dueAt);

            $next = match (true) {
                empty($m['warned']) => CarbonImmutable::parse($m['warn_at']),
                empty($m['breached']) => $dueAt,
                default => null,
            };
            if ($next) {
                $check = $check === null ? $next : $check->min($next);
            }
        }

        // Spalten in der Zeitzone der Anwendung speichern (Laravel rechnet beim Schreiben nicht um)
        $tz = config('app.timezone');
        $ticket->sla_due_at = $due?->setTimezone($tz);
        $ticket->sla_next_check_at = $check?->setTimezone($tz);
        $ticket->sla_breached = $breached;
    }
}
