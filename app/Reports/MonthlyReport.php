<?php

namespace App\Reports;

use App\Enums\MessageType;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Monatsbericht je Mandant: Eingang, Erledigung, Reaktions- und Lösungszeiten, Fristen, Kategorien.
 * Zeiten in Stunden (Kalenderzeit, nicht Geschäftszeit).
 */
class MonthlyReport
{
    public const COLUMNS = [
        'tenant' => 'Mandant',
        'created' => 'Neue Tickets',
        'via_email' => 'davon E-Mail',
        'via_api' => 'davon App',
        'closed' => 'Erledigt',
        'open_now' => 'Offen (heute)',
        'replies' => 'Antworten des Teams',
        'avg_first_response_h' => 'Ø erste Antwort (Std.)',
        'avg_resolution_h' => 'Ø Lösungszeit (Std.)',
        'sla_breached' => 'Frist überschritten',
        'top_category' => 'Häufigste Kategorie',
    ];

    /** Monatsanfang und -ende in der App-Zeitzone für z. B. "2026-11" */
    public static function period(string $month): array
    {
        $from = CarbonImmutable::createFromFormat('!Y-m', $month, config('app.timezone'));

        return [$from, $from->endOfMonth()];
    }

    /** @return list<array<string, mixed>> eine Zeile je Mandant, plus Summenzeile bei mehreren */
    public function rows(string $month, ?int $tenantId = null): array
    {
        [$from, $to] = self::period($month);

        return app(TenantContext::class)->runWithoutTenancy(function () use ($from, $to, $tenantId) {
            $tenants = Tenant::query()->when($tenantId, fn ($q) => $q->whereKey($tenantId))->orderBy('name')->get();
            $rows = $tenants->map(fn (Tenant $tenant) => $this->row($tenant, $from, $to))->all();

            if (count($rows) > 1) {
                $rows[] = $this->total($rows);
            }

            return $rows;
        });
    }

    private function row(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $created = Ticket::query()->with('category')->where('tenant_id', $tenant->id)->whereNull('merged_into_id')
            ->whereBetween('created_at', [$from, $to])->get();
        $closed = Ticket::query()->where('tenant_id', $tenant->id)->whereNull('merged_into_id')
            ->whereBetween('closed_at', [$from, $to])->get();

        $firstResponse = $created->filter(fn (Ticket $t) => $t->first_response_at)
            ->map(fn (Ticket $t) => $t->created_at->diffInMinutes($t->first_response_at));
        $resolution = $closed->filter(fn (Ticket $t) => $t->created_at)
            ->map(fn (Ticket $t) => $t->created_at->diffInMinutes($t->closed_at));

        $topCategory = $created->filter(fn (Ticket $t) => $t->category)->countBy(fn (Ticket $t) => $t->category->name)->sortDesc();

        return [
            'tenant' => $tenant->name,
            'created' => $created->count(),
            'via_email' => $created->where('channel', 'email')->count(),
            'via_api' => $created->where('channel', 'api')->count(),
            'closed' => $closed->count(),
            'open_now' => Ticket::query()->where('tenant_id', $tenant->id)->whereNull('merged_into_id')->unresolved()->count(),
            'replies' => TicketMessage::query()->where('tenant_id', $tenant->id)->where('type', MessageType::Reply)
                ->whereBetween('created_at', [$from, $to])->count(),
            'avg_first_response_h' => self::hours($firstResponse),
            'avg_resolution_h' => self::hours($resolution),
            'sla_breached' => $created->where('sla_breached', true)->count(),
            'top_category' => $topCategory->isNotEmpty() ? $topCategory->keys()->first().' ('.$topCategory->first().')' : null,
            // Rohwerte für die Summenzeile
            '_first' => $firstResponse->all(),
            '_resolution' => $resolution->all(),
        ];
    }

    private function total(array $rows): array
    {
        $sum = fn (string $key) => array_sum(array_column($rows, $key));

        return [
            'tenant' => 'Gesamt',
            'created' => $sum('created'),
            'via_email' => $sum('via_email'),
            'via_api' => $sum('via_api'),
            'closed' => $sum('closed'),
            'open_now' => $sum('open_now'),
            'replies' => $sum('replies'),
            'avg_first_response_h' => self::hours(collect(array_merge(...array_column($rows, '_first')))),
            'avg_resolution_h' => self::hours(collect(array_merge(...array_column($rows, '_resolution')))),
            'sla_breached' => $sum('sla_breached'),
            'top_category' => null,
            '_first' => [],
            '_resolution' => [],
        ];
    }

    private static function hours(Collection $minutes): ?float
    {
        return $minutes->isEmpty() ? null : round($minutes->avg() / 60, 1);
    }

    /** CSV (Semikolon, UTF-8 mit BOM, damit Excel Umlaute richtig anzeigt) */
    public function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_values(self::COLUMNS), ';');
        foreach ($rows as $row) {
            fputcsv($out, array_map(
                fn ($key) => is_float($row[$key]) ? number_format($row[$key], 1, ',', '') : $row[$key],
                array_keys(self::COLUMNS),
            ), ';');
        }
        rewind($out);

        return stream_get_contents($out);
    }
}
