<?php

namespace App\Install;

use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Ersteinrichtung im Browser (public/install.php), solange es noch keine .env gibt.
 *
 * Geschützt durch einen Zugangscode: entweder der Deploy-Token aus GitHub (der Deploy-Workflow
 * legt seinen SHA-256-Hash in bootstrap/install.hash ab) oder ein Code, den der Assistent beim
 * ersten Aufruf in storage/install-code.txt schreibt (nur per SFTP lesbar).
 *
 * Ablauf: Eingaben prüfen → Datenbank testen → Tabellen anlegen und Administrator einrichten
 * (Laravel mit den Werten als Umgebungsvariablen) → erst danach die .env schreiben.
 * Mit vorhandener .env ist der Assistent gesperrt.
 */
class Installer
{
    public const MIN_PHP = '8.2.0';

    public const EXTENSIONS = ['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'tokenizer', 'ctype', 'json', 'intl'];

    public function __construct(private readonly string $basePath) {}

    public function envPath(): string
    {
        return $this->basePath.'/.env';
    }

    public function isInstalled(): bool
    {
        return is_file($this->envPath());
    }

    // ---------------------------------------------------------------- Zugangscode

    /** 'deploy' (Deploy-Token aus GitHub) oder 'file' (Code in storage/install-code.txt) */
    public function codeMode(): string
    {
        return is_file($this->hashPath()) ? 'deploy' : 'file';
    }

    public function codeFilePath(): string
    {
        return $this->basePath.'/storage/install-code.txt';
    }

    /** Legt den Code-Datei-Zugang an, falls es keinen Deploy-Hash gibt */
    public function ensureCodeFile(): void
    {
        if ($this->codeMode() === 'file' && ! is_file($this->codeFilePath())) {
            @file_put_contents($this->codeFilePath(), strtoupper(bin2hex(random_bytes(6)))."\n");
            @chmod($this->codeFilePath(), 0600);
        }
    }

    public function checkCode(string $code): bool
    {
        $code = trim($code);
        if ($code === '') {
            return false;
        }

        if ($this->codeMode() === 'deploy') {
            $expected = trim((string) @file_get_contents($this->hashPath()));

            return $expected !== '' && hash_equals($expected, hash('sha256', $code));
        }

        $expected = strtoupper(trim((string) @file_get_contents($this->codeFilePath())));

        return $expected !== '' && hash_equals($expected, strtoupper($code));
    }

    private function hashPath(): string
    {
        return $this->basePath.'/bootstrap/install.hash';
    }

    // ---------------------------------------------------------------- Voraussetzungen

    /** @return list<array{label: string, ok: bool, hint: string}> */
    public function requirements(): array
    {
        $checks = [[
            'label' => 'PHP '.PHP_VERSION,
            'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            'hint' => 'Mindestens PHP 8.2, empfohlen 8.4 (IONOS: Hosting → PHP-Einstellungen).',
        ]];

        foreach (self::EXTENSIONS as $ext) {
            $checks[] = ['label' => "PHP-Erweiterung {$ext}", 'ok' => extension_loaded($ext), 'hint' => 'Bei IONOS normalerweise vorhanden.'];
        }

        $checks[] = ['label' => 'Programmdateien vollständig (vendor/)', 'ok' => is_file($this->basePath.'/vendor/autoload.php'),
            'hint' => 'Den Upload über GitHub (Deploy auf IONOS) erneut starten.'];

        // Ohne diese Dateien öffnen sich Fenster und Schaltflächen der Oberfläche nicht (z. B. 2FA einrichten)
        $checks[] = ['label' => 'Oberflächen-Dateien vollständig (public/js/filament)', 'ok' => is_file($this->basePath.'/public/js/filament/filament/app.js'),
            'hint' => 'Die Ordner public/js, public/css und public/fonts aus dem Download vollständig hochladen.'];

        foreach (['' => 'Anwendungsordner (für die .env)', 'storage' => 'storage/', 'bootstrap/cache' => 'bootstrap/cache/'] as $dir => $label) {
            $checks[] = ['label' => "Schreibrechte {$label}", 'ok' => is_writable($this->basePath.($dir ? '/'.$dir : '')),
                'hint' => 'Per SFTP die Rechte des Ordners auf 755 setzen.'];
        }

        return $checks;
    }

    public function requirementsMet(): bool
    {
        return array_reduce($this->requirements(), fn (bool $ok, array $c) => $ok && $c['ok'], true);
    }

    // ---------------------------------------------------------------- Eingaben

    public const FIELDS = [
        'app_url', 'db_host', 'db_port', 'db_database', 'db_username', 'db_password',
        'admin_name', 'admin_email', 'admin_password', 'admin_password_confirm',
        'tenant_name', 'tenant_code',
        'mail_enabled', 'mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_from',
    ];

    /** @return array<string, string> Fehler je Feld */
    public function validate(array $in): array
    {
        $errors = [];
        $required = function (string $key, string $msg) use (&$errors, $in) {
            if (trim((string) ($in[$key] ?? '')) === '') {
                $errors[$key] = $msg;
            }
        };

        if (! preg_match('#^https?://[^\s/]+#i', (string) ($in['app_url'] ?? ''))) {
            $errors['app_url'] = 'Bitte die vollständige Adresse angeben, z. B. https://tickets.deine-domain.de';
        }
        $required('db_host', 'Bitte den Datenbank-Server angeben.');
        $required('db_database', 'Bitte den Namen der Datenbank angeben.');
        $required('db_username', 'Bitte den Datenbank-Benutzer angeben.');
        if (! ctype_digit((string) ($in['db_port'] ?? ''))) {
            $errors['db_port'] = 'Der Port ist eine Zahl, meist 3306.';
        }

        $required('admin_name', 'Bitte deinen Namen angeben.');
        if (! filter_var($in['admin_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $errors['admin_email'] = 'Bitte eine gültige E-Mail-Adresse angeben.';
        }
        if (strlen((string) ($in['admin_password'] ?? '')) < 12) {
            $errors['admin_password'] = 'Das Passwort braucht mindestens 12 Zeichen.';
        } elseif (($in['admin_password'] ?? '') !== ($in['admin_password_confirm'] ?? '')) {
            $errors['admin_password_confirm'] = 'Die Passwörter stimmen nicht überein.';
        }

        if (trim((string) ($in['tenant_name'] ?? '')) !== '' && ! preg_match('/^[A-Za-z0-9]{2,10}$/', (string) ($in['tenant_code'] ?? ''))) {
            $errors['tenant_code'] = '2 bis 10 Buchstaben oder Ziffern, z. B. TSV. Erscheint in der Ticketnummer (TSV-1000).';
        }

        if (! empty($in['mail_enabled'])) {
            $required('mail_host', 'Bitte den SMTP-Server angeben.');
            $required('mail_username', 'Bitte den Benutzer (meist die E-Mail-Adresse) angeben.');
            if (! filter_var($in['mail_from'] ?? '', FILTER_VALIDATE_EMAIL)) {
                $errors['mail_from'] = 'Bitte eine gültige Absenderadresse angeben.';
            }
            if (! ctype_digit((string) ($in['mail_port'] ?? ''))) {
                $errors['mail_port'] = 'Der Port ist eine Zahl, meist 465.';
            }
        }

        return $errors;
    }

    /** Verbindung zur Datenbank prüfen; gibt eine verständliche Fehlermeldung oder null zurück */
    public function testDatabase(array $in): ?string
    {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', trim($in['db_host']), (int) $in['db_port'], trim($in['db_database'])),
                trim($in['db_username']),
                (string) $in['db_password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8],
            );
            $pdo->query('SELECT 1');

            return null;
        } catch (PDOException $e) {
            $msg = $e->getMessage();

            return match (true) {
                str_contains($msg, 'Access denied') => 'Benutzer oder Passwort der Datenbank sind falsch.',
                str_contains($msg, 'Unknown database') => 'Diese Datenbank gibt es nicht. Bitte den Namen prüfen (bei IONOS z. B. dbs12345678).',
                str_contains($msg, 'getaddrinfo') || str_contains($msg, 'Name or service not known') => 'Den Datenbank-Server gibt es nicht. Bitte den Hostnamen prüfen (bei IONOS z. B. db12345678.hosting-data.io).',
                default => 'Keine Verbindung zur Datenbank: '.$msg,
            };
        }
    }

    // ---------------------------------------------------------------- .env

    /** @return array<string, string> */
    public function environment(array $in, string $deployToken): array
    {
        $mail = ! empty($in['mail_enabled']);
        $port = (int) ($in['mail_port'] ?? 465);

        return [
            'APP_NAME' => 'Anliego',
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG' => 'false',
            'APP_URL' => rtrim(trim($in['app_url']), '/'),
            'APP_LOCALE' => 'de',
            'APP_FALLBACK_LOCALE' => 'en',
            'APP_TIMEZONE' => 'Europe/Berlin',
            'LOG_CHANNEL' => 'daily',
            'LOG_LEVEL' => 'warning',
            'DB_CONNECTION' => 'mariadb',
            'DB_HOST' => trim($in['db_host']),
            'DB_PORT' => (string) (int) $in['db_port'],
            'DB_DATABASE' => trim($in['db_database']),
            'DB_USERNAME' => trim($in['db_username']),
            'DB_PASSWORD' => (string) $in['db_password'],
            'SESSION_DRIVER' => 'database',
            'SESSION_LIFETIME' => '480',
            'SESSION_SECURE_COOKIE' => str_starts_with(strtolower($in['app_url']), 'https://') ? 'true' : 'false',
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'database',
            'FILESYSTEM_DISK' => 'local',
            'MAIL_MAILER' => $mail ? 'smtp' : 'log',
            'MAIL_SCHEME' => $port === 465 ? 'smtps' : 'smtp',
            'MAIL_HOST' => $mail ? trim($in['mail_host']) : 'smtp.ionos.de',
            'MAIL_PORT' => (string) ($mail ? $port : 465),
            'MAIL_USERNAME' => $mail ? trim($in['mail_username']) : '',
            'MAIL_PASSWORD' => $mail ? (string) $in['mail_password'] : '',
            'MAIL_FROM_ADDRESS' => $mail ? trim($in['mail_from']) : 'noreply@'.(parse_url($in['app_url'], PHP_URL_HOST) ?: 'example.de'),
            'MAIL_FROM_NAME' => 'Anliego',
            'ANLIEGO_REQUIRE_MFA' => 'true',
            'ANLIEGO_DEPLOY_TOKEN' => $deployToken,
            'ANLIEGO_CRON_WARNING_MINUTES' => '10',
        ];
    }

    /** @param array<string, string> $env */
    public static function renderEnv(array $env): string
    {
        $lines = ['# Angelegt vom Installationsassistenten am '.date('d.m.Y H:i').'. Siehe docs/betrieb-ionos.md', ''];
        foreach ($env as $key => $value) {
            $lines[] = $key.'='.self::quote($value);
        }

        return implode("\n", $lines)."\n";
    }

    /** Werte mit Leer- oder Sonderzeichen in Anführungszeichen; $ und " werden geschützt */
    public static function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@+=-]+$/', $value)) {
            return $value;
        }

