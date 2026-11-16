import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { Platform } from 'react-native';
import { ApiError, apiBase, apiRequest, Me, MfaChallenge, normalizeServer, Options, RequestOptions } from './api';
import { deleteItem, getItem, setItem } from './storage';

const KEY_SERVER = 'anl_server';
const KEY_TOKEN = 'anl_token';

/** Hauptfarbe wie in der Weboberfläche (Indigo) */
export const BRAND = '#4f46e5';

type SessionState = {
  status: 'loading' | 'signedOut' | 'signedIn';
  server: string;
  token: string | null;
  me: Me | null;
  options: Options | null;
  /** Anfrage mit dem gespeicherten Token; bei abgelaufener Anmeldung wird automatisch abgemeldet */
  request: <T>(path: string, opts?: RequestOptions) => Promise<T>;
  /** Liefert die Rückfrage, wenn nach dem Passwort noch der Code aus der Authenticator-App nötig ist */
  signIn: (server: string, email: string, password: string) => Promise<MfaChallenge | null>;
  verifyMfa: (challenge: MfaChallenge, code: string) => Promise<void>;
  signOut: () => Promise<void>;
  reloadOptions: () => Promise<void>;
  /** Vollständige Adresse eines Anhangs plus Anmelde-Header (zum Herunterladen) */
  authHeaders: () => Record<string, string>;
  lastServer: string;
};

const SessionContext = createContext<SessionState | null>(null);

const deviceName = () => (Platform.OS === 'android' ? 'Android-App' : Platform.OS === 'ios' ? 'iPhone-App' : 'Browser-App');

export function SessionProvider({ children }: { children: React.ReactNode }) {
  const [status, setStatus] = useState<SessionState['status']>('loading');
  const [server, setServer] = useState('');
  const [lastServer, setLastServer] = useState('');
  const [me, setMe] = useState<Me | null>(null);
  const [options, setOptions] = useState<Options | null>(null);
  const [tokenState, setTokenState] = useState<string | null>(null);
  const token = useRef<string | null>(null);
  const pendingServer = useRef('');

  const clear = useCallback(async () => {
    token.current = null;
    setTokenState(null);
    await deleteItem(KEY_TOKEN);
    setMe(null);
    setOptions(null);
    setStatus('signedOut');
  }, []);

  const request = useCallback(
    async <T,>(path: string, opts: RequestOptions = {}): Promise<T> => {
      try {
        return await apiRequest<T>(server, path, { ...opts, token: token.current });
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) await clear();
        throw e;
      }
    },
    [server, clear],
  );

  const loadOptions = useCallback(async (s: string) => {
    try {
      setOptions(await apiRequest<Options>(s, 'options', { token: token.current }));
    } catch {
      // offline – wird beim nächsten Öffnen eines Tickets erneut versucht
    }
  }, []);

  // Beim Start: gespeicherte Anmeldung prüfen
  useEffect(() => {
    (async () => {
      const s = normalizeServer((await getItem(KEY_SERVER)) ?? '');
      const t = await getItem(KEY_TOKEN);
      setServer(s);
      setLastServer(s);
      if (!s || !t) {
        setStatus('signedOut');
        return;
      }
      token.current = t;
      setTokenState(t);
      try {
        setMe(await apiRequest<Me>(s, 'me', { token: t }));
        setStatus('signedIn');
        loadOptions(s);
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) await clear();
        else setStatus('signedIn'); // offline: angemeldet bleiben
      }
    })();
  }, [clear, loadOptions]);

  const finishSignIn = useCallback(
    async (s: string, data: Me & { token: string }) => {
      token.current = data.token;
      setTokenState(data.token);
      await setItem(KEY_SERVER, s);
      await setItem(KEY_TOKEN, data.token);
      setServer(s);
      setLastServer(s);
      const { token: _t, ...rest } = data;
      setMe(rest);
      setStatus('signedIn');
      loadOptions(s);
    },
    [loadOptions],
  );

  const signIn = useCallback(
    async (serverInput: string, email: string, password: string) => {
      const s = normalizeServer(serverInput);
      if (!s) throw new ApiError('Bitte die Adresse eures Anliego eingeben.', 0, 'input');
      const data = await apiRequest<(Me & { token: string }) | MfaChallenge>(s, 'login', { body: { email: email.trim(), password, device: deviceName() } });
      if ('mfa_required' in data && data.mfa_required) {
        pendingServer.current = s;
        return data;
      }
      await finishSignIn(s, data as Me & { token: string });
      return null;
    },
    [finishSignIn],
  );

  const verifyMfa = useCallback(
    async (challenge: MfaChallenge, code: string) => {
      const s = pendingServer.current;
      const data = await apiRequest<Me & { token: string }>(s, 'login/mfa', { body: { mfa_token: challenge.mfa_token, code: code.trim() } });
      await finishSignIn(s, data);
    },
    [finishSignIn],
  );

  const signOut = useCallback(async () => {
    try {
      if (token.current) await apiRequest(server, 'logout', { body: {}, token: token.current });
    } catch {
      // Abmelden klappt auch offline – das Token wird lokal gelöscht
    }
    await clear();
  }, [server, clear]);

  const value = useMemo<SessionState>(
    () => ({
      status,
      server,
      token: tokenState,
      me,
      options,
      request,
      signIn,
      verifyMfa,
      signOut,
      reloadOptions: () => loadOptions(server),
      authHeaders: (): Record<string, string> => (token.current ? { Authorization: 'Bearer ' + token.current } : {}),
      lastServer,
    }),
    [status, server, tokenState, me, options, request, signIn, verifyMfa, signOut, loadOptions, lastServer],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionState {
  const ctx = useContext(SessionContext);
  if (!ctx) throw new Error('useSession außerhalb von SessionProvider');
  return ctx;
}

export { apiBase };
