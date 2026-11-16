import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { router, Stack, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { BRAND, useSession } from '@/lib/session';
import type { TicketList, TicketSummary } from '@/lib/api';
import { TicketRow } from '@/components/TicketRow';
import { colors, Empty, ErrorBox, Loading } from '@/components/ui';
import { UpdateReadyNotice } from '@/components/UpdateNotice';

// Ersatz, falls der Server (ältere Version) keine Reiter mitliefert; sonst gelten die Reiter vom Server
const TABS = [
  { key: 'mine', label: 'Meine' },
  { key: 'unassigned', label: 'Unzugewiesen' },
  { key: 'teams', label: 'Meine Teams' },
  { key: 'snoozed', label: 'Zurückgestellt' },
  { key: 'open', label: 'Alle offenen' },
  { key: 'pending', label: 'Wartend' },
  { key: 'closed', label: 'Geschlossen' },
  { key: 'all', label: 'Alle' },
];

export default function Tickets() {
  const { request, options } = useSession();
  const [tab, setTab] = useState('mine');
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [tenantId, setTenantId] = useState<number | null>(null);
  const [items, setItems] = useState<TicketSummary[]>([]);
  const [counts, setCounts] = useState<TicketList['counts'] | null>(null);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const requestId = useRef(0);

  // Suche erst kurz nach dem Tippen starten
  useEffect(() => {
    const t = setTimeout(() => setQuery(search.trim()), 350);
    return () => clearTimeout(t);
  }, [search]);

  const load = useCallback(
    async (mode: 'initial' | 'refresh' | 'silent' | 'more', nextPage = 1) => {
      const id = ++requestId.current;
      if (mode === 'initial') setLoading(true);
      if (mode === 'refresh') setRefreshing(true);
      if (mode === 'more') setLoadingMore(true);
      try {
        const r = await request<TicketList>('tickets', { params: { tab, search: query, tenant_id: tenantId, page: nextPage } });
        if (id !== requestId.current) return; // inzwischen anderer Reiter/Suchbegriff
        setItems((prev) => (mode === 'more' ? [...prev, ...r.data.filter((t) => !prev.some((p) => p.id === t.id))] : r.data));
        setPage(r.page);
        setHasMore(r.has_more);
        setTotal(r.total);
        setCounts(r.counts);
        setError(null);
      } catch (e) {
        if (id === requestId.current) setError(e instanceof Error ? e.message : 'Fehler beim Laden.');
      } finally {
        if (id === requestId.current) {
          setLoading(false);
          setRefreshing(false);
          setLoadingMore(false);
        }
      }
    },
    [request, tab, query, tenantId],
  );

  useEffect(() => {
    load('initial');
  }, [load]);

  // Zurück aus einem Ticket: Liste still aktualisieren
  const first = useRef(true);
  useFocusEffect(
    useCallback(() => {
      if (first.current) {
        first.current = false;
        return;
      }
      load('silent');
    }, [load]),
  );

  const tenants = options?.tenants ?? [];
  const tabs = options?.tabs?.length ? options.tabs : TABS;
  const searching = query !== '';
  const label = (key: string, text: string) => {
    const n = key === 'mine' ? counts?.mine : key === 'unassigned' ? counts?.unassigned : key === 'teams' ? counts?.teams : undefined;
    return n ? `${text} (${n})` : text;
  };

  return (
    <View style={{ flex: 1, backgroundColor: colors.bg }}>
      <Stack.Screen
        options={{
          headerRight: () => (
            <Pressable onPress={() => router.push('/konto')} hitSlop={10} accessibilityLabel="Konto">
              <Ionicons name="person-circle-outline" size={26} color="#fff" />
            </Pressable>
          ),
        }}
      />
      <View style={s.top}>
        <View style={s.search}>
          <Ionicons name="search" size={18} color={colors.muted} />
          <TextInput
            style={s.searchInput}
            value={search}
            onChangeText={setSearch}
            placeholder="Alle Tickets durchsuchen (Text, Absender, Nummer)"
            placeholderTextColor={colors.muted}
            returnKeyType="search"
            autoCorrect={false}
            onSubmitEditing={() => setQuery(search.trim())}
          />
          {search !== '' && (
            <Pressable onPress={() => setSearch('')} hitSlop={10} accessibilityLabel="Suche löschen">
              <Ionicons name="close-circle" size={18} color={colors.muted} />
            </Pressable>
          )}
        </View>
        {!searching && (
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={s.chips}>
            {tabs.map((t) => (
              <Chip key={t.key} label={label(t.key, t.label)} active={tab === t.key} onPress={() => setTab(t.key)} />
            ))}
          </ScrollView>
        )}
        {tenants.length > 1 && (
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={s.chips}>
            <Chip label="Alle Mandanten" small active={tenantId === null} onPress={() => setTenantId(null)} />
            {tenants.map((t) => (
              <Chip key={t.id} label={t.name} small active={tenantId === t.id} onPress={() => setTenantId(tenantId === t.id ? null : t.id)} />
            ))}
          </ScrollView>
        )}
        {searching && <Text style={s.hint}>Suche in allen Tickets, auch wartenden und geschlossenen · {total} Treffer</Text>}
      </View>

      {loading ? (
        <Loading />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(t) => String(t.id)}
          renderItem={({ item }) => <TicketRow ticket={item} onPress={() => router.push(`/ticket/${item.id}`)} />}
          contentContainerStyle={{ padding: 12, gap: 10, paddingBottom: 40 }}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => load('refresh')} tintColor={BRAND} colors={[BRAND]} />}
          onEndReached={() => hasMore && !loadingMore && load('more', page + 1)}
          onEndReachedThreshold={0.4}
          ListHeaderComponent={
            <>
              <UpdateReadyNotice style={{ marginBottom: 10 }} />
              {error && <ErrorBox message={error} onRetry={() => load('initial')} />}
            </>
          }
          ListEmptyComponent={error ? null : <Empty text={searching ? 'Keine Tickets gefunden.' : 'Keine Tickets in dieser Ansicht.'} />}
          ListFooterComponent={loadingMore ? <ActivityIndicator color={BRAND} style={{ marginTop: 8 }} /> : null}
        />
      )}
    </View>
  );
}

