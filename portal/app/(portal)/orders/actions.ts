'use server';

import { api } from '@/lib/api';
import { attempt, checked, text, type ActionResult } from '@/lib/actions';

/** Staff refund of everything still refundable on the order (no policy checks; approved straight away). */
export async function refundOrder(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const reference = text(form, 'reference') ?? '';
  if (!/^[A-Z0-9-]+$/.test(reference)) return { error: 'Missing order reference.' };
  return attempt(
    () =>
      api(`admin/orders/${reference}/refunds`, {
        method: 'POST',
        body: { reason: text(form, 'reason'), include_fees: checked(form, 'include_fees') },
      }),
    'Refund sent to the payment provider. It shows as succeeded once the provider confirms.',
  );
}
