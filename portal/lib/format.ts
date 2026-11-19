import { BASE_CURRENCY, DISPLAY_TIMEZONE } from './config';

/** API money comes as decimal strings ("1234.50"). */
export function money(value: string | number | null | undefined, currency = BASE_CURRENCY): string {
  const n = typeof value === 'string' ? Number(value) : (value ?? 0);
  // "$" for AUD (Australian site); other currencies keep a clear symbol such as "USD 99.90".
  const currencyDisplay = currency === 'AUD' ? 'narrowSymbol' : 'code';
  return new Intl.NumberFormat('en-AU', { style: 'currency', currency, currencyDisplay }).format(
    Number.isFinite(n) ? n : 0,
  );
}

export function count(n: number | null | undefined): string {
  return new Intl.NumberFormat('en-AU').format(n ?? 0);
}

/** ISO UTC timestamp → Sydney local date and time. */
export function dateTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  return new Intl.DateTimeFormat('en-AU', { dateStyle: 'medium', timeStyle: 'short', timeZone: DISPLAY_TIMEZONE }).format(
    new Date(iso),
  );
}

/** "2026-11-17" → "17 Nov". */
export function shortDay(ymd: string): string {
  const [y, m, d] = ymd.split('-').map(Number);
  return new Intl.DateTimeFormat('en-AU', { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(
    new Date(Date.UTC(y, m - 1, d)),
  );
}

/** Today's date in Sydney as Y-m-d. */
export function todayLocal(): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: DISPLAY_TIMEZONE }).format(new Date());
}

/** Shift a Y-m-d date by whole days. */
export function addDays(ymd: string, days: number): string {
  const [y, m, d] = ymd.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
}

export function isYmd(value: unknown): value is string {
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);
}

/** Date and time shown in a given timezone, e.g. an event in its venue's local time. */
export function dateTimeIn(iso: string | null | undefined, timeZone: string): string {
  if (!iso) return '—';
  return new Intl.DateTimeFormat('en-AU', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZone, timeZoneName: 'short' }).format(new Date(iso));
}

function offsetMs(utcMs: number, timeZone: string): number {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-US', { timeZone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' })
      .formatToParts(new Date(utcMs))
      .map((p) => [p.type, p.value]),
  );
  const asUtc = Date.UTC(+parts.year, +parts.month - 1, +parts.day, +parts.hour, +parts.minute, +parts.second);
  return asUtc - Math.floor(utcMs / 1000) * 1000;
}

/** ISO instant → value for <input type="datetime-local"> in that timezone ("2026-11-29T20:00"). */
export function toLocalInput(iso: string | null | undefined, timeZone: string): string {
  if (!iso) return '';
  const ms = new Date(iso).getTime();
  return new Date(ms + offsetMs(ms, timeZone)).toISOString().slice(0, 16);
}

/** <input type="datetime-local"> value meant in that timezone → UTC ISO string for the API. */
export function fromLocalInput(value: string | undefined, timeZone: string): string | undefined {
  if (!value || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(value)) return undefined;
  const [d, t] = value.split('T');
  const [y, m, day] = d.split('-').map(Number);
  const [h, min] = t.split(':').map(Number);
  const guess = Date.UTC(y, m - 1, day, h, min);
  let utc = guess - offsetMs(guess, timeZone);
  utc = guess - offsetMs(utc, timeZone); // second pass settles daylight-saving edges
  return new Date(utc).toISOString().replace('.000Z', 'Z');
}

/** Basis points → "10%" / "2.5%". */
export function percent(bps: number): string {
  return `${Number((bps / 100).toFixed(2))}%`;
}
