'use server';

import { api } from '@/lib/api';
import { attempt, idFrom, text, type ActionResult } from '@/lib/actions';

export async function approveRefund(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  return attempt(
    () => api(`admin/refunds/${id}/approve`, { method: 'POST', body: { note: text(form, 'note') } }),
    'Approved and sent to the payment provider.',
  );
}

export async function rejectRefund(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  return attempt(() => api(`admin/refunds/${id}/reject`, { method: 'POST', body: { note: text(form, 'note') } }), 'Refund request rejected.');
}
