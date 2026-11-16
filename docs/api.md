# Anliego Ticket-Schnittstelle (API v1)

Über diese Schnittstelle legen Anwendungen eines Mandanten (z. B. die Mitgliederverwaltung oder deren Handy-App)
Tickets an, laden Anhänge hoch und zeigen ihren Nutzern den Stand ihrer Meldungen.

Basis-URL: `https://<eure-anliego-subdomain>/api/v1`

## 1. API-Schlüssel

Ein Administrator legt den Schlüssel unter **Verwaltung → API-Schlüssel** an:

- Mandant (Pflicht): jeder Schlüssel gehört genau zu einem Mandanten. Tickets landen immer bei diesem Mandanten.
- Postfach (optional): über dieses Postfach gehen später die Antworten per E-Mail an den Nutzer.
  Ohne Angabe wird das erste aktive Postfach des Mandanten genommen.
- Team (optional): neue Tickets werden diesem Team zugeordnet.

Der Schlüssel (`ank_…`) wird **nur einmal angezeigt**. Gespeichert wird nur ein Hash. Geht er verloren,
einen neuen anlegen und den alten widerrufen. Den Schlüssel nur auf dem Server der Anwendung ablegen,
nie in der Handy-App selbst: die App spricht mit dem eigenen Server, und der ruft Anliego auf.

Jede Anfrage schickt den Schlüssel im Header:

```
Authorization: Bearer ank_…
Accept: application/json
```

## 2. Ticket anlegen

`POST /tickets` (JSON)

```json
{
  "subject": "Beitrag wird falsch angezeigt",
  "description": "Bei mir steht 120 € statt 60 €.",
  "category": "App-Fehler",
  "priority": "high",
  "reporter": {
    "email": "jonas@mitglied.de",
    "name": "Jonas K.",
    "external_user_id": "u_4711"
  },
  "context": { "url": "/beitraege", "app_version": "2.3.1", "browser": "Firefox 132" },
  "attachment_ids": ["att_01J…"]
}
```

| Feld | Pflicht | Hinweis |
|---|---|---|
| `subject` | ja | bis 200 Zeichen |
| `description` | ja | Text, bis 50 000 Zeichen |
| `reporter.email` | ja | an diese Adresse gehen die Antworten |
| `reporter.name` | nein | |
| `reporter.external_user_id` | nein | eure Nutzer-ID; damit findet `GET /tickets` die Meldungen des Nutzers |
| `category` | nein | Name einer Kategorie (Groß-/Kleinschreibung egal). Unbekannte Namen werden ignoriert. |
| `priority` | nein | `low`, `normal` (Standard), `high`, `urgent` |
| `context` | nein | bis 30 einfache Werte (je bis 2000 Zeichen). Nur für Mitarbeiter sichtbar („Technische Details“). |
| `attachment_ids` | nein | bis 20 IDs aus `POST /attachments` |

Antwort `201 Created`:

```json
{
  "number": 1000,
  "reference": "MUS-1000",
  "subject": "Beitrag wird falsch angezeigt",
  "status": { "name": "Neu", "state": "open" },
  "category": "App-Fehler",
  "priority": "high",
  "created_at": "2026-11-14T18:02:11+00:00",
  "updated_at": "2026-11-14T18:02:11+00:00"
}
```

`status.state` ist immer einer von `open`, `pending` (wartet z. B. auf den Nutzer) oder `closed`.
Der Name (`status.name`) kann sich ändern, weil Status frei angelegt werden können.

**Kontakt:** Gibt es zur `external_user_id` schon einen Kontakt, wird er verwendet; sonst der Kontakt mit der
gleichen E-Mail-Adresse (z. B. aus früheren E-Mails), sonst ein neuer. So landen App-Meldungen und E-Mails einer
Person beim selben Kontakt.

### Eigene Felder

Sind unter *Verwaltung → Eigene Felder* Felder angelegt (z. B. Kennung `bestellnummer`), kann die Anwendung sie mitschicken:

```json
"fields": { "bestellnummer": "A-4711", "bereich": "Verkauf" }
```

Unbekannte Kennungen werden ignoriert, ungültige Werte (z. B. ein Auswahlwert, den es nicht gibt) nicht gespeichert. `GET /tickets/{nummer}` liefert die Felder unter `fields` zurück.

### Doppelte Tickets vermeiden

Bei Netzwerkfehlern darf die Anwendung die Anfrage wiederholen, wenn sie einen eigenen Schlüssel mitschickt:

```
Idempotency-Key: 6f1c2e0a-…   (z. B. eine UUID je Meldung, bis 100 Zeichen)
```

Kommt derselbe Schlüssel noch einmal, legt Anliego kein zweites Ticket an, sondern antwortet mit `200 OK`,
dem Header `Idempotent-Replayed: true` und dem schon angelegten Ticket.

## 3. Anhänge

