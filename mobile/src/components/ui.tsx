import React from 'react';
import {
  ActivityIndicator,
  Pressable,
  RefreshControl,
  StyleProp,
  StyleSheet,
  Text,
  TextStyle,
  View,
  ViewStyle,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { KeyboardAwareScrollView } from 'react-native-keyboard-controller';
import { BRAND } from '@/lib/session';

export const colors = {
  bg: '#f1f5f9',
  card: '#ffffff',
  text: '#111827',
  muted: '#6b7280',
  border: '#e5e7eb',
  ok: '#15803d',
  okBg: '#dcfce7',
  warn: '#b45309',
  warnBg: '#fef3c7',
  danger: '#b91c1c',
  dangerBg: '#fee2e2',
};

type IconName = React.ComponentProps<typeof Ionicons>['name'];

/** Scrollbarer Bildschirm mit Pull-to-Refresh; das gerade bearbeitete Eingabefeld bleibt über der Tastatur */
export function Screen({
  children,
  refreshing = false,
  onRefresh,
  contentStyle,
  scrollEnabled = true,
}: {
  children: React.ReactNode;
  refreshing?: boolean;
  onRefresh?: () => void;
  contentStyle?: StyleProp<ViewStyle>;
  /** z.B. aus, solange im Unterschriftsfeld gezeichnet wird */
  scrollEnabled?: boolean;
}) {
  const color = BRAND;
  return (
    <KeyboardAwareScrollView
      style={styles.screen}
      contentContainerStyle={[styles.screenContent, contentStyle]}
      refreshControl={onRefresh ? <RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={color} colors={[color]} /> : undefined}
      keyboardShouldPersistTaps="handled"
      bottomOffset={24}
      scrollEnabled={scrollEnabled}
    >
      {children}
    </KeyboardAwareScrollView>
  );
}

export function Card({ title, right, children, style }: { title?: string; right?: React.ReactNode; children?: React.ReactNode; style?: StyleProp<ViewStyle> }) {
  return (
    <View style={[styles.card, style]}>
      {(title || right) && (
        <View style={styles.cardHead}>
          {title ? <Text style={styles.cardTitle}>{title}</Text> : <View />}
          {right}
        </View>
      )}
      {children}
    </View>
  );
}

/** Tippbare Zeile in einer Liste */
export function Row({
  title,
  subtitle,
  right,
  icon,
  onPress,
  last,
}: {
  title: React.ReactNode;
  subtitle?: React.ReactNode;
  right?: React.ReactNode;
  icon?: IconName;
  onPress?: () => void;
  last?: boolean;
}) {
  const color = BRAND;
  const content = (
    <View style={[styles.row, last && { borderBottomWidth: 0 }]}>
      {icon && <Ionicons name={icon} size={20} color={color} style={{ marginRight: 12 }} />}
      <View style={{ flex: 1 }}>
        {typeof title === 'string' ? <Text style={styles.rowTitle}>{title}</Text> : title}
        {subtitle ? typeof subtitle === 'string' ? <Text style={styles.rowSub}>{subtitle}</Text> : subtitle : null}
      </View>
      {right}
      {onPress && <Ionicons name="chevron-forward" size={18} color={colors.muted} style={{ marginLeft: 6 }} />}
    </View>
  );
  return onPress ? (
    <Pressable onPress={onPress} style={({ pressed }) => pressed && { opacity: 0.6 }} accessibilityRole="button">
      {content}
    </Pressable>
  ) : (
    content
  );
}

export function Pill({ label, tone = 'neutral' }: { label: string; tone?: 'ok' | 'warn' | 'danger' | 'neutral' | 'primary' }) {
  const color = BRAND;
  const map = {
    ok: [colors.okBg, colors.ok],
    warn: [colors.warnBg, colors.warn],
    danger: [colors.dangerBg, colors.danger],
    neutral: ['#e5e7eb', '#374151'],
    primary: [color + '22', color],
  } as const;
  const [bg, fg] = map[tone];
  return (
    <View style={[styles.pill, { backgroundColor: bg }]}>
      <Text style={[styles.pillText, { color: fg }]}>{label}</Text>
    </View>
  );
}

export function Loading() {
  const color = BRAND;
  return (
    <View style={styles.center}>
      <ActivityIndicator size="large" color={color} />
    </View>
  );
}

export function ErrorBox({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <View style={[styles.card, { borderColor: '#fca5a5', backgroundColor: colors.dangerBg }]}>
      <Text style={{ color: colors.danger }}>{message}</Text>
      {onRetry && <Button title="Erneut versuchen" onPress={onRetry} variant="ghost" style={{ marginTop: 8, alignSelf: 'flex-start' }} />}
    </View>
  );
}

export function Empty({ text }: { text: string }) {
  return <Text style={styles.empty}>{text}</Text>;
}

export function Notice({ text, tone = 'info' }: { text: string; tone?: 'info' | 'warn' | 'ok' }) {
  const bg = tone === 'warn' ? colors.warnBg : tone === 'ok' ? colors.okBg : '#e0f2fe';
  const fg = tone === 'warn' ? '#78350f' : tone === 'ok' ? colors.ok : '#075985';
  return (
    <View style={[styles.notice, { backgroundColor: bg }]}>
      <Text style={{ color: fg }}>{text}</Text>
    </View>
  );
}

export function Button({
  title,
  onPress,
  variant = 'primary',
  disabled,
  icon,
  style,
}: {
  title: string;
  onPress: () => void;
  variant?: 'primary' | 'ghost' | 'danger';
  disabled?: boolean;
  icon?: IconName;
  style?: StyleProp<ViewStyle>;
}) {
  const color = BRAND;
  const bg = variant === 'primary' ? color : variant === 'danger' ? colors.danger : 'transparent';
  const fg = variant === 'ghost' ? color : '#fff';
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      accessibilityRole="button"
      style={({ pressed }) => [
        styles.button,
        { backgroundColor: bg, borderColor: variant === 'ghost' ? color : bg, opacity: disabled ? 0.5 : pressed ? 0.75 : 1 },
        style,
      ]}
    >
      {icon && <Ionicons name={icon} size={18} color={fg} style={{ marginRight: 6 }} />}
      <Text style={[styles.buttonText, { color: fg }]}>{title}</Text>
    </Pressable>
  );
}

