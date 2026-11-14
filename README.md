<p align="center"><img src="public/images/anliego-logo.svg" alt="Anliego" height="64"></p>

# Anliego

Mandantenfähiges Ticketsystem: Ein Team betreut die Anliegen mehrerer Mandanten. Tickets entstehen aus E-Mails an die Postfächer der Mandanten und aus Meldungen direkt aus Anwendungen („Problem melden“), werden Mitarbeitern zugewiesen oder selbst übernommen und per E-Mail beantwortet.

- **Konzept und Architektur:** [docs/konzept.md](docs/konzept.md)
- **Betrieb auf IONOS-Webhosting:** [docs/betrieb-ionos.md](docs/betrieb-ionos.md)

## Stand

Phase 0 (Fundament): Mandanten, Mitarbeiter mit Zuordnung zu mehreren Mandanten, Teams, Anmeldung mit Zwei-Faktor, strikte Mandantentrennung mit Tests, automatisches Deployment auf IONOS. Tickets, E-Mail und App-Anbindung folgen in Phase 1.

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