Anhänge werden **vorher** einzeln hochgeladen, dann beim Ticket mit ihrer ID angegeben.

`POST /attachments` (multipart/form-data, Feld `file`)

```json
{ "id": "att_01JCX…", "filename": "screenshot.png", "size": 48211, "mime_type": "image/png" }
```

- Alle Dateiarten sind erlaubt (Bilder, PDF, DOCX, XLSX, APK …), bis 100 MB je Datei (`ANLIEGO_API_MAX_UPLOAD_MB`).
  Auf dem Webspace begrenzen zusätzlich `upload_max_filesize`/`post_max_size` in `public/.user.ini`.
- Eine ID gilt nur für den eigenen Mandanten und kann nur **einmal** verwendet werden.
- Nicht verwendete Uploads werden nach 24 Stunden gelöscht.

## 4. Meldungen eines Nutzers abrufen

`GET /tickets?external_user_id=u_4711` (oder `?email=jonas@mitglied.de`)

```json
{ "data": [ { "reference": "MUS-1000", "status": { "name": "Wartet auf Kunde", "state": "pending" }, … } ] }
```

Die neuesten 50 Tickets, jeweils in der Kurzform wie oben.

## 5. Ein Ticket mit Verlauf

`GET /tickets/MUS-1000` (die Nummer allein, `1000`, geht auch)

Kurzform plus `messages`: die Meldung des Nutzers und die Antworten des Teams, jeweils mit `type`
(`customer` / `reply`), `author`, `body`, `created_at` und `attachments` (Dateiname, Größe).
**Interne Notizen und die technischen Details werden nie ausgeliefert.**

### Antworten

`POST /tickets/MUS-1000/messages`

```json
{ "body": "Es ist der Familienbeitrag.", "reporter": { "external_user_id": "u_4711" }, "attachment_ids": [] }
```

- Antworten darf nur, wer das Ticket angelegt hat (`external_user_id` oder `email`) oder per CC beteiligt ist, sonst `403`.
- Wirkt wie eine Antwort per E-Mail: Ein wartendes oder erledigtes Ticket wird wieder offen, der Bearbeiter bekommt einen Hinweis.
- Ist beim erledigten Status „Kundenantwort → neues Ticket“ eingestellt, entsteht ein **Folgeticket**.
  Die Antwort (`201`) ist die Kurzform des Tickets, das die Nachricht bekommen hat, plus `"follow_up": true|false`.

### Zusammengeführte Tickets

Hat das Team ein Ticket mit einem anderen zusammengeführt, liefern `GET /tickets/{nummer}` und `POST /tickets/{nummer}/messages` für die alte Nummer das Ziel-Ticket (an `reference` in der Antwort erkennbar). Antworten des Nutzers landen also automatisch im richtigen Ticket. In der Liste der Meldungen bleibt das alte Ticket als geschlossen stehen.

## 6. Fehler

Fehler kommen immer als JSON.

| Code | Bedeutung |
|---|---|
| 401 | Schlüssel fehlt, ist falsch oder widerrufen |
| 403 | Mandant ist deaktiviert, oder die Antwort kommt nicht vom Ersteller des Tickets |
| 404 | Ticket nicht gefunden (oder gehört zu einem anderen Mandanten) |
| 422 | Eingaben ungültig, Details in `errors`, z. B. `{"errors": {"reporter.email": ["…"]}}` |
| 429 | Mehr als 60 Anfragen pro Minute je Schlüssel; Header `Retry-After` beachten |

## 7. PHP-Client

Für PHP-Anwendungen (ab PHP 7.4, nur cURL nötig, kein Composer) liegt ein fertiger Client in
[`clients/php/AnliegoClient.php`](../clients/php/AnliegoClient.php). Die Datei einfach ins Projekt kopieren:

```php
require __DIR__ . '/AnliegoClient.php';

$anliego = new AnliegoClient('https://support.example.de', getenv('ANLIEGO_API_KEY'));

try {
    $upload = $anliego->uploadAttachment($_FILES['screenshot']['tmp_name'], $_FILES['screenshot']['name']);

    $ticket = $anliego->createTicket([
        'subject' => $betreff,
        'description' => $beschreibung,
        'reporter' => ['email' => $user['email'], 'name' => $user['name'], 'external_user_id' => (string) $user['id']],
        'context' => ['url' => $seite, 'app_version' => APP_VERSION],
        'attachment_ids' => [$upload['id']],
    ], bin2hex(random_bytes(16)));

    echo 'Deine Meldung hat die Nummer ' . $ticket['reference'];
} catch (AnliegoException $e) {
    // $e->getCode() = HTTP-Status, $e->response = Antwort von Anliego
}

$meine = $anliego->ticketsOfUser((string) $user['id']);
$verlauf = $anliego->ticket('MUS-1000');
```

Den Idempotency-Key am besten einmal pro Meldung erzeugen und bei einer Wiederholung derselben Meldung wiederverwenden.
