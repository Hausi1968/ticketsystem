# Anliego auf IONOS-Webhosting einrichten

Diese Anleitung beschreibt die Ersteinrichtung auf einem IONOS-Webhosting-Paket mit eigener Subdomain. Danach lädt GitHub jede neue Version automatisch hoch.

Voraussetzungen: IONOS-Webhosting mit PHP 8.4, MariaDB/MySQL-Datenbank, SFTP-Zugang und Cronjobs.

## Überblick

```
Webspace (SFTP)
└── anliego/                 ← Anwendung (IONOS_TARGET_DIR)
    ├── app/, vendor/, …
    ├── .env                 ← Zugangsdaten, nur auf dem Server
    ├── storage/             ← Logs, Anhänge; wird beim Deploy nie überschrieben
    └── public/              ← Ziel der Subdomain (Document-Root)
```

Wichtig: Die Subdomain zeigt auf `anliego/public`, **nicht** auf `anliego/`. Sonst wären `.env` und der Programmcode aus dem Internet abrufbar.

## 1. Datenbank anlegen

IONOS → *Hosting* → *Datenbanken* → *Datenbank anlegen* (MariaDB/MySQL). Notiere Hostname (`db….hosting-data.io`), Datenbankname, Benutzer und Passwort.

## 2. Subdomain anlegen

1. IONOS → *Domains & SSL* → Domain auswählen → *Subdomain hinzufügen*, z. B. `tickets.deine-domain.de`.
2. Als Ziel *Webspace* wählen und den Ordner `/anliego/public` eintragen (Ordner ggf. vorher per SFTP anlegen).
3. SSL-Zertifikat für die Subdomain aktivieren.
4. PHP-Version für den Webspace auf **8.4** prüfen (*Hosting* → *PHP-Einstellungen*).

## 3. SFTP-Zugang

IONOS → *Hosting* → *SFTP & SSH* → Benutzer anlegen. Notiere Server (z. B. `access-….webspace-host.com`), Benutzer und Passwort.

## 4. `.env` auf dem Server anlegen

1. Vorlage [`.env.production.example`](../.env.production.example) kopieren und ausfüllen:
   - `APP_KEY`: lokal erzeugen mit `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`
   - `APP_URL`: `https://tickets.deine-domain.de`
   - Datenbankdaten aus Schritt 1
   - `ANLIEGO_DEPLOY_TOKEN`: lange Zufallszeichenfolge, z. B. `php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"`
   - `ANLIEGO_INITIAL_ADMIN_EMAIL` und `ANLIEGO_INITIAL_ADMIN_PASSWORD` (mind. 12 Zeichen) für deinen ersten Zugang
2. Die Datei als `.env` per SFTP in den Ordner `/anliego/` hochladen (nicht in `public/`).

## 5. GitHub einrichten

Im Repository → *Settings* → *Secrets and variables* → *Actions*:

| Art | Name | Wert |
|---|---|---|
| Secret | `IONOS_SFTP_HOST` | SFTP-Server aus Schritt 3 |
| Secret | `IONOS_SFTP_USER` | SFTP-Benutzer |
| Secret | `IONOS_SFTP_PASSWORD` | SFTP-Passwort |
| Secret | `IONOS_TARGET_DIR` | Pfad des Ordners, z. B. `/anliego` |
| Secret | `ANLIEGO_DEPLOY_TOKEN` | derselbe Wert wie in der `.env` |
| Variable | `ANLIEGO_URL` | `https://tickets.deine-domain.de` |
| Variable | `DEPLOY_ON_PUSH` | `true`, wenn jede Änderung auf `claude/ticketsystem/main` automatisch live gehen soll (sonst weglassen) |

Unter *Settings* → *Environments* wird die Umgebung `production` beim ersten Lauf automatisch angelegt. Dort lässt sich auf Wunsch eine Freigabe vor jedem Deploy einstellen.

## 6. Erstes Deployment

GitHub → *Actions* → *Deploy auf IONOS* → *Run workflow*.

Der Workflow installiert die Abhängigkeiten, lädt alles per SFTP hoch und ruft danach `POST /_deploy/migrate` auf. Dabei werden die Datenbanktabellen angelegt und – beim ersten Mal – dein Administrator-Zugang aus der `.env`.

**Danach:** `ANLIEGO_INITIAL_ADMIN_PASSWORD` aus der `.env` auf dem Server löschen.

## 7. Cronjob einrichten

IONOS → *Hosting* → *Cronjobs* → *Cronjob anlegen*:

- Typ: *Befehl / Skript*
- Befehl: `/usr/bin/php8.4-cli /PFAD/ZUM/WEBSPACE/anliego/artisan schedule:run`
- Intervall: **jede Minute** (oder das kürzeste angebotene Intervall)

Den vollständigen Pfad zum Webspace zeigt IONOS bei der Einrichtung des Cronjobs bzw. per SSH mit `pwd` an. Ob der PHP-Befehl `php8.4-cli` heißt, lässt sich per SSH mit `ls /usr/bin/php*` prüfen.

Die Übersicht in Anliego zeigt unter **Cronjob**, wann er zuletzt gelaufen ist. Steht dort „prüfen“, läuft der Cronjob nicht.

## 8. Erste Anmeldung

1. `https://tickets.deine-domain.de` öffnen und mit dem Administrator-Zugang anmelden.
2. Die Zwei-Faktor-Anmeldung mit einer Authenticator-App (z. B. Microsoft Authenticator, Google Authenticator, 2FAS) einrichten. Sie ist für alle Mitarbeiter Pflicht. Die Wiederherstellungscodes sicher aufbewahren.
3. Unter *Verwaltung* Mandanten, Mitarbeiter und Teams anlegen.

## Fehlersuche

| Problem | Lösung |
|---|---|
| Fehler 500 | `storage/logs/laravel-*.log` per SFTP ansehen. Häufig: `APP_KEY` fehlt oder Datenbankdaten falsch. |
| Seite zeigt Ordnerinhalt oder `.env` ist abrufbar | Subdomain zeigt nicht auf `public/`. Sofort korrigieren (Schritt 2). |
| Deploy-Schritt „Migrationen“ meldet 404 | `ANLIEGO_DEPLOY_TOKEN` in `.env` und GitHub-Secret stimmen nicht überein, oder `.env` fehlt. |
| Cronjob „prüfen“ | Befehl und Pfad im IONOS-Cronjob prüfen; die Ausgabe schickt IONOS auf Wunsch per E-Mail. |
| Zwei-Faktor-Gerät verloren | Wiederherstellungscode verwenden, oder ein anderer Administrator setzt es zurück (folgt in Phase 1). |
