'use server';

import { api } from '@/lib/api';
import { attempt, idFrom, text, type ActionResult } from '@/lib/actions';

export async function reviewEvent(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  const decision = text(form, 'decision');
  return attempt(
    () => api(`admin/events/${id}/review`, { method: 'POST', body: { decision, note: text(form, 'note') } }),
    decision === 'approve' ? 'Event approved and published.' : 'Sent back to the organizer with your note.',
  );
}

export async function cancelEvent(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  return attempt(
    () => api(`admin/events/${id}/cancel`, { method: 'POST', body: { reason: text(form, 'reason') } }),
    'Event cancelled. Ticket holders are notified and refunds are being sent in the background.',
  );
}
