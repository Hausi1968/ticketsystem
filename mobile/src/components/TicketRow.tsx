import { memo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import type { TicketSummary } from '@/lib/api';
import { ago, behaviourTone, due, priorityTone } from '@/lib/format';
import { colors, Pill } from './ui';

export const TicketRow = memo(function TicketRow({ ticket, onPress }: { ticket: TicketSummary; onPress: () => void }) {
  const deadline = ticket.status?.behaviour === 'closed' ? null : due(ticket.sla_due_at);
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [s.row, pressed && { opacity: 0.6 }]} accessibilityRole="button">
      <View style={[s.bar, { backgroundColor: ticket.tenant.color || '#94a3b8' }]} />
      <View style={{ flex: 1, gap: 4 }}>
        <View style={s.head}>
          <Text style={s.ref}>
            {ticket.reference} · {ticket.tenant.name}
          </Text>
          <Text style={s.time}>{ago(ticket.updated_at)}</Text>
        </View>
        <Text style={s.subject} numberOfLines={2}>
          {ticket.subject || '(ohne Betreff)'}
        </Text>
        {ticket.contact && (
          <Text style={s.contact} numberOfLines={1}>
            {ticket.contact.name || ticket.contact.email}
          </Text>
        )}
        <View style={s.pills}>
          {ticket.status && <Pill label={ticket.status.name} tone={behaviourTone(ticket.status.behaviour)} />}
          {priorityTone(ticket.priority.value) !== 'neutral' && <Pill label={ticket.priority.label} tone={priorityTone(ticket.priority.value)} />}
          {ticket.error_count > 1 && <Pill label={`${ticket.error_count}× aufgetreten`} tone="danger" />}
          <View style={s.assignee}>
            <Ionicons name={ticket.assignee ? 'person' : 'person-outline'} size={13} color={colors.muted} />
            <Text style={s.assigneeText}>{ticket.assignee?.name ?? 'Unzugewiesen'}</Text>
          </View>
        </View>
        {deadline && <Text style={[s.due, deadline.overdue && { color: colors.danger, fontWeight: '700' }]}>Frist {deadline.text}</Text>}
      </View>
    </Pressable>
  );
});

const s = StyleSheet.create({
  row: { flexDirection: 'row', backgroundColor: colors.card, borderRadius: 12, borderWidth: 1, borderColor: colors.border, padding: 12, gap: 10 },
  bar: { width: 4, borderRadius: 2 },
  head: { flexDirection: 'row', justifyContent: 'space-between', gap: 8 },
  ref: { fontSize: 12, color: colors.muted, fontWeight: '600', flexShrink: 1 },
  time: { fontSize: 12, color: colors.muted },
  subject: { fontSize: 15, fontWeight: '700', color: colors.text },
  contact: { fontSize: 13, color: colors.muted },
  pills: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 6, marginTop: 2 },
  assignee: { flexDirection: 'row', alignItems: 'center', gap: 3 },
  assigneeText: { fontSize: 12, color: colors.muted },
  due: { fontSize: 12, color: colors.warn },
});
