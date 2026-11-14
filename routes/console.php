<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Auf dem Webhosting gibt es keinen dauerhaft laufenden Prozess. Ein einziger Cronjob ruft
 * "php artisan schedule:run" auf (ideal jede Minute). Alles Weitere wird hier geplant.
 */

Schedule::command('anliego:heartbeat')->everyMinute();

// Warteschlange abarbeiten und vor dem nächsten Cron-Lauf sauber beenden
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(5);

// Phase 1: Postfächer abrufen (Intervall je Postfach), SLA-Prüfung, automatisches Schließen
