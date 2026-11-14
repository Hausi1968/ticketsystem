<?php

namespace App\Filament\Widgets;

use App\Console\Commands\Heartbeat;
use App\Enums\StatusBehaviour;
use App\Models\Mailbox;
use App\Models\Tenant;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class SystemStatus extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $last = Cache::get(Heartbeat::CACHE_KEY);
        $lastRun = $last ? CarbonImmutable::parse($last) : null;
        $ok = $lastRun && $lastRun->gt(now()->subMinutes((int) config('anliego.cron_warning_minutes')));

        return [
            Stat::make('Mandanten', Tenant::query()->accessible()->count())
                ->description('die du betreust'),
            Stat::make('Cronjob', $ok ? 'läuft' : 'prüfen')
                ->description($lastRun ? 'zuletzt '.$lastRun->timezone('Europe/Berlin')->format('d.m.Y H:i') : 'noch nie gelaufen')
                ->color($ok ? 'success' : 'danger'),
            Stat::make('Offene Tickets', Ticket::query()->withBehaviour(StatusBehaviour::Open)->count())
                ->description(Ticket::query()->whereNull('assignee_id')->unresolved()->count().' unzugewiesen'),
            Stat::make('Postfächer', Mailbox::query()->where('active', true)->count())
                ->description(($failing = Mailbox::query()->whereNotNull('last_error')->count()) > 0 ? "{$failing} mit Abruffehler" : 'alle ok')
                ->color(($failing ?? 0) > 0 ? 'danger' : 'success'),
        ];
    }
}
