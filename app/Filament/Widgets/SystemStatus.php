<?php

namespace App\Filament\Widgets;

use App\Console\Commands\Heartbeat;
use App\Models\Tenant;
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
            Stat::make('Tickets', '–')->description('folgen in Phase 1'),
        ];
    }
}
