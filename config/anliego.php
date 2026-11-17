<?php

return [
    // Version der Weboberfläche (bei jeder für Mitarbeiter sichtbaren Änderung erhöhen, siehe Handbuch Kap. 12)
    'version' => '1.7.2',

    // App-Anmeldungen, die so viele Tage nicht benutzt wurden, verfallen (0 = nie)
    // Aufbewahrung des Sicherheitsprotokolls in Tagen
    'security_log_days' => (int) env('ANLIEGO_SECURITY_LOG_DAYS', 180),

    'agent_token_days' => (int) env('ANLIEGO_AGENT_TOKEN_DAYS', 90),

    // Zwei-Faktor-Anmeldung für alle Mitarbeiter verpflichtend (empfohlen in Produktion,
    // da jeder Zugang Daten mehrerer Mandanten sieht)
    // Zwei-Faktor-Anmeldung ist freiwillig; mit ANLIEGO_MFA_PFLICHT=true wird sie für alle verpflichtend
    'require_mfa' => (bool) env('ANLIEGO_MFA_PFLICHT', false),

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
