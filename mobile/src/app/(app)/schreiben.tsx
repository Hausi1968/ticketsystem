import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Platform, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import * as DocumentPicker from 'expo-document-picker';
import * as ImagePicker from 'expo-image-picker';
import { BRAND, useSession } from '@/lib/session';
import { ApiError, TicketDetail, TicketUpdate } from '@/lib/api';
import { fileSize } from '@/lib/format';
import { Button, Card, colors, ErrorBox, Label, Loading, Notice, Screen } from '@/components/ui';
import { SelectSheet } from '@/components/SelectSheet';

const HEARTBEAT_MS = 15000;

type Upload = { key: string; name: string; size: number; id: string | null; error?: string };
type Picked = { uri: string; name: string; mimeType?: string | null; size?: number; file?: Blob };

/** Antwort an den Kunden oder interne Notiz schreiben */
export default function Write() {
  const { id, mode } = useLocalSearchParams<{ id: string; mode: 'reply' | 'note' }>();
  const isReply = mode !== 'note';
  const { request, options } = useSession();
  const [ticket, setTicket] = useState<TicketDetail | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [body, setBody] = useState('');
  const [cc, setCc] = useState('');
  const [statusId, setStatusId] = useState<number | null>(null);
  const [statusSheet, setStatusSheet] = useState(false);
  const [signatureId, setSignatureId] = useState<number | null>(null);
  const [signatureSheet, setSignatureSheet] = useState(false);
  const [mins, setMins] = useState('');
  const [uploads, setUploads] = useState<Upload[]>([]);
  const [sending, setSending] = useState(false);
  const seenMessageId = useRef<number | null>(null);

  // Ticket laden und den anderen zeigen „schreibt gerade“ (wie im Browser)
  const heartbeat = useCallback(async () => {
    const t = await request<TicketDetail>(`tickets/${id}`, { params: { writing: isReply ? 1 : undefined } });
    setTicket(t);
    return t;
  }, [request, id, isReply]);

  useEffect(() => {
    heartbeat()
      .then((t) => {
        seenMessageId.current = t.last_message_id;
        setCc(t.participants.filter((p) => p.toLowerCase() !== t.contact?.email?.toLowerCase()).join(', '));
      })
      .catch((e) => setLoadError(e instanceof Error ? e.message : 'Fehler beim Laden.'));
    const timer = setInterval(() => heartbeat().catch(() => {}), HEARTBEAT_MS);
    return () => clearInterval(timer);
  }, [heartbeat]);

  const upload = async (files: Picked[]) => {
    for (const f of files) {
      const key = `${Date.now()}-${Math.random()}`;
      setUploads((u) => [...u, { key, name: f.name, size: f.size ?? 0, id: null }]);
      const form = new FormData();
      if (Platform.OS === 'web' && f.file) form.append('file', f.file, f.name);
      else form.append('file', { uri: f.uri, name: f.name, type: f.mimeType || 'application/octet-stream' } as unknown as Blob);
      try {
        const r = await request<{ id: string; size: number }>('uploads', { form });
        setUploads((u) => u.map((x) => (x.key === key ? { ...x, id: r.id, size: r.size || x.size } : x)));
      } catch (e) {
        setUploads((u) => u.map((x) => (x.key === key ? { ...x, error: e instanceof Error ? e.message : 'Hochladen fehlgeschlagen' } : x)));
      }
    }
  };

  const pickDocument = async () => {
    const r = await DocumentPicker.getDocumentAsync({ multiple: true, copyToCacheDirectory: true });
    if (!r.canceled) upload(r.assets.map((a) => ({ uri: a.uri, name: a.name, mimeType: a.mimeType, size: a.size, file: a.file })));
  };

  const pickImage = async (camera: boolean) => {
    if (camera) {
      const perm = await ImagePicker.requestCameraPermissionsAsync();
      if (!perm.granted) {
        Alert.alert('Kamera', 'Bitte erlaube der App den Zugriff auf die Kamera.');
        return;
      }
    }
    const opts: ImagePicker.ImagePickerOptions = { mediaTypes: ['images'], quality: 0.8, allowsMultipleSelection: !camera };
    const r = camera ? await ImagePicker.launchCameraAsync(opts) : await ImagePicker.launchImageLibraryAsync(opts);
    if (!r.canceled) {
      upload(
        r.assets.map((a, i) => ({
          uri: a.uri,
          name: a.fileName || `foto-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}${i ? '-' + i : ''}.jpg`,
          mimeType: a.mimeType ?? 'image/jpeg',
          size: a.fileSize,
          file: a.file ?? undefined,
        })),
      );
    }
  };

  const send = async (force = false) => {
    if (!body.trim()) {
      Alert.alert(isReply ? 'Antwort' : 'Notiz', 'Bitte einen Text eingeben.');
      return;
    }
    if (uploads.some((u) => !u.id && !u.error)) {
      Alert.alert('Anhänge', 'Bitte warten, bis alle Anhänge hochgeladen sind.');
      return;
    }
    const ccList = cc
      .split(/[,;\s]+/)
      .map((x) => x.trim())
      .filter(Boolean);
    const payload = {
      body: body.trim(),
      minutes: parseInt(mins, 10) || undefined,
      upload_ids: uploads.filter((u) => u.id).map((u) => u.id),
      ...(isReply
        ? { cc: ccList, status_id: statusId ?? undefined, signature_team_id: signatureId ?? undefined, last_message_id: seenMessageId.current ?? undefined, force }
        : {}),
    };
    setSending(true);
    try {
      const r = await request<TicketUpdate>(`tickets/${id}/${isReply ? 'reply' : 'note'}`, { body: payload });
      if (isReply && r.delivery_status !== 'sent' && r.message) Alert.alert('Antwort gespeichert', r.message);
      router.back();
    } catch (e) {
      if (e instanceof ApiError && e.code === 'new_messages') {
        const fresh: TicketDetail | undefined = e.data?.ticket;
        if (fresh) setTicket(fresh);
        Alert.alert('Neue Nachricht', e.message + '\n\nDie neue Nachricht steht unten im Verlauf. Trotzdem senden?', [
          { text: 'Erst lesen', style: 'cancel', onPress: () => fresh && (seenMessageId.current = fresh.last_message_id) },
          { text: 'Trotzdem senden', onPress: () => send(true) },
        ]);
      } else {
        Alert.alert(isReply ? 'Antwort nicht gesendet' : 'Notiz nicht gespeichert', e instanceof Error ? e.message : 'Unbekannter Fehler.');
      }
    } finally {
      setSending(false);
    }
  };

  const title = isReply ? 'Antworten' : 'Interne Notiz';
  if (!ticket) {
    return (
      <>
        <Stack.Screen options={{ title }} />
        {loadError ? (
          <Screen>
            <ErrorBox message={loadError} />
          </Screen>
        ) : (
          <Loading />
        )}
      </>
    );
  }

  const selectedStatus = options?.statuses.find((s) => s.id === statusId);
  // Team-Signaturen des Mandanten; das Team des Tickets zuerst
  const signatures = (options?.teams ?? [])
    .filter((t) => t.signature && t.tenant_id === ticket.tenant.id)
    .sort((a, b) => Number(b.id === ticket.team?.id) - Number(a.id === ticket.team?.id));
  const selectedSignature = signatures.find((t) => t.id === signatureId);
  const addSignature = () => (signatures.length === 1 ? setSignatureId(signatures[0].id) : setSignatureSheet(true));
  const newer = ticket.messages.filter((m) => seenMessageId.current !== null && m.id > seenMessageId.current && m.type !== 'auto');
  const lastCustomer = [...ticket.messages].reverse().find((m) => m.type === 'customer');

  return (
    <>
      <Stack.Screen
        options={{
          title,
          headerLeft: () => (
            <Pressable onPress={() => router.back()} hitSlop={10}>
              <Text style={{ color: '#fff', fontSize: 16 }}>Abbrechen</Text>
            </Pressable>
          ),
        }}
      />
      <Screen>
        <Text style={s.ref}>
          {ticket.reference} · {ticket.subject}
        </Text>
        {ticket.others.some((o) => o.writing) && (
          <Notice tone="warn" text={ticket.others.filter((o) => o.writing).map((o) => `${o.name} schreibt gerade auch eine Antwort.`).join('\n')} />
        )}
        {newer.length > 0 && <Notice tone="warn" text={`Seit dem Öffnen ${newer.length === 1 ? 'ist eine neue Nachricht' : `sind ${newer.length} neue Nachrichten`} eingegangen.`} />}
        {!isReply && <Notice text="Interne Notizen sieht nur das Team – sie werden nicht an den Kunden geschickt." />}

        {isReply && lastCustomer && (
          <Card title="Letzte Nachricht des Kunden">
            <Text style={s.quote} numberOfLines={6}>
              {lastCustomer.body}
            </Text>
          </Card>
        )}

        <Card>
          {isReply && (
            <>
              <Label style={{ marginTop: 0 }}>An</Label>
              <Text style={s.to}>{ticket.contact?.email ?? '–'}</Text>
              <Label>Kopie an (mit Komma trennen)</Label>
              <TextInput style={s.input} value={cc} onChangeText={setCc} autoCapitalize="none" autoCorrect={false} keyboardType="email-address" placeholder="optional" placeholderTextColor={colors.muted} />
            </>
          )}
          <Label style={isReply ? undefined : { marginTop: 0 }}>{isReply ? 'Antwort' : 'Notiz'}</Label>
          <TextInput
            style={[s.input, s.body]}
            value={body}
            onChangeText={setBody}
            multiline
            textAlignVertical="top"
            placeholder={isReply ? 'Hallo …' : 'Für das Team …'}
            placeholderTextColor={colors.muted}
            autoFocus
          />
          {isReply && signatures.length > 0 && (
            <View style={s.signature}>
              {selectedSignature ? (
                <>
                  <Ionicons name="checkmark-circle" size={18} color={colors.ok} />
                  <Text style={{ flex: 1, color: colors.text }}>Signatur „{selectedSignature.name}“ wird angehängt</Text>
                  <Pressable onPress={() => setSignatureId(null)} hitSlop={10} accessibilityLabel="Signatur entfernen">
                    <Ionicons name="close" size={20} color={colors.muted} />
                  </Pressable>
                </>
              ) : (
                <AttachButton icon="id-card-outline" label="Signatur hinzufügen" onPress={addSignature} />
              )}
            </View>
          )}
          {!isReply && (
            <View style={{ marginTop: 6 }}>
              <Text style={{ fontSize: 12, color: colors.muted }}>Kollegen erwähnen: antippen fügt @Name ein, der Kollege bekommt einen Hinweis.</Text>
              <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 6 }}>
                {(options?.users ?? [])
                  .filter((u) => u.tenant_ids.includes(ticket.tenant.id))
                  .slice(0, 12)
                  .map((u) => (
                    <Pressable
                      key={u.id}
                      onPress={() => setBody((b) => (b && !b.endsWith(' ') && !b.endsWith('\n') ? b + ' ' : b) + '@' + u.name + ' ')}
                      style={{ borderWidth: 1, borderColor: '#cbd5e1', borderRadius: 14, paddingHorizontal: 10, paddingVertical: 4 }}>
                      <Text style={{ fontSize: 13, color: BRAND, fontWeight: '600' }}>@{u.name}</Text>
                    </Pressable>
                  ))}
              </View>
            </View>
          )}

          <Label>Anhänge</Label>
          <View style={s.attachRow}>
            <AttachButton icon="document-attach-outline" label="Datei" onPress={pickDocument} />
            <AttachButton icon="images-outline" label="Foto" onPress={() => pickImage(false)} />
            {Platform.OS !== 'web' && <AttachButton icon="camera-outline" label="Kamera" onPress={() => pickImage(true)} />}
          </View>
          {uploads.map((u) => (
            <View key={u.key} style={s.upload}>
              {u.id ? <Ionicons name="checkmark-circle" size={18} color={colors.ok} /> : u.error ? <Ionicons name="alert-circle" size={18} color={colors.danger} /> : <ActivityIndicator size="small" color={BRAND} />}
              <View style={{ flex: 1 }}>
                <Text numberOfLines={1} style={{ color: colors.text }}>
                  {u.name}
                </Text>
                <Text style={{ fontSize: 12, color: u.error ? colors.danger : colors.muted }}>{u.error ?? (u.size ? fileSize(u.size) : 'wird hochgeladen …')}</Text>
              </View>
              <Pressable onPress={() => setUploads((x) => x.filter((y) => y.key !== u.key))} hitSlop={10} accessibilityLabel="Anhang entfernen">
                <Ionicons name="close" size={20} color={colors.muted} />
              </Pressable>
            </View>
          ))}

          {isReply && (
            <>
              <Label>Status danach</Label>
              <Pressable onPress={() => setStatusSheet(true)} style={[s.input, s.select]} accessibilityRole="button">
                <Text style={{ color: colors.text, fontSize: 16 }}>{selectedStatus?.name ?? 'Wartet auf Kunde (Standard)'}</Text>
                <Ionicons name="chevron-down" size={18} color={colors.muted} />
              </Pressable>
            </>
          )}
          <Label>Arbeitszeit in Minuten (optional)</Label>
          <TextInput style={s.input} value={mins} onChangeText={setMins} keyboardType="number-pad" placeholder="z.B. 10" placeholderTextColor={colors.muted} />
        </Card>

        <Button title={sending ? 'Wird gesendet …' : isReply ? 'Antwort senden' : 'Notiz speichern'} icon={isReply ? 'send' : 'save-outline'} onPress={() => send()} disabled={sending} />
      </Screen>

      <SelectSheet
        visible={statusSheet}
        title="Status nach dem Senden"
        options={[{ value: '', label: 'Wartet auf Kunde (Standard)' }, ...(options?.statuses ?? []).map((st) => ({ value: String(st.id), label: st.name, color: st.color }))]}
        selected={statusId ? String(statusId) : ''}
        onSelect={(v) => {
          setStatusId(v ? Number(v) : null);
          setStatusSheet(false);
        }}
        onClose={() => setStatusSheet(false)}
      />
      <SelectSheet
        visible={signatureSheet}
        title="Signatur hinzufügen"
        options={signatures.map((t) => ({ value: String(t.id), label: t.name }))}
        selected={signatureId ? String(signatureId) : ''}
        onSelect={(v) => {
          setSignatureId(v ? Number(v) : null);
          setSignatureSheet(false);
        }}
        onClose={() => setSignatureSheet(false)}
      />
    </>
  );
}

function AttachButton({ icon, label, onPress }: { icon: React.ComponentProps<typeof Ionicons>['name']; label: string; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [s.attach, pressed && { opacity: 0.6 }]} accessibilityRole="button">
      <Ionicons name={icon} size={20} color={BRAND} />
      <Text style={{ color: BRAND, fontWeight: '600' }}>{label}</Text>
    </Pressable>
  );
}

const s = StyleSheet.create({
  ref: { fontSize: 13, color: colors.muted, fontWeight: '600' },
  quote: { color: colors.muted, fontSize: 13, lineHeight: 18 },
  to: { fontSize: 15, color: colors.text },
  input: { borderWidth: 1, borderColor: '#d1d5db', borderRadius: 10, paddingHorizontal: 12, paddingVertical: 10, fontSize: 16, color: colors.text, backgroundColor: '#fff' },
  body: { minHeight: 180 },
  select: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  attachRow: { flexDirection: 'row', gap: 8 },
  attach: { flexDirection: 'row', alignItems: 'center', gap: 6, borderWidth: 1.5, borderColor: BRAND, borderRadius: 10, paddingVertical: 8, paddingHorizontal: 12 },
  signature: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 10 },
  upload: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingVertical: 8, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border },
});
