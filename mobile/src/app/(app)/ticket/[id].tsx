import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, Stack, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { BRAND, useSession } from '@/lib/session';
import type { Attachment, Message, TicketDetail, TicketEvent, TicketUpdate } from '@/lib/api';
import { behaviourTone, dateTime, due, eventText, fileSize, minutes, priorityTone } from '@/lib/format';
import { openAttachment } from '@/lib/files';
import { Button, Card, colors, ErrorBox, Loading, Notice, Pill, Row, Screen } from '@/components/ui';
import { SelectSheet, SelectOption, TimeSheet } from '@/components/SelectSheet';
import { getItem, setItem } from '@/lib/storage';

const POLL_MS = 15000;

type Sheet = null | 'status' | 'priority' | 'category' | 'assignee' | 'team' | 'snooze' | 'time';

/** Gemerkte Sortierung des Verlaufs (Standard: neueste oben) */
const ORDER_KEY = 'anliego.verlauf.sortierung';

export default function TicketScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { request, options, reloadOptions, me, authHeaders } = useSession();
  const [ticket, setTicket] = useState<TicketDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [sheet, setSheet] = useState<Sheet>(null);
  const [opening, setOpening] = useState<number | null>(null);
  const [newestFirst, setNewestFirst] = useState(true);

  useEffect(() => {
    getItem(ORDER_KEY).then((v) => v === 'oldest' && setNewestFirst(false)).catch(() => {});
  }, []);

  const toggleOrder = () => {
    const next = !newestFirst;
    setNewestFirst(next);
    setItem(ORDER_KEY, next ? 'newest' : 'oldest').catch(() => {});
  };

  const load = useCallback(
    async (mode: 'initial' | 'refresh' | 'silent' = 'initial') => {
      if (mode === 'refresh') setRefreshing(true);
      try {
        setTicket(await request<TicketDetail>(`tickets/${id}`));
        setError(null);
      } catch (e) {
        if (mode !== 'silent') setError(e instanceof Error ? e.message : 'Fehler beim Laden.');
      } finally {
        setRefreshing(false);
      }
    },
    [request, id],
  );

  useEffect(() => {
    load('initial');
    if (!options) reloadOptions();
  }, [load]); // eslint-disable-line react-hooks/exhaustive-deps

  // Solange das Ticket offen ist: regelmäßig neu laden (neue Nachrichten, wer noch daran arbeitet).
  // Zurück vom Antworten: sofort neu laden.
  const focused = useRef(false);
  useFocusEffect(
    useCallback(() => {
      if (focused.current) load('silent');
      focused.current = true;
      const t = setInterval(() => load('silent'), POLL_MS);
      return () => clearInterval(t);
    }, [load]),
  );

  const act = async (path: string, body: object = {}, done?: string) => {
    setSheet(null);
    setBusy(true);
    try {
      const r = await request<TicketUpdate>(`tickets/${id}/${path}`, { body });
      setTicket(r.ticket);
      if (done) Alert.alert(done);
    } catch (e) {
      Alert.alert('Nicht gespeichert', e instanceof Error ? e.message : 'Unbekannter Fehler.');
    } finally {
      setBusy(false);
    }
  };

  const open = async (att: Attachment) => {
    setOpening(att.id);
    try {
      await openAttachment(att, authHeaders());
    } catch (e) {
      Alert.alert('Anhang', 'Die Datei konnte nicht geöffnet werden.' + (e instanceof Error ? `\n\n${e.message}` : ''));
    } finally {
      setOpening(null);
    }
  };

  // Verlauf: Nachrichten und Ereignisse gemischt, sortiert nach Wahl (Standard: neueste oben)
  const timeline = useMemo(() => {
    if (!ticket) return [];
    const rows: ({ kind: 'message'; at: string; item: Message } | { kind: 'event'; at: string; item: TicketEvent })[] = [
      ...ticket.messages.map((m) => ({ kind: 'message' as const, at: m.created_at, item: m })),
      ...ticket.events.map((e) => ({ kind: 'event' as const, at: e.created_at, item: e })),
    ];
    const dir = newestFirst ? -1 : 1;
    return rows.sort((a, b) => dir * (new Date(a.at).getTime() - new Date(b.at).getTime()));
  }, [ticket, newestFirst]);

  const sheetOptions = useMemo((): { title: string; options: SelectOption[]; selected?: string | null } | null => {
    if (!ticket || !options || !sheet || sheet === 'time') return null;
    switch (sheet) {
      case 'status':
        return {
          title: 'Status',
          options: options.statuses.map((s) => ({ value: String(s.id), label: s.name, color: s.color })),
          selected: ticket.status ? String(ticket.status.id) : null,
        };
      case 'priority':
        return { title: 'Priorität', options: options.priorities.map((p) => ({ value: p.value, label: p.label })), selected: ticket.priority.value };
      case 'category':
        return {
          title: 'Kategorie',
          options: [
            { value: '', label: 'Keine Kategorie' },
            ...options.categories.filter((c) => c.tenant_id === null || c.tenant_id === ticket.tenant.id).map((c) => ({ value: String(c.id), label: c.name })),
          ],
          selected: ticket.category ? String(ticket.category.id) : '',
        };
      case 'assignee':
        return {
          title: 'Zuweisen an',
          options: [
            { value: '', label: 'Niemand (Unzugewiesen)' },
            ...options.users
              .filter((u) => u.tenant_ids.includes(ticket.tenant.id))
              .map((u) => ({ value: String(u.id), label: u.id === me?.id ? `${u.name} (ich)` : u.name })),
          ],
          selected: ticket.assignee ? String(ticket.assignee.id) : '',
        };
      case 'snooze':
        return {
          title: 'Zurückstellen bis',
          options: [
            { value: 'tomorrow', label: 'Morgen 8 Uhr' },
            { value: 'days3', label: 'In 3 Tagen' },
            { value: 'week', label: 'In 1 Woche' },
            ...(ticket.snoozed_until ? [{ value: 'off', label: 'Wiedervorlage aufheben' }] : []),
          ],
          selected: null,
        };
      case 'team':
        return {
          title: 'Team',
          options: [
            { value: '', label: 'Kein Team' },
            ...(options.teams ?? []).filter((t) => t.tenant_id === ticket.tenant.id).map((t) => ({ value: String(t.id), label: t.name })),
          ],
          selected: ticket.team ? String(ticket.team.id) : '',
        };
    }
  }, [sheet, ticket, options, me]);

  const select = (value: string) => {
    if (sheet === 'status') act('status', { status_id: Number(value) });
    if (sheet === 'priority') act('priority', { priority: value });
    if (sheet === 'category') act('category', { category_id: value ? Number(value) : null });
    if (sheet === 'assignee') act('assign', { assignee_id: value ? Number(value) : null });
    if (sheet === 'team') act('team', { team_id: value ? Number(value) : null });
    if (sheet === 'snooze') act('snooze', { preset: value });
  };

  if (!ticket) {
    return (
      <>
        <Stack.Screen options={{ title: 'Ticket' }} />
        {error ? (
          <Screen>
            <ErrorBox message={error} onRetry={() => load('initial')} />
          </Screen>
        ) : (
          <Loading />
        )}
      </>
    );
  }

  const deadline = ticket.status?.behaviour === 'closed' ? null : due(ticket.sla_due_at);
  const write = (mode: 'reply' | 'note') => router.push({ pathname: '/schreiben', params: { id: String(ticket.id), mode } });

  return (
    <>
      <Stack.Screen options={{ title: ticket.reference }} />
      <Screen refreshing={refreshing} onRefresh={() => load('refresh')}>
        <Card>
          <Text style={s.tenant}>{ticket.tenant.name}</Text>
          <Text style={s.subject}>{ticket.subject || '(ohne Betreff)'}</Text>
          {ticket.contact && (
            <Pressable onPress={() => ticket.contact?.email && Linking.openURL('mailto:' + ticket.contact.email)} disabled={!ticket.contact.email}>
              <Text style={s.contact}>
                {ticket.contact.name ? `${ticket.contact.name} · ` : ''}
                <Text style={{ color: BRAND }}>{ticket.contact.email}</Text>
              </Text>
            </Pressable>
          )}
          <View style={s.pills}>
            {ticket.status && <Pill label={ticket.status.name} tone={behaviourTone(ticket.status.behaviour)} />}
            <Pill label={ticket.priority.label} tone={priorityTone(ticket.priority.value)} />
            {ticket.error_count > 1 && <Pill label={`${ticket.error_count}× aufgetreten`} tone="danger" />}
          </View>
          {deadline && <Text style={[s.meta, deadline.overdue && { color: colors.danger, fontWeight: '700' }]}>Frist {deadline.text}</Text>}
          {ticket.snoozed_until && <Notice tone="warn" text={`Zurückgestellt bis ${dateTime(ticket.snoozed_until)}`} />}
          {ticket.merged_into && <Notice tone="warn" text={`Zusammengeführt in ${ticket.merged_into}`} />}
        </Card>

        {ticket.others.length > 0 && (
          <Notice
            tone="warn"
            text={ticket.others.map((o) => (o.writing ? `${o.name} schreibt gerade eine Antwort` : `${o.name} hat das Ticket auch offen`)).join('\n')}
          />
        )}

        <View style={s.actions}>
          {ticket.can.take && <Button title="Übernehmen" icon="hand-left-outline" onPress={() => act('take')} disabled={busy} style={s.action} />}
          {ticket.can.reply && <Button title="Antworten" icon="arrow-undo" onPress={() => write('reply')} style={s.action} />}
          {ticket.can.note && <Button title="Notiz" icon="create-outline" variant="ghost" onPress={() => write('note')} style={s.action} />}
        </View>

        <Card title="Bearbeiten" right={busy ? <ActivityIndicator color={BRAND} /> : undefined}>
          <Row icon="person-outline" title="Bearbeiter" subtitle={ticket.assignee?.name ?? 'Unzugewiesen'} onPress={ticket.can.edit ? () => setSheet('assignee') : undefined} />
          <Row icon="people-outline" title="Team" subtitle={ticket.team?.name ?? 'Kein Team'} onPress={ticket.can.edit ? () => setSheet('team') : undefined} />
          <Row icon="flag-outline" title="Status" subtitle={ticket.status?.name ?? '–'} onPress={ticket.can.edit ? () => setSheet('status') : undefined} />
          <Row icon="alert-circle-outline" title="Priorität" subtitle={ticket.priority.label} onPress={ticket.can.edit ? () => setSheet('priority') : undefined} />
          <Row icon="pricetag-outline" title="Kategorie" subtitle={ticket.category?.name ?? 'Keine'} onPress={ticket.can.edit ? () => setSheet('category') : undefined} />
          {ticket.status?.behaviour !== 'closed' && (
            <Row
              icon="alarm-outline"
              title="Wiedervorlage"
              subtitle={ticket.snoozed_until ? `zurückgestellt bis ${dateTime(ticket.snoozed_until)}` : 'nicht zurückgestellt'}
              onPress={ticket.can.edit ? () => setSheet('snooze') : undefined}
            />
          )}
          <Row
            icon="time-outline"
            title="Arbeitszeit"
            subtitle={ticket.time_minutes ? minutes(ticket.time_minutes) : 'noch nichts erfasst'}
            onPress={ticket.can.edit ? () => setSheet('time') : undefined}
            last
          />
          {!ticket.can.edit && <Text style={s.meta}>Du kannst dieses Ticket nur lesen.</Text>}
        </Card>

        {Object.keys(ticket.fields ?? {}).length > 0 && (
          <Card title="Angaben">
            {Object.entries(ticket.fields).map(([k, v]) => (
              <View key={k} style={s.field}>
                <Text style={s.fieldKey}>{k}</Text>
                <Text style={s.fieldVal}>{typeof v === 'object' ? JSON.stringify(v) : String(v)}</Text>
              </View>
            ))}
          </Card>
        )}

        <View style={s.sectionRow}>
          <Text style={s.section}>Verlauf</Text>
          <Pressable onPress={toggleOrder} hitSlop={8} style={s.sortBtn} accessibilityRole="button">
            <Ionicons name={newestFirst ? 'arrow-down' : 'arrow-up'} size={14} color={BRAND} />
            <Text style={s.sortText}>{newestFirst ? 'Neueste oben' : 'Älteste oben'}</Text>
          </Pressable>
        </View>
        {timeline.map((row) =>
          row.kind === 'event' ? (
            <Text key={'e' + row.item.created_at + row.item.type} style={s.event}>
              {eventText(row.item, options)} · {dateTime(row.at)}
            </Text>
          ) : (
            <MessageCard key={'m' + row.item.id} message={row.item} opening={opening} onOpen={open} />
          ),
        )}
      </Screen>

      <SelectSheet
        visible={sheetOptions !== null}
        title={sheetOptions?.title ?? ''}
        options={sheetOptions?.options ?? []}
        selected={sheetOptions?.selected}
        onSelect={select}
        onClose={() => setSheet(null)}
      />
      <TimeSheet visible={sheet === 'time'} onClose={() => setSheet(null)} onSave={(m, note) => act('time', { minutes: m, note: note || null }, `${m} Minuten erfasst`)} />
    </>
  );
}

