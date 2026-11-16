import { useState } from 'react';
import { Modal, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { BRAND } from '@/lib/session';
import { Button, colors } from './ui';

export type SelectOption = { value: string; label: string; hint?: string; color?: string | null };

/** Auswahlliste von unten (Status, Priorität, Kategorie, Bearbeiter) */
export function SelectSheet({
  visible,
  title,
  options,
  selected,
  onSelect,
  onClose,
}: {
  visible: boolean;
  title: string;
  options: SelectOption[];
  selected?: string | null;
  onSelect: (value: string) => void;
  onClose: () => void;
}) {
  const insets = useSafeAreaInsets();
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <Pressable style={s.backdrop} onPress={onClose} accessibilityLabel="Schließen" />
      <View style={[s.sheet, { paddingBottom: insets.bottom + 12 }]}>
        <View style={s.head}>
          <Text style={s.title}>{title}</Text>
          <Pressable onPress={onClose} hitSlop={10} accessibilityLabel="Schließen">
            <Ionicons name="close" size={24} color={colors.muted} />
          </Pressable>
        </View>
        <ScrollView style={{ maxHeight: 420 }}>
          {options.map((o) => (
            <Pressable key={o.value} onPress={() => onSelect(o.value)} style={({ pressed }) => [s.option, pressed && { backgroundColor: colors.bg }]} accessibilityRole="button">
              {o.color ? <View style={[s.dot, { backgroundColor: o.color }]} /> : null}
              <View style={{ flex: 1 }}>
                <Text style={[s.label, o.value === selected && { color: BRAND, fontWeight: '700' }]}>{o.label}</Text>
                {o.hint ? <Text style={s.hint}>{o.hint}</Text> : null}
              </View>
              {o.value === selected && <Ionicons name="checkmark" size={20} color={BRAND} />}
            </Pressable>
          ))}
        </ScrollView>
      </View>
    </Modal>
  );
}

const PRESETS = [5, 10, 15, 30, 45, 60, 90, 120];

/** Arbeitszeit erfassen */
export function TimeSheet({ visible, onSave, onClose }: { visible: boolean; onSave: (minutes: number, note: string) => void; onClose: () => void }) {
  const insets = useSafeAreaInsets();
  const [value, setValue] = useState('');
  const [note, setNote] = useState('');
  const minutes = parseInt(value, 10);
  const valid = minutes > 0 && minutes <= 1440;
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <Pressable style={s.backdrop} onPress={onClose} accessibilityLabel="Schließen" />
      <View style={[s.sheet, { paddingBottom: insets.bottom + 12, paddingHorizontal: 16, gap: 10 }]}>
        <View style={[s.head, { paddingHorizontal: 0 }]}>
          <Text style={s.title}>Zeit erfassen</Text>
          <Pressable onPress={onClose} hitSlop={10} accessibilityLabel="Schließen">
            <Ionicons name="close" size={24} color={colors.muted} />
          </Pressable>
        </View>
        <View style={s.presets}>
          {PRESETS.map((p) => (
            <Pressable key={p} onPress={() => setValue(String(p))} style={[s.preset, value === String(p) && { backgroundColor: BRAND }]}>
              <Text style={{ color: value === String(p) ? '#fff' : BRAND, fontWeight: '600' }}>{p} Min.</Text>
            </Pressable>
          ))}
        </View>
        <TextInput style={s.input} value={value} onChangeText={setValue} keyboardType="number-pad" placeholder="Minuten" placeholderTextColor={colors.muted} />
        <TextInput style={s.input} value={note} onChangeText={setNote} placeholder="Notiz (optional)" placeholderTextColor={colors.muted} maxLength={255} />
        <Button
          title="Zeit speichern"
          icon="time-outline"
          disabled={!valid}
          onPress={() => {
            onSave(minutes, note.trim());
            setValue('');
            setNote('');
          }}
        />
      </View>
    </Modal>
  );
}

const s = StyleSheet.create({
  backdrop: { flex: 1, backgroundColor: 'rgba(15,23,42,0.45)' },
  sheet: { backgroundColor: colors.card, borderTopLeftRadius: 18, borderTopRightRadius: 18, paddingTop: 8 },
  head: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 16, paddingVertical: 10 },
  title: { fontSize: 17, fontWeight: '700', color: colors.text },
  option: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: 16, paddingVertical: 13, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.border },
  dot: { width: 10, height: 10, borderRadius: 5 },
  label: { fontSize: 15, color: colors.text },
  hint: { fontSize: 12, color: colors.muted, marginTop: 2 },
  presets: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  preset: { borderWidth: 1.5, borderColor: BRAND, borderRadius: 999, paddingVertical: 6, paddingHorizontal: 12 },
  input: { borderWidth: 1, borderColor: '#d1d5db', borderRadius: 10, paddingHorizontal: 12, paddingVertical: 10, fontSize: 16, color: colors.text },
});
