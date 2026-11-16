import { Stack } from 'expo-router';
import { BRAND } from '@/lib/session';

export default function AppLayout() {
  return (
    <Stack
      screenOptions={{
        headerStyle: { backgroundColor: BRAND },
        headerTintColor: '#fff',
        headerTitleStyle: { fontWeight: '700' },
        headerBackTitle: 'Zurück',
      }}
    >
      <Stack.Screen name="index" options={{ title: 'Tickets' }} />
      <Stack.Screen name="ticket/[id]" options={{ title: 'Ticket' }} />
      <Stack.Screen name="schreiben" options={{ title: 'Antworten', presentation: 'modal' }} />
      <Stack.Screen name="konto" options={{ title: 'Konto' }} />
    </Stack>
  );
}
