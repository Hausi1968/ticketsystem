import { useEffect, useState } from 'react';
import { Image, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { KeyboardAwareScrollView } from 'react-native-keyboard-controller';
import { Ionicons } from '@expo/vector-icons';
import { BRAND, useSession } from '@/lib/session';
import { MfaChallenge } from '@/lib/api';
import { Button, colors, ErrorBox, Label, Notice } from '@/components/ui';

export default function Login() {
  const { signIn, verifyMfa, lastServer } = useSession();
  const [address, setAddress] = useState(lastServer);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [challenge, setChallenge] = useState<MfaChallenge | null>(null);
  const [code, setCode] = useState('');
  const [recovery, setRecovery] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (lastServer && !address) setAddress(lastServer);
  }, [lastServer, address]);

  const submit = async () => {
    setError(null);
    if (!address.trim() || !email.trim() || !password) {
      setError('Bitte Adresse, E-Mail-Adresse und Passwort eingeben.');
      return;
    }
    setBusy(true);
    try {
      const c = await signIn(address, email, password);
      if (c) {
        setChallenge(c);
        setCode('');
        setPassword('');
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Anmeldung fehlgeschlagen.');
    } finally {
      setBusy(false);
    }
  };

  const submitCode = async () => {
    if (!challenge) return;
    setError(null);
    if (!code.trim()) {
      setError(recovery ? 'Bitte einen Wiederherstellungscode eingeben.' : 'Bitte den 6-stelligen Code eingeben.');
      return;
    }
    setBusy(true);
    try {
      await verifyMfa(challenge, code);
    } catch (e) {
      const c = (e as { code?: string })?.code;
      if (c === 'mfa_expired') setChallenge(null);
      setError(e instanceof Error ? e.message : 'Der Code stimmt nicht.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: BRAND }}>
      <KeyboardAwareScrollView contentContainerStyle={s.wrap} keyboardShouldPersistTaps="handled" bottomOffset={24}>
        <Image source={require('../../assets/icon.png')} style={s.logo} />
        <Text style={s.title}>Anliego</Text>
        <Text style={s.sub}>{challenge ? 'Zweiter Schritt: Code aus der Authenticator-App' : 'Mit deinem Mitarbeiter-Zugang anmelden – wie im Browser.'}</Text>

        {challenge ? (
          <View style={s.card}>
            <Label>{recovery ? 'Wiederherstellungscode' : 'Code aus der Authenticator-App'}</Label>
            <TextInput
              style={[s.input, !recovery && s.code]}
              value={code}
              onChangeText={setCode}
              keyboardType={recovery ? 'default' : 'number-pad'}
              autoCapitalize="none"
              autoCorrect={false}
              maxLength={recovery ? 64 : 6}
              textContentType="oneTimeCode"
              autoComplete="one-time-code"
              autoFocus
              onSubmitEditing={submitCode}
              returnKeyType="go"
            />
            {error && <ErrorBox message={error} />}
            <Button title={busy ? 'Prüfen …' : 'Anmelden'} onPress={submitCode} disabled={busy} style={{ marginTop: 16 }} />
            <Pressable
              onPress={() => {
                setRecovery((r) => !r);
                setCode('');
                setError(null);
              }}
              style={{ marginTop: 14, alignSelf: 'center' }}
              accessibilityRole="button"
            >
              <Text style={{ color: BRAND, fontWeight: '600' }}>{recovery ? 'Code aus der App verwenden' : 'Handy mit der Authenticator-App nicht dabei?'}</Text>
            </Pressable>
          </View>
        ) : (
          <View style={s.card}>
            <Label>Adresse von Anliego</Label>
            <TextInput
              style={s.input}
              value={address}
              onChangeText={setAddress}
              placeholder="z.B. tickets.firma.de"
              placeholderTextColor={colors.muted}
              autoCapitalize="none"
              autoCorrect={false}
              keyboardType="url"
              textContentType="URL"
            />
            <Text style={s.hint}>Die Adresse, unter der du Anliego im Browser öffnest.</Text>
            <Label>E-Mail-Adresse</Label>
            <TextInput
              style={s.input}
              value={email}
              onChangeText={setEmail}
              autoCapitalize="none"
              autoCorrect={false}
              keyboardType="email-address"
              textContentType="username"
              autoComplete="email"
            />
            <Label>Passwort</Label>
            <TextInput
              style={s.input}
              value={password}
              onChangeText={setPassword}
              secureTextEntry
              textContentType="password"
              autoComplete="password"
              onSubmitEditing={submit}
              returnKeyType="go"
            />
            {error && <ErrorBox message={error} />}
            <Button title={busy ? 'Anmelden …' : 'Anmelden'} onPress={submit} disabled={busy} style={{ marginTop: 16 }} />
          </View>
        )}

        {challenge ? (
          <Pressable
            onPress={() => {
              setChallenge(null);
              setError(null);
            }}
            style={s.back}
            accessibilityRole="button"
          >
            <Ionicons name="arrow-back" size={16} color="#fff" />
            <Text style={{ color: '#fff', fontWeight: '600' }}>Zurück</Text>
          </Pressable>
        ) : (
          <Notice text="Passwort vergessen oder Zwei-Faktor-Anmeldung noch nicht eingerichtet? Das geht im Browser unter deinem Profil." />
        )}
      </KeyboardAwareScrollView>
    </SafeAreaView>
  );
}

const s = StyleSheet.create({
  wrap: { padding: 20, paddingTop: 40, gap: 8, flexGrow: 1 },
  logo: { width: 76, height: 76, borderRadius: 18, alignSelf: 'center' },
  title: { color: '#fff', fontSize: 26, fontWeight: '800', textAlign: 'center', marginTop: 10 },
  sub: { color: '#e0e7ff', textAlign: 'center', marginBottom: 12 },
  card: { backgroundColor: colors.card, borderRadius: 16, padding: 16, gap: 4 },
  input: { borderWidth: 1, borderColor: '#d1d5db', borderRadius: 10, paddingHorizontal: 12, paddingVertical: 11, fontSize: 16, color: colors.text, backgroundColor: '#fff' },
  code: { fontSize: 24, letterSpacing: 6, textAlign: 'center' },
  hint: { color: colors.muted, fontSize: 12, marginTop: 4 },
  back: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, marginTop: 14, paddingVertical: 10 },
});
