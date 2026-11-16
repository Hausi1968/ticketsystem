<?php

return [
    // Version der Weboberfläche (bei jeder für Mitarbeiter sichtbaren Änderung erhöhen, siehe Handbuch Kap. 12)
    'version' => '1.2.0',

    // Zwei-Faktor-Anmeldung für alle Mitarbeiter verpflichtend (empfohlen in Produktion,
    // da jeder Zugang Daten mehrerer Mandanten sieht)
    'require_mfa' => env('ANLIEGO_REQUIRE_MFA', true),

    // Geheimer Schlüssel für den Aufruf /_deploy/migrate nach einem Upload ohne SSH.
    // Leer = Route deaktiviert.
    'deploy_token' => env('ANLIEGO_DEPLOY_TOKEN'),

    // Nur für die Ersteinrichtung ohne SSH: Wird beim ersten Aufruf von /_deploy/migrate
    // angelegt, wenn noch kein Benutzer existiert. Danach aus der .env entfernen.
    'initial_admin' => [
        'name' => env('ANLIEGO_INITIAL_ADMIN_NAME', 'Administrator'),
        'email' => env('ANLIEGO_INITIAL_ADMIN_EMAIL'),
        'password' => env('ANLIEGO_INITIAL_ADMIN_PASSWORD'),
    ],

    // Wie viele nächtliche Datenbanksicherungen aufbewahrt werden
    'backup_keep' => env('ANLIEGO_BACKUP_KEEP', 14),

    // Nach wie vielen Minuten ohne Cronjob-Lauf eine Warnung angezeigt wird
    'cron_warning_minutes' => env('ANLIEGO_CRON_WARNING_MINUTES', 10),

    // Größte Datei, die über die API hochgeladen werden darf (MB)
    'api_max_upload_mb' => (int) env('ANLIEGO_API_MAX_UPLOAD_MB', 100),
];