const TYPE_LABEL: Record<string, string> = { customer: '', reply: 'Antwort', note: 'Interne Notiz', auto: 'Automatische E-Mail' };

function MessageCard({ message: m, opening, onOpen }: { message: Message; opening: number | null; onOpen: (a: Attachment) => void }) {
  const [full, setFull] = useState(false);
  const style = m.type === 'note' ? s.note : m.type === 'reply' ? s.reply : m.type === 'auto' ? s.auto : null;
  return (
    <View style={[s.msg, style]}>
      <View style={s.msgHead}>
        <Text style={s.author} numberOfLines={1}>
          {m.author ?? 'Unbekannt'}
          {TYPE_LABEL[m.type] ? <Text style={s.msgType}> · {TYPE_LABEL[m.type]}</Text> : null}
        </Text>
        <Text style={s.meta}>{dateTime(m.created_at)}</Text>
      </View>
      {m.type === 'reply' && m.to.length > 0 && (
        <Text style={s.meta}>
          An {m.to.join(', ')}
          {m.cc.length ? ` · Kopie ${m.cc.join(', ')}` : ''}
        </Text>
      )}
      <Text style={s.body} selectable>
        {full && m.body_full ? m.body_full : m.body}
      </Text>
      {m.body_full && (
        <Pressable onPress={() => setFull((f) => !f)} hitSlop={6}>
          <Text style={s.more}>{full ? 'Zitate ausblenden' : 'Ganze E-Mail mit Zitaten zeigen'}</Text>
        </Pressable>
      )}
      {m.delivery_status === 'failed' && <Text style={[s.meta, { color: colors.danger }]}>Versand fehlgeschlagen – wird wiederholt</Text>}
      {m.delivery_status === 'pending' && <Text style={s.meta}>wird gesendet …</Text>}
      {m.attachments.length > 0 && (
        <View style={s.files}>
          {m.attachments.map((a) => (
            <Pressable key={a.id} onPress={() => onOpen(a)} style={s.file} accessibilityRole="button" disabled={opening !== null}>
              {opening === a.id ? <ActivityIndicator size="small" color={BRAND} /> : <Ionicons name="attach" size={16} color={BRAND} />}
              <Text style={s.fileName} numberOfLines={1}>
                {a.filename}
              </Text>
              <Text style={s.fileSize}>{fileSize(a.size)}</Text>
            </Pressable>
          ))}
        </View>
      )}
    </View>
  );
}

