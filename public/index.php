<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (PHP_VERSION_ID < 80200) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Anliego braucht PHP 8.2 oder neuer (empfohlen 8.4). Auf diesem Webspace läuft PHP '.PHP_VERSION.'. IONOS: Hosting → PHP-Einstellungen.');
}

// Noch nicht eingerichtet: zum Installationsassistenten
if (! file_exists(__DIR__.'/../.env') && file_exists(__DIR__.'/install.php')) {
    header('Location: install.php', true, 302);
    exit;
}

// Wartung während eines Deploys: GitHub Actions legt die Datei vor dem Hochladen an und entfernt sie nach
// den Migrationen. Bis dahin sehen alle eine Wartungsseite statt Fehlern durch den halb hochgeladenen Stand.
// Nur /_deploy/* läuft durch. Nach 30 Minuten gilt die Datei als vergessen und wird übergangen.
$wartung = __DIR__.'/../storage/framework/anliego-wartung';
if (is_file($wartung) && time() - (int) @filemtime($wartung) < 1800) {
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (! str_starts_with($path, '/_deploy/')) {
        http_response_code(503);
        header('Retry-After: 60');
        header('Cache-Control: no-store');
        if (str_starts_with($path, '/api/') || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json; charset=utf-8');
            exit('{"error":"maintenance","message":"Anliego wird gerade aktualisiert. Bitte in einer Minute noch einmal versuchen."}');
        }
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__.'/wartung.html');
        exit;
    }
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
