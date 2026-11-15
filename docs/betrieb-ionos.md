# Anliego auf IONOS-Webhosting einrichten

Diese Anleitung beschreibt die Ersteinrichtung auf einem IONOS-Webhosting-Paket mit eigener Subdomain. Die Zugangsdaten trägst du dabei in einem Einrichtungsassistenten im Browser ein. Danach lädt GitHub jede neue Version automatisch hoch.

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

## 4. GitHub einrichten

Einen langen Zufallswert als **Deploy-Token** ausdenken oder erzeugen, z. B. mit einem Passwortgenerator (mindestens 32 Zeichen) oder `php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"`. Er dient später als Zugangscode für den Installationsassistenten.

Im Repository → *Settings* → *Secrets and variables* → *Actions*:

| Art | Name | Wert |
|---|---|---|
| Secret | `IONOS_SFTP_HOST` | SFTP-Server aus Schritt 3 |
| Secret | `IONOS_SFTP_USER` | SFTP-Benutzer |
| Secret | `IONOS_SFTP_PASSWORD` | SFTP-Passwort |
| Secret | `IONOS_TARGET_DIR` | Pfad des Ordners, z. B. `/anliego` |
| Secret | `ANLIEGO_DEPLOY_TOKEN` | der Deploy-Token |
| Variable | `ANLIEGO_URL` | `https://tickets.deine-domain.de` |
| Variable | `DEPLOY_ON_PUSH` | `true`, wenn jede Änderung auf `claude/ticketsystem/main` automatisch live gehen soll (sonst weglassen) |

Unter *Settings* → *Environments* wird die Umgebung `production` beim ersten Lauf automatisch angelegt. Dort lässt sich auf Wunsch eine Freigabe vor jedem Deploy einstellen.

## 5. Hochladen

GitHub → *Actions* → *Deploy auf IONOS* → *Run workflow*.

Der Workflow installiert die Abhängigkeiten und lädt alles per SFTP hoch. Beim ersten Mal endet er mit dem Hinweis „Anliego noch nicht eingerichtet“. Das ist richtig so.

## 6. Einrichtungsassistent

`https://tickets.deine-domain.de` im Browser öffnen. Solange Anliego nicht eingerichtet ist, erscheint der Assistent (`/install.php`):

1. **Voraussetzungen** werden geprüft (PHP-Version, Erweiterungen, Schreibrechte).
2. **Zugangscode:** der Deploy-Token aus Schritt 4. Ohne ihn kann niemand sonst die Einrichtung starten.
3. **Datenbank** aus Schritt 1 eintragen. Der Assistent prüft die Verbindung und meldet z. B. ein falsches Passwort verständlich.
4. **Administrator-Zugang** (Name, E-Mail, Passwort mit mindestens 12 Zeichen), optional der **erste Mandant** und der **E-Mail-Versand** für Systemmails.
5. *Anliego einrichten*: Die Tabellen und Grunddaten (Status) werden angelegt, der Administrator eingerichtet und zum Schluss die `.env` mit einem neuen `APP_KEY` gespeichert (Rechte 600).

Danach ist der Assistent gesperrt. Ab dem nächsten Upload führt der Workflow die Datenbank-Änderungen automatisch aus.

Konnte die `.env` nicht gespeichert werden, zeigt der Assistent ihren Inhalt an. Dann diesen Text als Datei `.env` per SFTP in den Ordner `/anliego/` hochladen (nicht in `public/`).

**Ohne GitHub** (Dateien per SFTP selbst hochgeladen, inklusive `vendor/`): Der Assistent legt beim ersten Aufruf einen Zugangscode in `storage/install-code.txt` ab. Die Datei per SFTP oder im IONOS-Dateimanager öffnen. Den Deploy-Token zeigt der Assistent am Ende an, für den Fall, dass später doch über GitHub aktualisiert wird.

**Neu einrichten:** `.env` per SFTP löschen oder umbenennen, dann öffnet sich der Assistent wieder. Die Daten in der Datenbank bleiben erhalten; der Administrator wird anhand der E-Mail-Adresse aktualisiert.

Die frühere Einrichtung mit einer selbst geschriebenen `.env` (Vorlage [`.env.production.example`](../.env.production.example)) funktioniert weiterhin.

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
3. Unter *Verwaltung* Mandanten, Mitarbeiter und Teams anlegen. Unter *Status* und *Kategorien* die Status (mit „schließt das Ticket ja/nein“) und Ticketkategorien nach Bedarf anpassen.
4. Unter *Fristen (SLA)* je Mandant eine Richtlinie mit Geschäftszeiten und Feiertagen anlegen (optional).

## 9. Postfächer anbinden

Für jede Adresse, aus der Tickets werden sollen (z. B. `auftrag@firma.de`), unter *Verwaltung → Postfächer* ein Postfach anlegen:

