# Mitarbeiter-App: Schnittstelle

Grundadresse: `https://<anliego>/api/agent/v1`. Alle Antworten sind JSON. Nach der Anmeldung jede Anfrage mit
`Authorization: Bearer agt_…`. Die Mandantentrennung ist dieselbe wie in der Weboberfläche: Ein Mitarbeiter sieht nur
Tickets der Mandanten, die er betreut (Administratoren alle).

## Anmelden
- `POST /login` `{email, password, device}` → `{token, id, name, tenants…}` oder `{mfa_required: true, mfa_token}`
- `POST /login/mfa` `{mfa_token, code}` – 6-stelliger Code aus der Authenticator-App oder ein Wiederherstellungscode
- Ist `ANLIEGO_MFA_PFLICHT` an und die Zwei-Faktor-Anmeldung noch nicht eingerichtet: `403 {error: "mfa_setup"}` – erst im Browser einrichten
- `POST /logout` beendet die Anmeldung dieses Geräts, `GET /me`

## Tickets
- `GET /tickets?tab=mine|unassigned|open|pending|closed|all&search=…&tenant_id=…&page=…` (30 je Seite, `has_more`).
  Mit `search` wird wie im Browser in allen Tickets gesucht (Betreff, Nachrichten, Notizen, Absender, Ticketnummer).
- `GET /tickets/{id}` – Ticket mit Verlauf (`messages`, `events`), Anhängen, `can` (reply/note/edit/take), `others`
  (wer das Ticket gerade offen hat) und `last_message_id`. `?writing=1` meldet „schreibt gerade“.
- `POST /tickets/{id}/reply` `{body, cc?, status_id?, minutes?, upload_ids?, last_message_id?, force?}` –
  ist seit `last_message_id` eine neue Nachricht eingegangen, kommt `409 {error: "new_messages", ticket}`; mit `force: true` trotzdem senden.
- `POST /tickets/{id}/note` `{body, minutes?, upload_ids?}`
- `POST /tickets/{id}/take`, `/assign {assignee_id|null}`, `/status {status_id}`, `/category {category_id|null}`,
  `/priority {priority}`, `/time {minutes, note?}` – Antwort jeweils `{ok, ticket}`
- `POST /uploads` (multipart, Feld `file`, bis 20 MB) → `{id}` für `upload_ids`
- `GET /attachments/{id}` – Anhang herunterladen
- `GET /options` – Reiter, Status, Prioritäten, Kategorien, Mandanten und mögliche Bearbeiter (`tenant_ids`)

## Push-Nachrichten
`POST /push-token {push_token: "ExponentPushToken[…]"}` (null = aus). Neue Tickets, Zuweisungen, Kundenantworten und
Fristen kommen dann zusätzlich als Push über den Expo-Push-Dienst; `data.ticket_id` öffnet das Ticket.
