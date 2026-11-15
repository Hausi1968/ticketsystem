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
