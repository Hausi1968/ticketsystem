import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { KeyboardProvider } from 'react-native-keyboard-controller';
import { SessionProvider, useSession } from '@/lib/session';
import { Loading } from '@/components/ui';
import { useForegroundUpdateCheck } from '@/lib/updates';
import { usePushNotifications } from '@/lib/push';

function RootNavigator() {
  const { status } = useSession();
  usePushNotifications();
  if (status === 'loading') return <Loading />;
  const signedIn = status === 'signedIn';
  return (
    <Stack screenOptions={{ headerShown: false }}>
      <Stack.Protected guard={signedIn}>
        <Stack.Screen name="(app)" />
      </Stack.Protected>
      <Stack.Protected guard={!signedIn}>
        <Stack.Screen name="login" />
      </Stack.Protected>
    </Stack>
  );
}

export default function RootLayout() {
  useForegroundUpdateCheck();
  return (
    <SafeAreaProvider>
      <KeyboardProvider>
        <SessionProvider>
          <StatusBar style="light" />
          <RootNavigator />
        </SessionProvider>
      </KeyboardProvider>
    </SafeAreaProvider>
  );
}