const s = StyleSheet.create({
  tenant: { fontSize: 12, color: colors.muted, fontWeight: '600', textTransform: 'uppercase' },
  subject: { fontSize: 18, fontWeight: '800', color: colors.text, marginTop: 2 },
  contact: { fontSize: 14, color: colors.text, marginTop: 4 },
  pills: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 10 },
  meta: { fontSize: 12, color: colors.muted, marginTop: 4 },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  action: { flexGrow: 1 },
  field: { flexDirection: 'row', gap: 10, paddingVertical: 4 },
  fieldKey: { width: 110, color: colors.muted, fontSize: 13 },
  fieldVal: { flex: 1, color: colors.text, fontSize: 13 },
  section: { fontSize: 13, fontWeight: '700', color: colors.muted },
  sectionRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 6 },
  sortBtn: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  sortText: { fontSize: 13, fontWeight: '600', color: BRAND },
  event: { fontSize: 12, color: colors.muted, textAlign: 'center', paddingHorizontal: 10 },
  msg: { backgroundColor: colors.card, borderRadius: 12, borderWidth: 1, borderColor: colors.border, padding: 12, gap: 4 },
  reply: { borderLeftWidth: 4, borderLeftColor: BRAND },
  note: { backgroundColor: '#fffbeb', borderColor: '#fcd34d' },
  auto: { borderLeftWidth: 4, borderLeftColor: '#94a3b8', opacity: 0.85 },
  msgHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'baseline', gap: 8 },
  author: { fontSize: 14, fontWeight: '700', color: colors.text, flexShrink: 1 },
  msgType: { fontWeight: '400', color: colors.muted },
  body: { fontSize: 14, lineHeight: 20, color: colors.text, marginTop: 4 },
  more: { color: BRAND, fontSize: 13, fontWeight: '600', marginTop: 6 },
  files: { gap: 6, marginTop: 8 },
  file: { flexDirection: 'row', alignItems: 'center', gap: 6, borderWidth: 1, borderColor: '#cbd5e1', borderRadius: 8, paddingHorizontal: 10, paddingVertical: 8 },
  fileName: { flex: 1, fontSize: 13, color: colors.text },
  fileSize: { fontSize: 12, color: colors.muted },
});
