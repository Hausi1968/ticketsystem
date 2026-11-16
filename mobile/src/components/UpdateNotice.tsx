import { useState } from 'react';
import { Alert, StyleProp, Text, View, ViewStyle } from 'react-native';
import * as Updates from 'expo-updates';
import { checkAndFetchUpdate, updatesEnabled } from '@/lib/updates';
import { Button, colors } from '@/components/ui';

/** Hinweis „Neue Version geladen – jetzt neu starten“; erscheint nur, wenn ein Update bereitliegt */
export function UpdateReadyNotice({ style }: { style?: StyleProp<ViewStyle> }) {
  const { isUpdatePending } = Updates.useUpdates();
  if (!updatesEnabled || !isUpdatePending) return null;
  return (
    <View style={[{ backgroundColor: colors.okBg, borderRadius: 12, padding: 12, gap: 8 }, style]}>
      <Text style={{ color: colors.ok, fontWeight: '700' }}>Eine neue App-Version ist geladen.</Text>
      <Text style={{ color: colors.text, fontSize: 13 }}>Sie wird beim nächsten Öffnen der App aktiv – oder sofort mit „Jetzt neu starten“.</Text>
      <Button title="Jetzt neu starten" icon="refresh" onPress={() => Updates.reloadAsync()} />
    </View>
  );
}

/** Stand der App und Knopf „Nach Updates suchen“ (unter Mehr → Konto) */
export function UpdateStatus() {
  const { isChecking, isDownloading, isUpdatePending } = Updates.useUpdates();
  const [busy, setBusy] = useState(false);
  if (!updatesEnabled) return null;
  const search = async () => {
    setBusy(true);
    try {
      const fetched = await checkAndFetchUpdate();
      if (!fetched && !isUpdatePending) Alert.alert('App ist aktuell', 'Es gibt keine neuere Version für diese App.');
    } catch (e) {
      // Den echten Grund zeigen (z.B. Fehler beim Update-Server) – nicht pauschal „Keine Verbindung“
      const reason = e instanceof Error && e.message ? e.message : 'unbekannter Fehler';
      Alert.alert('Update nicht möglich', `Die Suche nach Updates hat nicht geklappt.\n\nGrund: ${reason}\n\nBitte später erneut versuchen oder dem Administrator Bescheid geben.`);
    } finally {
      setBusy(false);
    }
  };
  const working = busy || isChecking || isDownloading;
  return (
    <View style={{ marginTop: 6 }}>
      <Text style={{ color: colors.muted, fontSize: 12 }}>
        {Updates.isEmbeddedLaunch || !Updates.createdAt ? 'Stand: wie installiert' : `Stand: Update vom ${dateDe(Updates.createdAt)}`}
      </Text>
      <Button
        title={working ? 'Suche nach Updates …' : 'Nach Updates suchen'}
        icon="cloud-download-outline"
        variant="ghost"
        onPress={search}
        disabled={working}
        style={{ marginTop: 10 }}
      />
    </View>
  );
}

function dateDe(d: Date): string {
  const p = (n: number) => String(n).padStart(2, '0');
  return `${p(d.getDate())}.${p(d.getMonth() + 1)}.${d.getFullYear()}`;
}
