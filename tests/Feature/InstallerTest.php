<?php

use App\Install\Installer;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/anliego-install-'.uniqid();
    mkdir($this->dir.'/storage', 0777, true);
    mkdir($this->dir.'/bootstrap/cache', 0777, true);
    $this->installer = new Installer($this->dir);
});

afterEach(fn () => exec('rm -rf '.escapeshellarg($this->dir)));

function validInput(array $o = []): array
{
    return [
        'app_url' => 'https://tickets.example.de', 'db_host' => 'db1.hosting-data.io', 'db_port' => '3306',
        'db_database' => 'dbs1', 'db_username' => 'dbu1', 'db_password' => 'p"a$s word',
        'admin_name' => 'Karsten', 'admin_email' => 'k@example.de', 'admin_password' => 'zwölf-zeichen-lang', 'admin_password_confirm' => 'zwölf-zeichen-lang',
        'tenant_name' => '', 'tenant_code' => '', 'mail_enabled' => '', ...$o,
    ];
}

it('öffnet sich nur mit dem Deploy-Token, dessen Hash GitHub ablegt', function () {
    file_put_contents($this->dir.'/bootstrap/install.hash', hash('sha256', 'geheimer-token')."\n");

    expect($this->installer->codeMode())->toBe('deploy')
        ->and($this->installer->checkCode('geheimer-token'))->toBeTrue()
        ->and($this->installer->checkCode('falsch'))->toBeFalse()
        ->and($this->installer->checkCode(''))->toBeFalse();
});

it('legt ohne Deploy-Token einen Code an, der nur per SFTP lesbar ist', function () {
    $this->installer->ensureCodeFile();
    $code = trim(file_get_contents($this->dir.'/storage/install-code.txt'));

    expect($this->installer->codeMode())->toBe('file')
        ->and(strlen($code))->toBe(12)
        ->and($this->installer->checkCode(strtolower($code)))->toBeTrue()
        ->and($this->installer->checkCode('ABC'))->toBeFalse();
});

it('ist gesperrt, sobald es eine .env gibt', function () {
    expect($this->installer->isInstalled())->toBeFalse();
    touch($this->dir.'/.env');
    expect($this->installer->isInstalled())->toBeTrue();
});

it('prüft die Eingaben', function () {
    expect($this->installer->validate(validInput()))->toBe([]);

    $errors = $this->installer->validate(validInput([
        'app_url' => 'tickets.example.de', 'db_port' => 'x', 'admin_password_confirm' => 'anders-als-oben',
        'tenant_name' => 'TSV', 'tenant_code' => 'T S V', 'mail_enabled' => '1', 'mail_host' => '', 'mail_port' => '465', 'mail_from' => 'kein',
    ]));

    expect(array_keys($errors))->toEqualCanonicalizing(['app_url', 'db_port', 'admin_password_confirm', 'tenant_code', 'mail_host', 'mail_username', 'mail_from']);
});

it('schreibt Sonderzeichen so in die .env, dass Laravel sie unverändert liest', function () {
    $env = $this->installer->environment(validInput(), 'tok');
    $content = Installer::renderEnv($env);

    $parsed = Dotenv\Dotenv::parse($content);

    expect($parsed['DB_PASSWORD'])->toBe('p"a$s word')
        ->and($parsed['APP_KEY'])->toStartWith('base64:')
        ->and($parsed['ANLIEGO_DEPLOY_TOKEN'])->toBe('tok')
        ->and($parsed['SESSION_SECURE_COOKIE'])->toBe('true')
        ->and($parsed['MAIL_MAILER'])->toBe('log');
});

it('leitet ohne .env auf den Assistenten um', function () {
    $index = file_get_contents(base_path('public/index.php'));

    expect($index)->toContain("header('Location: install.php'");
});
