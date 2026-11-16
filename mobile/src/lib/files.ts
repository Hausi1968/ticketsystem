import { Alert, Linking, Platform } from 'react-native';
import { Directory, File, Paths } from 'expo-file-system';
import * as IntentLauncher from 'expo-intent-launcher';
import * as Sharing from 'expo-sharing';
import type { Attachment } from './api';

/** Anhang laden und mit der passenden App öffnen (PDF-Anzeige, Bilder, Office …) */
export async function openAttachment(att: Attachment, headers: Record<string, string>): Promise<void> {
  if (Platform.OS === 'web') {
    const res = await fetch(att.url, { headers });
    const blob = await res.blob();
    Linking.openURL(URL.createObjectURL(blob));
    return;
  }
  const dir = new Directory(Paths.cache, 'anhaenge');
  if (!dir.exists) dir.create({ intermediates: true });
  const safe = att.filename.replace(/[^\w.\-äöüÄÖÜß ]+/g, '_').slice(-120) || 'datei';
  const target = new File(dir, `${att.id}-${safe}`);
  const file = target.exists ? target : await File.downloadFileAsync(att.url, target, { headers, idempotent: true });

  if (Platform.OS === 'android') {
    try {
      await IntentLauncher.startActivityAsync('android.intent.action.VIEW', {
        data: file.contentUri,
        flags: 1, // FLAG_GRANT_READ_URI_PERMISSION
        type: att.mime_type || 'application/octet-stream',
      });
      return;
    } catch {
      // keine App für diesen Dateityp – stattdessen „Teilen“ anbieten
    }
  }
  if (await Sharing.isAvailableAsync()) {
    await Sharing.shareAsync(file.uri, { mimeType: att.mime_type, dialogTitle: att.filename });
  } else {
    Alert.alert('Anhang', 'Auf diesem Gerät gibt es keine App, die diese Datei öffnen kann.');
  }
}
