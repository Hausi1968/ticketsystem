import { useEffect } from 'react';
import { Platform } from 'react-native';
import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import { router } from 'expo-router';
import { useSession } from './session';

// Benachrichtigungen auch zeigen, wenn die App gerade offen ist
Notifications.setNotificationHandler({
  handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: true, shouldSetBadge: false }),
});

const projectId: string | undefined = Constants.expoConfig?.extra?.eas?.projectId ?? Constants.easConfig?.projectId;

/** Push-Adresse dieses Geräts holen (fragt beim ersten Mal nach der Erlaubnis); null = nicht möglich */
async function pushToken(): Promise<string | null> {
  if (Platform.OS === 'web' || !Device.isDevice || !projectId) return null;
  if (Platform.OS === 'android') {
    // Muss zum channelId passen, den der Server mitschickt
    await Notifications.setNotificationChannelAsync('tickets', {
      name: 'Tickets',
      importance: Notifications.AndroidImportance.HIGH,
      vibrationPattern: [0, 200, 120, 200],
      lightColor: '#4f46e5',
    });
  }
  let { status } = await Notifications.getPermissionsAsync();
  if (status !== 'granted') status = (await Notifications.requestPermissionsAsync()).status;
  if (status !== 'granted') return null;
  return (await Notifications.getExpoPushTokenAsync({ projectId })).data;
}

function openFromNotification(response: Notifications.NotificationResponse | null) {
  const id = response?.notification.request.content.data?.ticket_id;
  if (id) router.push(`/ticket/${id}`);
}

/** Nach der Anmeldung: Push-Adresse beim Server hinterlegen und Tippen auf eine Benachrichtigung öffnet das Ticket */
export function usePushNotifications() {
  const { status, request } = useSession();
  const signedIn = status === 'signedIn';

  useEffect(() => {
    if (!signedIn) return;
    pushToken()
      .then((token) => (token ? request('push-token', { body: { push_token: token } }) : null))
      .catch(() => {
        // ohne Push geht die App trotzdem – beim nächsten Start erneut versuchen
      });
  }, [signedIn, request]);

  useEffect(() => {
    if (!signedIn || Platform.OS === 'web') return;
    // App wurde über eine Benachrichtigung gestartet
    openFromNotification(Notifications.getLastNotificationResponse());
    const sub = Notifications.addNotificationResponseReceivedListener(openFromNotification);
    return () => sub.remove();
  }, [signedIn]);
}
