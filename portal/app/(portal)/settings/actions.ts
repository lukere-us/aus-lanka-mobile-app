'use server';

import { api } from '@/lib/api';
import { attempt, checked, compact, text, type ActionResult } from '@/lib/actions';

/** Percent fields are typed as % on screen and stored as basis points. */
const PERCENT_KEYS = ['ticket_commission_bps', 'booking_fee_bps'];
const KEYS = ['ticket_commission_bps', 'booking_fee_per_ticket', 'booking_fee_bps', 'ticket_refund_cutoff_hours', 'rental_refund_window_hours', 'payout_hold_days'];

export async function saveSettings(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const body: Record<string, string | number> = {};
  for (const key of KEYS) {
    const raw = text(form, key);
    if (raw === undefined) continue;
    if (PERCENT_KEYS.includes(key)) {
      const n = Number(raw);
      if (!Number.isFinite(n)) return { error: 'Percentages must be numbers, for example 10 or 2.5.' };
      body[key] = Math.round(n * 100);
    } else {
      body[key] = key === 'booking_fee_per_ticket' ? raw : Math.trunc(Number(raw));
    }
  }
  return attempt(() => api('admin/settings', { method: 'PUT', body }), 'Settings saved. They apply to new orders from now on.');
}

export async function saveCurrency(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const code = (text(form, 'code') ?? '').toUpperCase();
  if (!/^[A-Z]{3}$/.test(code)) return { error: 'Use a three-letter currency code, for example NZD.' };
  return attempt(
    () =>
      api(`admin/currencies/${code}`, {
        method: 'PUT',
        body: compact({
          name: text(form, 'name'),
          symbol: text(form, 'symbol'),
          rate_per_base: text(form, 'rate_per_base'),
          is_active: form.has('is_active_present') ? checked(form, 'is_active') : undefined,
        }),
      }),
    `${code} saved.`,
  );
}
