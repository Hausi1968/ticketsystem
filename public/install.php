<?php

/**
 * Anliego – Installationsassistent.
 * Läuft nur, solange es noch keine .env gibt. Danach ist diese Seite gesperrt.
 */

declare(strict_types=1);

// Vor allem anderen: zu alte PHP-Version verständlich melden (sonst nur "500 Internal Server Error")
if (PHP_VERSION_ID < 80200) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Anliego braucht PHP 8.2 oder neuer (empfohlen 8.4). Auf diesem Webspace läuft PHP '.PHP_VERSION.".\n"
        .'IONOS: Hosting → PHP-Einstellungen → Version für diesen Webspace auf 8.4 stellen.';
    exit;
}

use App\Install\Installer;

$base = dirname(__DIR__);

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'");

if (! is_file($base.'/vendor/autoload.php')) {
    http_response_code(503);
    exit('Die Programmdateien sind unvollständig (vendor/ fehlt). Bitte den Upload über GitHub erneut starten.');
}

require $base.'/vendor/autoload.php';

$installer = new Installer($base);
$h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/** Seitenrahmen ausgeben */
$page = function (string $title, string $body) use ($h) {
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">',
    '<meta name="robots" content="noindex, nofollow"><title>', $h($title), ' – Anliego</title><style>',
    ':root{--p:#4f46e5;--a:#f59e0b;--t:#0f172a;--m:#64748b;--b:#e2e8f0;--bg:#f8fafc;--ok:#16a34a;--err:#dc2626}',
    '*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif;color:var(--t);background:var(--bg)}',
    '.wrap{max-width:46rem;margin:0 auto;padding:2rem 1rem 4rem}.logo{display:flex;align-items:center;gap:.6rem;font-weight:700;font-size:1.4rem;margin-bottom:1.5rem}',
    '.logo img{width:2.2rem;height:2.2rem}.logo span{color:var(--a)}h1{font-size:1.5rem;margin:0 0 .25rem}p.lead{color:var(--m);margin:0 0 1.5rem}',
    'fieldset{border:1px solid var(--b);border-radius:.75rem;background:#fff;padding:1rem 1.25rem 1.25rem;margin:0 0 1.25rem}',
    'legend{font-weight:600;padding:0 .4rem}.hint{color:var(--m);font-size:.85rem;margin:.1rem 0 .75rem}',
    '.grid{display:grid;gap:.75rem 1rem;grid-template-columns:1fr}@media(min-width:640px){.grid.two{grid-template-columns:1fr 1fr}}',
    'label{display:block;font-weight:500;font-size:.9rem;margin-bottom:.2rem}input[type=text],input[type=email],input[type=password],input[type=url],input[type=number]{width:100%;padding:.55rem .7rem;border:1px solid #cbd5e1;border-radius:.5rem;font:inherit}',
    'input:focus{outline:2px solid var(--p);outline-offset:1px}.err{color:var(--err);font-size:.85rem;margin-top:.2rem}.box{border-radius:.75rem;padding:.8rem 1rem;margin:0 0 1.25rem}',
    '.box.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.box.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}.box.info{background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3}',
    'ul.checks{list-style:none;padding:0;margin:0}ul.checks li{padding:.15rem 0}ul.checks .no{color:var(--err)}ul.checks .yes{color:var(--ok)}',
    'button{background:var(--p);color:#fff;border:0;border-radius:.5rem;padding:.7rem 1.4rem;font:inherit;font-weight:600;cursor:pointer}',
    'button:disabled{opacity:.5;cursor:not-allowed}code,pre{font-family:ui-monospace,monospace;font-size:.85rem;background:#f1f5f9;border-radius:.35rem}code{padding:.1rem .3rem}',
    'pre{padding:.75rem;overflow:auto;white-space:pre-wrap;word-break:break-all}a{color:var(--p)}.check{display:flex;gap:.5rem;align-items:center;font-weight:500}',
    '</style></head><body><div class="wrap"><div class="logo"><img src="images/anliego-icon.svg" alt=""><div>Anlie<span>go</span></div></div>', $body, '</div></body></html>';
};

// ---------------------------------------------------------------- bereits installiert

if ($installer->isInstalled()) {
    http_response_code(403);
    $page('Bereits installiert', '<h1>Anliego ist bereits eingerichtet</h1><p class="lead">Der Installationsassistent ist gesperrt, weil es schon eine Konfiguration (.env) gibt.</p>'
        .'<p><a href="./">Zur Anmeldung</a></p><p class="hint">Neu einrichten: die Datei <code>.env</code> im Anwendungsordner per SFTP löschen oder umbenennen.</p>');
    exit;
}

$installer->ensureCodeFile();

