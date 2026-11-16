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

// Postfächer abrufen. Das Intervall steht je Postfach (z. B. 5 oder 10 Minuten).
Schedule::command('anliego:poll-mailboxes')
    ->everyMinute()
    ->withoutOverlapping(15);

Schedule::command('anliego:cleanup')->dailyAt('03:15');

// Datenbank nachts sichern (storage/app/private/backups, Download unter Verwaltung → Datensicherung)
Schedule::command('anliego:backup')->dailyAt('02:30')->withoutOverlapping(60);

// SLA: Warnungen vor Ablauf und Meldungen bei Überschreitung
Schedule::command('anliego:sla-check')
    ->everyMinute()
    ->withoutOverlapping(5);

// Je Mandant: Erinnerung an Kunden, wartende Tickets lösen, gelöste schließen
Schedule::command('anliego:automation')->hourly()->withoutOverlapping(30);

// Wiedervorlage: zurückgestellte Tickets zum gewählten Zeitpunkt zurückholen
Schedule::command('anliego:wake-snoozed')->everyMinute()->withoutOverlapping(5);
