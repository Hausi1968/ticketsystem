import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';

/**
 * Sicherer Speicher für Server-Adresse und Anmelde-Token.
 * Auf Android/iOS: verschlüsselter Schlüsselbund (SecureStore). Im Browser (nur zum Testen): localStorage.
 */
export async function getItem(key: string): Promise<string | null> {
  if (Platform.OS === 'web') {
    try {
      return globalThis.localStorage?.getItem(key) ?? null;
    } catch {
      return null;
    }
  }
  return SecureStore.getItemAsync(key);
}

export async function setItem(key: string, value: string): Promise<void> {
  if (Platform.OS === 'web') {
    try {
      globalThis.localStorage?.setItem(key, value);
    } catch {
      // Browser ohne Speicher – Anmeldung gilt dann nur für diese Sitzung
    }
    return;
  }
  await SecureStore.setItemAsync(key, value);
}

export async function deleteItem(key: string): Promise<void> {
  if (Platform.OS === 'web') {
    try {
      globalThis.localStorage?.removeItem(key);
    } catch {
      // ignorieren
    }
    return;
  }
  await SecureStore.deleteItemAsync(key);
}
