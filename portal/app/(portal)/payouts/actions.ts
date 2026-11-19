'use server';

import { api } from '@/lib/api';
import { attempt, idFrom, text, type ActionResult } from '@/lib/actions';

export async function recordPayout(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  return attempt(
    () =>
      api(`admin/organizers/${id}/payouts`, {
        method: 'POST',
        body: { amount: text(form, 'amount'), reference: text(form, 'reference'), note: text(form, 'note') },
      }),
    'Payout recorded.',
  );
}

export async function setCommission(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  const raw = text(form, 'percent');
  // Empty = use the platform default. Percent with up to 2 decimals → basis points.
  const bps = raw === undefined ? null : Math.round(Number(raw) * 100);
  if (bps !== null && !Number.isFinite(bps)) return { error: 'Enter a percentage, for example 8 or 7.5.' };
  return attempt(
    () => api(`admin/organizers/${id}/commission`, { method: 'PUT', body: { commission_bps: bps } }),
    bps === null ? 'This organizer now uses the platform commission.' : 'Commission saved for new sales.',
  );
}