        return '"'.addcslashes($value, '\\"$').'"';
    }

    // ---------------------------------------------------------------- Installation

    /**
     * Legt Tabellen, Administrator und (optional) den ersten Mandanten an und schreibt danach die .env.
     *
     * @return array{env_written: bool, env: string, log: list<string>}
     */
    public function install(array $in, string $deployToken): array
    {
        $env = $this->environment($in, $deployToken);
        $log = [];

        // Gecachte Konfiguration würde die neuen Werte überdecken
        @unlink($this->basePath.'/bootstrap/cache/config.php');

        foreach ($env as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $app = require $this->basePath.'/bootstrap/app.php';
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();

        $this->call($kernel, 'migrate', ['--force' => true], $log);
        $this->call($kernel, 'anliego:create-admin', [
            'email' => mb_strtolower(trim($in['admin_email'])),
            'name' => trim($in['admin_name']),
            '--password' => (string) $in['admin_password'],
        ], $log);

        if (trim((string) ($in['tenant_name'] ?? '')) !== '') {
            $tenant = Tenant::query()->firstOrCreate(
                ['short_code' => strtoupper(trim($in['tenant_code']))],
                ['name' => trim($in['tenant_name'])],
            );
            $log[] = "Mandant {$tenant->name} ({$tenant->short_code}) angelegt.";
        }

        $content = self::renderEnv($env);
        $written = @file_put_contents($this->envPath().'.tmp', $content) !== false
            && @rename($this->envPath().'.tmp', $this->envPath());
        if ($written) {
            @chmod($this->envPath(), 0600);
            @unlink($this->codeFilePath());
        }

        return ['env_written' => $written, 'env' => $content, 'log' => $log];
    }

    private function call(Kernel $kernel, string $command, array $args, array &$log): void
    {
        try {
            $code = $kernel->call($command, $args);
        } catch (Throwable $e) {
            throw new RuntimeException("{$command}: ".$e->getMessage(), 0, $e);
        }

        $output = trim($kernel->output());
        if ($code !== 0) {
            throw new RuntimeException("{$command}: ".($output ?: 'fehlgeschlagen'));
        }
        $log[] = "{$command}: ok";
    }

    /** Befehl für den Cronjob, mit dem tatsächlichen Pfad auf dem Server */
    public function cronCommand(): string
    {
        return '/usr/bin/php8.4-cli '.(realpath($this->basePath) ?: $this->basePath).'/artisan schedule:run';
    }
}
