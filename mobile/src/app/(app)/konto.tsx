import { Alert, Linking } from 'react-native';
import { useSession } from '@/lib/session';
import { APP_VERSION } from '@/lib/api';
import { updateLabel } from '@/lib/updates';
import { Button, Card, InfoList, Notice, Screen } from '@/components/ui';
import { UpdateReadyNotice, UpdateStatus } from '@/components/UpdateNotice';

export default function Account() {
  const { me, server, signOut } = useSession();
  const confirmSignOut = () =>
    Alert.alert('Abmelden', 'Auf diesem Gerät abmelden? Push-Benachrichtigungen kommen dann nicht mehr an.', [
      { text: 'Abbrechen', style: 'cancel' },
      { text: 'Abmelden', style: 'destructive', onPress: signOut },
    ]);
  return (
    <Screen>
      <UpdateReadyNotice />
      <Card title={me?.name ?? 'Konto'}>
        <InfoList
          items={[
            ['E-Mail', me?.email ?? ''],
            ['Server', server.replace(/^https:\/\//, '')],
            ['Rolle', me?.is_admin ? 'Administrator' : ''],
            ['Mandanten', (me?.tenants ?? []).map((t) => t.name).join(', ')],
          ]}
        />
      </Card>
      <Button title="Benutzerhandbuch" icon="book-outline" variant="ghost" onPress={() => Linking.openURL(server + '/handbuch.pdf')} />
      <Notice text="Profil, Passwort, Zwei-Faktor-Anmeldung, Postfächer und alle Einstellungen bearbeitest du im Browser." />
      <Card title="Versionen">
        <InfoList
          items={[
            ['App', `${APP_VERSION} (${updateLabel()})`],
            ['Web / Server', me?.server?.label ?? 'unbekannt (Server zu alt oder offline)'],
          ]}
        />
        <UpdateStatus />
      </Card>
      <Button title="Abmelden" icon="log-out-outline" variant="danger" onPress={() => (globalThis.confirm ? globalThis.confirm('Abmelden?') && signOut() : confirmSignOut())} />
    </Screen>
  );
}
