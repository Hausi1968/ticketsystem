/**
 * Zugriff auf die Mitarbeiter-Schnittstelle von Anliego (/api/agent/v1, siehe docs/agent-api.md).
 * Alle Rechte und die Mandantentrennung prüft der Server – die App zeigt nur, was der Mitarbeiter auch im Browser sieht.
 */

import { Platform } from 'react-native';
import appConfig from '../../app.json';
import { updateLabel } from './updates';

export const APP_VERSION: string = appConfig.expo.version;

export class ApiError extends Error {
  status: number;
  code: string;
  data: any;
  constructor(message: string, status: number, code: string, data?: any) {
    super(message);
    this.status = status;
    this.code = code;
    this.data = data;
  }
}

/** Server-Adresse vereinheitlichen: immer https, ohne Pfad zur Weboberfläche und ohne Schrägstrich am Ende */
export function normalizeServer(input: string): string {
  let s = input.trim();
  if (s === '') return '';
  // Passwort und Token gehen nie unverschlüsselt über die Leitung – Ausnahme nur zum Testen auf dem eigenen Rechner
  const local = /^(http:\/\/)?(localhost|127\.0\.0\.1)(:\d+)?(\/|$)/i.test(s);
  if (local) s = s.replace(/^(?!http:\/\/)/i, 'http://');
  else {
    s = s.replace(/^http:\/\//i, 'https://');
    if (!/^https:\/\//i.test(s)) s = 'https://' + s;
  }
  s = s.replace(/[?#].*$/, '').replace(/\/(login|tickets.*|admin.*)$/i, '').replace(/\/+$/, '');
  return s;
}

export const apiBase = (server: string) => `${server}/api/agent/v1`;

type Params = Record<string, string | number | boolean | undefined | null>;

export type RequestOptions = {
  method?: 'GET' | 'POST';
  params?: Params;
  body?: unknown;
  /** multipart (Datei-Upload) */
  form?: FormData;
  token?: string | null;
};

export async function apiRequest<T>(server: string, path: string, opts: RequestOptions = {}): Promise<T> {
  const query = new URLSearchParams();
  for (const [k, v] of Object.entries(opts.params ?? {})) {
    if (v !== undefined && v !== null && v !== '' && v !== false) query.set(k, v === true ? '1' : String(v));
  }
  const qs = query.toString();
  const headers: Record<string, string> = { Accept: 'application/json', 'X-App-Version': APP_VERSION, 'X-App-Platform': Platform.OS, 'X-App-Update': updateLabel() };
  if (opts.token) headers.Authorization = 'Bearer ' + opts.token;
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';

  let res: Response;
  try {
    res = await fetch(`${apiBase(server)}/${path}${qs ? '?' + qs : ''}`, {
      method: opts.method ?? (opts.body !== undefined || opts.form ? 'POST' : 'GET'),
      headers,
      body: opts.form ?? (opts.body !== undefined ? JSON.stringify(opts.body) : undefined),
    });
  } catch {
    throw new ApiError('Keine Verbindung zum Server. Bitte Internetverbindung und Server-Adresse prüfen.', 0, 'network');
  }

  let data: any = null;
  try {
    data = await res.json();
  } catch {
    throw new ApiError(
      res.status === 404 ? 'Unter dieser Adresse wurde kein Anliego gefunden. Bitte die Adresse prüfen.' : 'Unerwartete Antwort vom Server (' + res.status + ').',
      res.status,
      'format',
    );
  }
  if (!res.ok) {
    const firstValidation = data?.errors ? (Object.values(data.errors)[0] as string[] | undefined)?.[0] : undefined;
    const message = firstValidation ?? data?.message ?? (res.status === 403 ? 'Keine Berechtigung.' : res.status === 404 ? 'Nicht gefunden.' : 'Fehler ' + res.status);
    throw new ApiError(message, res.status, data?.error ?? 'error', data);
  }
  return data as T;
}

/* ---------- Datentypen der Antworten ---------- */

export type Tenant = { id: number; name: string; short_code: string; color?: string | null };

export type Me = {
  id: number;
  name: string;
  email: string;
  is_admin: boolean;
  tenants: { id: number; name: string; short_code: string; role: string | null }[];
  /** Version der Weboberfläche bzw. des Servers */
  server?: { version: string; label: string; commit: string | null; built_at: string | null };
};

export type MfaChallenge = { mfa_required: true; mfa_token: string };

export type StatusRef = { id: number; name: string; color: string | null; behaviour: 'open' | 'pending' | 'closed' | null };

export type TicketSummary = {
  id: number;
  reference: string;
  subject: string;
  tenant: Tenant;
  status: StatusRef | null;
  category: { id: number; name: string } | null;
  priority: { value: string; label: string };
  assignee: { id: number; name: string } | null;
  team: { id: number; name: string } | null;
  contact: { name: string | null; email: string | null } | null;
  channel: string;
  sla_due_at: string | null;
  snoozed_until?: string | null;
  error_count: number;
  created_at: string;
  updated_at: string;
};

export type Attachment = { id: number; filename: string; mime_type: string; size: number; url: string };

export type Message = {
  id: number;
  type: 'customer' | 'reply' | 'note' | 'forward' | string;
  author: string | null;
  from_email: string | null;
  to: string[];
  cc: string[];
  body: string;
  body_full: string | null;
  delivery_status: string | null;
  created_at: string;
  attachments: Attachment[];
};

export type TicketEvent = { type: string; actor: string | null; data: Record<string, any>; created_at: string };

export type TicketDetail = TicketSummary & {
  fields: Record<string, any>;
  participants: string[];
  time_minutes: number;
  merged_into: string | null;
  can: { reply: boolean; note: boolean; edit: boolean; take: boolean };
  others: { name: string; writing: boolean }[];
  last_message_id: number | null;
  messages: Message[];
  events: TicketEvent[];
};

export type TicketList = {
  data: TicketSummary[];
  page: number;
  has_more: boolean;
  total: number;
  counts: { mine: number; unassigned: number; teams?: number };
};

export type Options = {
  tabs: { key: string; label: string }[];
  statuses: (StatusRef & { closes: boolean })[];
  priorities: { value: string; label: string }[];
  categories: { id: number; name: string; tenant_id: number | null }[];
  tenants: Tenant[];
  users: { id: number; name: string; tenant_ids: number[] }[];
  teams?: { id: number; name: string; tenant_id: number }[];
};

/** Antwort der Bearbeitungs-Aktionen */
export type TicketUpdate = { ok: true; ticket: TicketDetail; message?: string; delivery_status?: string };