session_name('anliego_install');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'cookie_secure' => ! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$_SESSION['fails'] ??= 0;

$https = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$defaults = [
    'app_url' => ($https ? 'https://' : 'http://').preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')),
    'db_host' => '', 'db_port' => '3306', 'db_database' => '', 'db_username' => '', 'db_password' => '',
    'admin_name' => '', 'admin_email' => '', 'admin_password' => '', 'admin_password_confirm' => '',
    'tenant_name' => '', 'tenant_code' => '',
    'mail_enabled' => '', 'mail_host' => 'smtp.ionos.de', 'mail_port' => '465', 'mail_username' => '', 'mail_password' => '', 'mail_from' => '',
];
$in = $defaults;
$errors = [];
$general = null;

// ---------------------------------------------------------------- Absenden

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach (Installer::FIELDS as $f) {
        $in[$f] = is_string($_POST[$f] ?? null) ? $_POST[$f] : '';
    }
    $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';

    if (! hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $general = 'Die Seite war zu lange offen. Bitte noch einmal absenden.';
    } elseif ($_SESSION['fails'] >= 10) {
        $general = 'Zu viele falsche Zugangscodes. Bitte den Browser schließen und später erneut versuchen.';
    } elseif (! $installer->checkCode($code)) {
        $_SESSION['fails']++;
        sleep(2);
        $errors['code'] = 'Der Zugangscode stimmt nicht.';
    } elseif (! $installer->requirementsMet()) {
        $general = 'Der Webspace erfüllt noch nicht alle Voraussetzungen (siehe oben).';
    } elseif (! ($errors = $installer->validate($in)) && ($dbError = $installer->testDatabase($in)) !== null) {
        $general = $dbError;
    } elseif (! $errors) {
        $deployToken = $installer->codeMode() === 'deploy' ? trim($code) : bin2hex(random_bytes(32));

        try {
            $result = $installer->install($in, $deployToken);
        } catch (Throwable $e) {
            $general = 'Die Einrichtung ist fehlgeschlagen: '.$e->getMessage();
        }

        if (isset($result)) {
            session_destroy();
            $body = '<h1>Anliego ist eingerichtet</h1><p class="lead">Die Datenbanktabellen sind angelegt und dein Administrator-Zugang ist bereit.</p>';

            if (! $result['env_written']) {
                $body .= '<div class="box err"><strong>Die Konfiguration konnte nicht gespeichert werden.</strong> Bitte den folgenden Text als Datei <code>.env</code> '
                    .'in den Anwendungsordner hochladen (neben <code>artisan</code>, nicht in <code>public/</code>). Erst dann funktioniert die Anmeldung.</div>'
                    .'<pre>'.$h($result['env']).'</pre>';
            } else {
                $body .= '<div class="box ok">Die Konfiguration (.env) ist gespeichert. Dieser Assistent ist ab jetzt gesperrt.</div>';
            }

            $body .= '<fieldset><legend>Noch zwei Schritte</legend><p><strong>1. Cronjob anlegen</strong> (IONOS → Hosting → Cronjobs, jede Minute):</p>'
                .'<pre>'.$h($installer->cronCommand()).'</pre><p class="hint">Falls es den Befehl <code>php8.4-cli</code> nicht gibt, zeigt IONOS beim Anlegen des Cronjobs den richtigen PHP-Pfad an.</p>'
                .'<p><strong>2. Anmelden</strong> und die Zwei-Faktor-Anmeldung mit einer Authenticator-App einrichten:</p>'
                .'<p><a href="./"><button type="button">Zur Anmeldung</button></a></p></fieldset>';

            if ($installer->codeMode() === 'file') {
                $body .= '<fieldset><legend>Für spätere Updates über GitHub</legend><p class="hint">Diesen Wert als GitHub-Secret <code>ANLIEGO_DEPLOY_TOKEN</code> eintragen. '
                    .'Er steht auch in der .env.</p><pre>'.$h($deployToken).'</pre></fieldset>';
            }

            $page('Fertig', $body);
            exit;
        }
    }
}

// ---------------------------------------------------------------- Formular

$field = function (string $name, string $label, string $type = 'text', string $hint = '', array $attrs = []) use ($in, $errors, $h) {
    $value = in_array($type, ['password'], true) ? '' : $in[$name];
    $extra = '';
    foreach ($attrs as $k => $v) {
        $extra .= ' '.$k.'="'.$h($v).'"';
    }

    return '<div><label for="'.$h($name).'">'.$h($label).'</label>'
        .'<input id="'.$h($name).'" name="'.$h($name).'" type="'.$h($type).'" value="'.$h($value).'"'.$extra.'>'
        .($hint ? '<div class="hint">'.$hint.'</div>' : '')
        .(isset($errors[$name]) ? '<div class="err">'.$h($errors[$name]).'</div>' : '').'</div>';
};

