import type { Options, TicketEvent } from './api';

const pad = (n: number) => String(n).padStart(2, '0');

/** „14:05“ heute, „gestern 14:05“, sonst „03.11. 14:05“ bzw. mit Jahr */
export function dateTime(iso: string | null | undefined): string {
  if (!iso) return '–';
  const d = new Date(iso);
  const now = new Date();
  const time = `${pad(d.getHours())}:${pad(d.getMinutes())}`;
  const sameDay = (a: Date, b: Date) => a.toDateString() === b.toDateString();
  if (sameDay(d, now)) return time;
  const y = new Date(now);
  y.setDate(now.getDate() - 1);
  if (sameDay(d, y)) return `gestern ${time}`;
  const date = `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.`;
  return d.getFullYear() === now.getFullYear() ? `${date} ${time}` : `${date}${d.getFullYear()} ${time}`;
}

/** „vor 5 Min.“, „vor 3 Std.“, „vor 2 Tagen“ */
export function ago(iso: string | null | undefined): string {
  if (!iso) return '';
  const min = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
  if (min < 1) return 'gerade eben';
  if (min < 60) return `vor ${min} Min.`;
  const h = Math.round(min / 60);
  if (h < 24) return `vor ${h} Std.`;
  const d = Math.round(h / 24);
  return d === 1 ? 'vor 1 Tag' : `vor ${d} Tagen`;
}

/** Frist: „überfällig seit …“ oder „fällig in …“ */
export function due(iso: string | null): { text: string; overdue: boolean } | null {
  if (!iso) return null;
  const min = Math.round((new Date(iso).getTime() - Date.now()) / 60000);
  const abs = Math.abs(min);
  const span = abs < 60 ? `${abs} Min.` : abs < 1440 ? `${Math.round(abs / 60)} Std.` : `${Math.round(abs / 1440)} Tg.`;
  return min < 0 ? { text: `überfällig seit ${span}`, overdue: true } : { text: `fällig in ${span}`, overdue: false };
}

export function fileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;
}

export function minutes(total: number): string {
  if (total < 60) return `${total} Min.`;
  const h = Math.floor(total / 60);
  const m = total % 60;
  return m ? `${h} Std. ${m} Min.` : `${h} Std.`;
}

/** Ereignis im Verlauf als Satz – wie in der Weboberfläche */
export function eventText(e: TicketEvent, options: Options | null): string {
  const who = e.actor ?? 'System';
  const d = e.data ?? {};
  const userName = (id: unknown) => options?.users.find((u) => u.id === Number(id))?.name ?? 'jemandem';
  const priority = (v: unknown) => options?.priorities.find((p) => p.value === v)?.label ?? String(v ?? '–');
  switch (e.type) {
    case 'assigned':
      return d.to ? `${who} hat das Ticket ${userName(d.to)} zugewiesen` : `${who} hat die Zuweisung entfernt`;
    case 'status':
      return `${who}: Status ${d.from ?? '–'} → ${d.to ?? '–'}` + (d.auto ? ` (automatisch nach ${d.auto} Tagen)` : '') + (d.note ? ` (${d.note})` : '');
    case 'team':
      return `${who}: Team ${d.from ?? '–'} → ${d.to ?? '–'}`;
    case 'category':
      return `${who}: Kategorie ${d.from ?? '–'} → ${d.to ?? '–'}`;
    case 'priority':
      return `${who}: Priorität → ${priority(d.to)}`;
    case 'reminder':
      return 'Erinnerung an den Kunden verschickt';
    case 'fields':
      return `${who}: ` + Object.entries(d.changes ?? {}).map(([k, v]) => `${k} → ${v}`).join(', ');
    case 'rule':
      return `Regel „${d.rule ?? '–'}“ angewendet` + (d.team ? ` · Team → ${d.team}` : '');
    case 'merged_into':
      return `${who} hat dieses Ticket in ${d.ticket ?? '–'} zusammengeführt`;
    case 'merged_from':
      return `${who} hat ${d.ticket ?? '–'} hierher zusammengeführt`;
    case 'sender_blocked':
      return `${who} hat ${d.entry ?? 'den Absender'} für dieses Postfach gesperrt`;
    case 'anonymized':
      return `${who}: Kontaktdaten auf Wunsch gelöscht (Datenschutz)`;
    case 'sla_breach':
      return 'Frist überschritten';
    case 'time':
      return `${who}: ${minutes(Number(d.minutes ?? 0))} erfasst` + (d.note ? ` (${d.note})` : '');
    default:
      return `${who}: ${e.type}`;
  }
}

export const behaviourTone = (b: string | null | undefined): 'ok' | 'warn' | 'neutral' | 'primary' =>
  b === 'open' ? 'primary' : b === 'pending' ? 'warn' : b === 'closed' ? 'ok' : 'neutral';

export const priorityTone = (p: string | undefined): 'danger' | 'warn' | 'neutral' => (p === 'urgent' ? 'danger' : p === 'high' ? 'warn' : 'neutral');