export function Label({ children, style }: { children: React.ReactNode; style?: StyleProp<TextStyle> }) {
  return <Text style={[styles.label, style]}>{children}</Text>;
}

/** Zweispaltige Angaben (Bezeichnung – Wert) */
export function InfoList({ items }: { items: [string, React.ReactNode][] }) {
  return (
    <View>
      {items
        .filter(([, v]) => v !== null && v !== undefined && v !== '' && v !== '–')
        .map(([k, v], i) => (
          <View key={k + i} style={styles.info}>
            <Text style={styles.infoKey}>{k}</Text>
            {typeof v === 'string' || typeof v === 'number' ? <Text style={styles.infoVal}>{v}</Text> : <View style={{ flex: 1 }}>{v}</View>}
          </View>
        ))}
    </View>
  );
}

export const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  screenContent: { padding: 14, paddingBottom: 40, gap: 12 },
  card: { backgroundColor: colors.card, borderRadius: 12, padding: 14, borderWidth: 1, borderColor: colors.border },
  cardHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 },
  cardTitle: { fontSize: 16, fontWeight: '700', color: colors.text },
  row: { flexDirection: 'row', alignItems: 'center', paddingVertical: 11, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border },
  rowTitle: { fontSize: 15, color: colors.text, fontWeight: '500' },
  rowSub: { fontSize: 13, color: colors.muted, marginTop: 2 },
  pill: { borderRadius: 999, paddingHorizontal: 8, paddingVertical: 3, alignSelf: 'flex-start' },
  pillText: { fontSize: 12, fontWeight: '700' },
  statRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  stat: { flexGrow: 1, flexBasis: '45%', backgroundColor: colors.card, borderRadius: 12, padding: 12, borderWidth: 1, borderColor: colors.border },
  statLabel: { fontSize: 12, color: colors.muted },
  statValue: { fontSize: 22, fontWeight: '700', marginTop: 2 },
  statSub: { fontSize: 12, color: colors.muted, marginTop: 2 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 40, backgroundColor: colors.bg },
  empty: { color: colors.muted, paddingVertical: 8 },
  notice: { borderRadius: 10, padding: 12 },
  button: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: 10, paddingVertical: 11, paddingHorizontal: 16, borderWidth: 1.5 },
  buttonText: { fontSize: 15, fontWeight: '700' },
  actions: { flexDirection: 'row', gap: 10, marginTop: 10, flexWrap: 'wrap' },
  action: { alignItems: 'center', width: 64 },
  actionIcon: { width: 44, height: 44, borderRadius: 22, alignItems: 'center', justifyContent: 'center' },
  actionLabel: { fontSize: 11, color: colors.muted, marginTop: 4 },
  label: { fontSize: 13, fontWeight: '600', color: '#374151', marginBottom: 4, marginTop: 10 },
  info: { flexDirection: 'row', paddingVertical: 6, gap: 10 },
  infoKey: { width: 120, color: colors.muted, fontSize: 14 },
  infoVal: { flex: 1, color: colors.text, fontSize: 14 },
});
