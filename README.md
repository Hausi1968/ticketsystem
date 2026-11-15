<p align="center"><img src="public/images/anliego-logo.svg" alt="Anliego" height="64"></p>

# Anliego

Mandantenfähiges Ticketsystem: Ein Team betreut die Anliegen mehrerer Mandanten. Tickets entstehen aus E-Mails an die Postfächer der Mandanten und aus Meldungen direkt aus Anwendungen („Problem melden“), werden Mitarbeitern zugewiesen oder selbst übernommen und per E-Mail beantwortet.

- **Konzept und Architektur:** [docs/konzept.md](docs/konzept.md)
- **Betrieb auf IONOS-Webhosting:** [docs/betrieb-ionos.md](docs/betrieb-ionos.md)
- **Ticket-Schnittstelle für Anwendungen:** [docs/api.md](docs/api.md), PHP-Client in [clients/php](clients/php/AnliegoClient.php)

## Stand

Phase 0 (Fundament): Mandanten, Mitarbeiter mit Zuordnung zu mehreren Mandanten, Teams, Anmeldung mit Zwei-Faktor, strikte Mandantentrennung mit Tests, automatisches Deployment auf IONOS. 

Phase 1a (Tickets und E-Mail): Postfächer je Mandant mit IMAP-Abruf per Cronjob (Intervall je Postfach), Zuordnung von Antworten zum richtigen Ticket, Schutz vor Dubletten und Abwesenheitsnotizen, Antworten per SMTP im selben E-Mail-Verlauf, interne Notizen, Anhänge, gemeinsame Arbeitsliste über alle betreuten Mandanten mit „Übernehmen“.

Phase 1b (Ticket-API): API-Schlüssel je Mandant, Tickets aus Anwendungen mit Anhängen, technischen Details und Schutz vor Doppelmeldungen, Abruf der eigenen Meldungen mit Verlauf (ohne interne Notizen).

Phase 2a (Benachrichtigungen): Eingangsbestätigung an Kunden je Postfach (mit Schutz vor Schleifen und noreply-Absendern), Hinweise an Mitarbeiter bei neuem Ticket, Zuweisung und Kundenantwort in der Oberfläche und nach Wunsch per E-Mail.

Phase 2b (Fristen/SLA): Richtlinien je Mandant (optional nur für ein Team oder einen Eingang) mit Zielen je Priorität für Erstreaktion, Folgereaktion und Lösung. Gerechnet wird in Geschäftszeiten (je Wochentag, gesetzliche Feiertage je Bundesland, eigene freie Tage) oder rund um die Uhr. „Wartet“ pausiert die Lösungszeit, eine Prioritätsänderung berechnet die Ziele neu. `anliego:sla-check` (jede Minute über den Scheduler) warnt den Bearbeiter bei 75 % (einstellbar) und meldet Überschreitungen zusätzlich den Supervisoren. Fristen stehen in der Ticketliste (Spalte und Filter „Frist“) und in der Ticketansicht.

Als Nächstes: SLAs und Erinnerungen, Webhooks, „Problem melden“ in der Mitgliederverwaltung.

## Technik

PHP 8.4, Laravel 12, Filament 4, MariaDB/MySQL. Läuft auf normalem Webhosting; Hintergrundarbeit über einen Cronjob (`php artisan schedule:run`).

## Lokal entwickeln

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan anliego:create-admin du@example.de "Dein Name"
php artisan serve
```

Lokal lässt sich die Zwei-Faktor-Pflicht mit `ANLIEGO_REQUIRE_MFA=false` in der `.env` abschalten.

Tests: `vendor/bin/pest`

## Mandantentrennung

Alle mandantenbezogenen Modelle nutzen den Trait `App\Tenancy\BelongsToTenant`. Er filtert jede Abfrage automatisch auf die Mandanten des angemeldeten Mitarbeiters, verhindert das Anlegen von Daten in fremden Mandanten und das Umhängen von Datensätzen. Ohne Anmeldung (z. B. in Jobs) ist nichts sichtbar, bis der Mandant ausdrücklich gesetzt wird:

```php
app(TenantContext::class)->runFor($tenant, fn () => /* … */);
```

Zusätzlich sichern zusammengesetzte Fremdschlüssel `(tenant_id, …)` in der Datenbank ab, dass Verknüpfungen nie über Mandantengrenzen gehen. Die Tests in `tests/Feature/TenantIsolationTest.php` prüfen das.
