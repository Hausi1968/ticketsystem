<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Merkt sich den letzten Cronjob-Lauf, damit die Oberfläche warnen kann, wenn er ausbleibt. */
class Heartbeat extends Command
{
    protected $signature = 'anliego:heartbeat';

    protected $description = 'Speichert den Zeitpunkt des letzten Cronjob-Laufs';

    public const CACHE_KEY = 'anliego:last_cron_run';

    public function handle(): int
    {
        Cache::forever(self::CACHE_KEY, now()->toIso8601String());

        return self::SUCCESS;
    }
}