| Feld | IONOS-Postfach | Hinweis |
|---|---|---|
| IMAP-Server / Port / Verschlüsselung | `imap.ionos.de` / `993` / SSL/TLS | |
| SMTP-Server / Port / Verschlüsselung | `smtp.ionos.de` / `465` / SSL/TLS | |
| Benutzername | die E-Mail-Adresse | Passwörter werden verschlüsselt gespeichert |
| Abruf alle … Minuten | z. B. `5` oder `10` | Der Cronjob läuft jede Minute, das Postfach wird aber nur in diesem Abstand abgerufen |
| Verarbeitete E-Mails verschieben nach | z. B. `Anliego` | Ordner vorher im Webmail anlegen. Leer = nur als gelesen markieren |

Danach in der Liste *Verbindung testen* und *Jetzt abrufen*. Fehler beim Abruf stehen in der Liste und auf der Übersicht.

Wichtig:

- Anliego holt nur **ungelesene** E-Mails. Wer das Postfach zusätzlich in Outlook öffnet, sollte dort nichts als gelesen markieren, bevor Anliego abgerufen hat (oder Anliego bekommt ein eigenes Postfach bzw. eine Weiterleitung).
- Abwesenheitsnotizen, Unzustellbarkeitsmeldungen und E-Mails aus den eigenen Postfächern werden erkannt und erzeugen kein Ticket.
- Antworten gehen über das SMTP des Postfachs, mit der Ticketnummer im Betreff (z. B. `[#MUS-1000]`). Antwortet der Kunde, landet seine E-Mail wieder im selben Ticket.
- Anhänge bis 100 MB: Dafür liegt `public/.user.ini` bei. Falls IONOS die Werte nicht übernimmt, `upload_max_filesize` und `post_max_size` im IONOS-Kundenbereich unter *PHP-Einstellungen* erhöhen.
- **Eingangsbestätigung:** Je Postfach einschaltbar, der Text ist anpassbar (Platzhalter `{name}`, `{nummer}`, `{betreff}`, `{mandant}`, `{absender}`). Sie geht über das SMTP des Postfachs, sobald ein neues Ticket entsteht, auch bei Meldungen aus Anwendungen. Keine Bestätigung bekommen `noreply@`-Absender (z. B. Webshop-Bestellungen), Newsletter, die eigenen Postfächer, und jeder Kunde höchstens eine in 15 Minuten.

## 10. Benachrichtigungen für Mitarbeiter

Mitarbeiter sehen Hinweise in Anliego an der Glocke oben rechts:

- **Neues Ticket:** an die Mitglieder des Teams, sonst an alle Mitarbeiter des Mandanten
- **Zuweisung:** an den neuen Bearbeiter (nicht, wenn er sich das Ticket selbst nimmt)
- **Kundenantwort:** an den Bearbeiter, ohne Bearbeiter wie bei einem neuen Ticket

Zusätzlich kommen sie per E-Mail. Jeder stellt im **Profil** (oben rechts → Profil) ein, welche. Diese E-Mails gehen nicht über die Postfächer der Mandanten, sondern über eine eigene Absenderadresse von Anliego. Dafür in der `.env` auf dem Server zum Beispiel eintragen:

```
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.ionos.de
MAIL_PORT=465
MAIL_USERNAME=anliego@deine-domain.de
MAIL_PASSWORD=…
MAIL_FROM_ADDRESS=anliego@deine-domain.de
MAIL_FROM_NAME="Anliego"
```

Die E-Mails werden über die Warteschlange verschickt, also innerhalb einer Minute durch den Cronjob. Ohne diese Einstellungen landen sie nur in `storage/logs`, die Hinweise an der Glocke funktionieren trotzdem.

## Fehlersuche

| Problem | Lösung |
|---|---|
| Fehler 500 | `storage/logs/laravel-*.log` per SFTP ansehen. Häufig: `APP_KEY` fehlt oder Datenbankdaten falsch. |
| Seite zeigt Ordnerinhalt oder `.env` ist abrufbar | Subdomain zeigt nicht auf `public/`. Sofort korrigieren (Schritt 2). |
| Deploy-Schritt „Migrationen“ meldet 404 | `ANLIEGO_DEPLOY_TOKEN` in `.env` und GitHub-Secret stimmen nicht überein, oder `.env` fehlt. |
| Cronjob „prüfen“ | Befehl und Pfad im IONOS-Cronjob prüfen; die Ausgabe schickt IONOS auf Wunsch per E-Mail. |
| Postfach zeigt „Fehler“ | *Verbindung testen*; häufig falsches Passwort oder Ordnername. Nach der Korrektur *Jetzt abrufen*. |
| Antwort „Versand fehlgeschlagen“ | SMTP-Daten des Postfachs prüfen. Anliego versucht den Versand automatisch bis zu fünfmal erneut (bis etwa 1,5 Stunden). |
| Zwei-Faktor-Gerät verloren | Wiederherstellungscode verwenden, oder ein anderer Administrator setzt es zurück (folgt in Phase 1). |
