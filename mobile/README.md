# Anliego – Android-App für Mitarbeiter

Expo-App (SDK 57, Expo Router) für unterwegs: Tickets lesen, suchen, übernehmen, zuweisen,
Status/Priorität/Kategorie ändern, Zeit erfassen, antworten und interne Notizen schreiben – mit Anhängen
und Push-Benachrichtigungen. Verwaltung (Postfächer, Regeln, Benutzer) bleibt im Browser.

Schnittstelle: `docs/agent-api.md` (`/api/agent/v1`). Anmeldung mit E-Mail, Passwort und Code aus der
Authenticator-App; die Zwei-Faktor-Anmeldung wird im Browser eingerichtet.

## Aufbau
- `src/app/login.tsx` – Anmeldung (Adresse, E-Mail, Passwort, 2FA-Code oder Wiederherstellungscode)
- `src/app/(app)/index.tsx` – Ticketliste mit Reitern wie im Browser, Suche über alle Tickets, Mandantenfilter
- `src/app/(app)/ticket/[id].tsx` – Ticket mit Verlauf, Anhängen, wer es gerade offen hat, Bearbeiten
- `src/app/(app)/schreiben.tsx` – Antwort oder Notiz mit Kopie, Status danach, Zeit und Anhängen (Datei, Foto, Kamera)
- `src/lib/push.ts` – Push über Expo; Tippen auf die Benachrichtigung öffnet das Ticket

## Entwickeln
```bash
cd mobile
npm install
npx tsc --noEmit
npx expo start            # Expo Go / Entwicklungs-Build
npx expo export --platform web   # schneller Test im Browser (gegen http://127.0.0.1:8000)
```

## APK bauen (EAS)
Projekt: https://expo.dev/accounts/dc-neuwerk-1986-ev/projects/ticketsystem.
Projekt-ID `a9cf77c0-5014-441c-ac46-37ef65693b87` (steht in `app.json`). Für Builds über GitHub muss im
Expo-Projekt unter Settings → GitHub das Repository `Hausi1968/ticketsystem` mit Basisverzeichnis `mobile` verbunden sein.

```bash
npx eas-cli@latest build --platform android --profile preview   # APK zum direkten Installieren
npx eas-cli@latest update --channel preview                     # reine JS-Änderungen „aus der Luft“
```
Neue native Bausteine (Pakete mit nativem Code, Berechtigungen) brauchen eine neue APK.