function Chip({ label, active, onPress, small }: { label: string; active: boolean; onPress: () => void; small?: boolean }) {
  return (
    <Pressable onPress={onPress} style={[s.chip, small && s.chipSmall, active && s.chipOn]} accessibilityRole="button" accessibilityState={{ selected: active }}>
      <Text style={[s.chipText, small && { fontSize: 12 }, active && { color: '#fff' }]}>{label}</Text>
    </Pressable>
  );
}

const s = StyleSheet.create({
  top: { backgroundColor: colors.card, borderBottomWidth: 1, borderBottomColor: colors.border, paddingTop: 10, paddingBottom: 6, gap: 6 },
  search: { flexDirection: 'row', alignItems: 'center', gap: 8, marginHorizontal: 12, backgroundColor: colors.bg, borderRadius: 10, paddingHorizontal: 10 },
  searchInput: { flex: 1, paddingVertical: 9, fontSize: 15, color: colors.text },
  chips: { paddingHorizontal: 12, gap: 6 },
  chip: { paddingVertical: 6, paddingHorizontal: 12, borderRadius: 999, borderWidth: 1.5, borderColor: BRAND },
  chipSmall: { paddingVertical: 4, paddingHorizontal: 10, borderColor: '#cbd5e1' },
  chipOn: { backgroundColor: BRAND, borderColor: BRAND },
  chipText: { color: BRAND, fontWeight: '600', fontSize: 13 },
  hint: { fontSize: 12, color: colors.muted, marginHorizontal: 12 },
});
