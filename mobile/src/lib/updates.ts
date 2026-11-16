import { useEffect, useRef } from 'react';
import { AppState, Platform } from 'react-native';
import * as Updates from 'expo-updates';

/** Updates „aus der Luft“ gibt es nur in der installierten App (nicht im Browser, nicht im Entwicklungsmodus) */
export const updatesEnabled = Platform.OS !== 'web' && Updates.isEnabled && !__DEV__;

const CHECK_INTERVAL_MS = 30 * 60 * 1000;

/** Sucht nach einer neuen Version und lädt sie herunter; true, wenn eine neue Version bereitliegt */
export async function checkAndFetchUpdate(): Promise<boolean> {
  if (!updatesEnabled) return false;
  const check = await Updates.checkForUpdateAsync();
  if (!check.isAvailable) return false;
  const result = await Updates.fetchUpdateAsync();
  return result.isNew;
}

/**
 * Beim Start prüft die App selbst; viele lassen sie aber tagelang im Hintergrund offen.
 * Deshalb zusätzlich prüfen, wenn die App wieder in den Vordergrund kommt (höchstens alle 30 Minuten).
 */
export function useForegroundUpdateCheck() {
  const last = useRef(Date.now());
  useEffect(() => {
    if (!updatesEnabled) return;
    const sub = AppState.addEventListener('change', (state) => {
      if (state !== 'active' || Date.now() - last.current < CHECK_INTERVAL_MS) return;
      last.current = Date.now();
      checkAndFetchUpdate().catch(() => {
        // offline o.ä. – beim nächsten Mal erneut versuchen
      });
    });
    return () => sub.remove();
  }, []);
}