$checks = '';
foreach ($installer->requirements() as $c) {
    $checks .= '<li class="'.($c['ok'] ? 'yes' : 'no').'">'.($c['ok'] ? '✓ ' : '✗ ').$h($c['label']).($c['ok'] ? '' : ' – <span class="hint">'.$h($c['hint']).'</span>').'</li>';
}
$ready = $installer->requirementsMet();

$codeHint = $installer->codeMode() === 'deploy'
    ? 'Der Wert, den du in GitHub als Secret <code>ANLIEGO_DEPLOY_TOKEN</code> hinterlegt hast.'
    : 'Steht in der Datei <code>storage/install-code.txt</code> im Anwendungsordner (per SFTP oder im IONOS-Dateimanager öffnen). So kann niemand außer dir die Einrichtung starten.';

$body = '<h1>Einrichtung</h1><p class="lead">Einmal ausfüllen: Anliego legt die Datenbanktabellen an, richtet deinen Administrator-Zugang ein und speichert die Konfiguration.</p>';
if ($general) {
    $body .= '<div class="box err">'.$h($general).'</div>';
}
$body .= '<fieldset><legend>Voraussetzungen</legend><ul class="checks">'.$checks.'</ul></fieldset>'
    .'<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="'.$h($_SESSION['csrf']).'">'
    .'<fieldset><legend>Zugangscode</legend><div class="grid">'.$field('code', 'Zugangscode', 'password', $codeHint).'</div></fieldset>'
    .'<fieldset><legend>Adresse</legend><div class="grid">'.$field('app_url', 'Adresse von Anliego', 'url', 'So wie sie im Browser steht, z. B. https://tickets.deine-domain.de').'</div></fieldset>'
    .'<fieldset><legend>Datenbank (MariaDB/MySQL)</legend><p class="hint">IONOS: Hosting → Datenbanken → Datenbank auswählen. Die Datenbank sollte leer sein.</p><div class="grid two">'
    .$field('db_host', 'Server (Hostname)', 'text', '', ['placeholder' => 'db12345678.hosting-data.io'])
    .$field('db_port', 'Port', 'number')
    .$field('db_database', 'Datenbankname', 'text', '', ['placeholder' => 'dbs12345678'])
    .$field('db_username', 'Benutzer', 'text', '', ['placeholder' => 'dbu12345678'])
    .$field('db_password', 'Passwort', 'password')
    .'</div></fieldset>'
    .'<fieldset><legend>Dein Administrator-Zugang</legend><div class="grid two">'
    .$field('admin_name', 'Name')
    .$field('admin_email', 'E-Mail-Adresse (Anmeldename)', 'email')
    .$field('admin_password', 'Passwort', 'password', 'Mindestens 12 Zeichen.')
    .$field('admin_password_confirm', 'Passwort wiederholen', 'password')
    .'</div></fieldset>'
    .'<fieldset><legend>Erster Mandant (optional)</legend><p class="hint">Kann auch später unter Verwaltung → Mandanten angelegt werden.</p><div class="grid two">'
    .$field('tenant_name', 'Name', 'text', '', ['placeholder' => 'TSV Beispiel e. V.'])
    .$field('tenant_code', 'Kürzel für Ticketnummern', 'text', '', ['placeholder' => 'TSV', 'maxlength' => '10'])
    .'</div></fieldset>'
    .'<fieldset><legend>E-Mails von Anliego an Mitarbeiter (optional)</legend>'
    .'<p class="hint">Für „Passwort vergessen“ und Hinweise per E-Mail. Die Postfächer für Tickets richtest du später in Anliego ein.</p>'
    .'<label class="check"><input type="checkbox" name="mail_enabled" value="1"'.($in['mail_enabled'] ? ' checked' : '').'> Jetzt einrichten</label><div class="grid two" style="margin-top:.75rem">'
    .$field('mail_host', 'SMTP-Server')
    .$field('mail_port', 'Port', 'number', '465 (SSL) oder 587 (STARTTLS)')
    .$field('mail_username', 'Benutzer', 'text', 'Meist die E-Mail-Adresse')
    .$field('mail_password', 'Passwort', 'password')
    .$field('mail_from', 'Absenderadresse', 'email', '', ['placeholder' => 'anliego@deine-domain.de'])
    .'</div></fieldset>'
    .'<button type="submit"'.($ready ? '' : ' disabled').'>Anliego einrichten</button>'
    .($ready ? '' : '<p class="err">Erst die fehlenden Voraussetzungen beheben, dann die Seite neu laden.</p>')
    .'</form>';

$page('Einrichtung', $body);
