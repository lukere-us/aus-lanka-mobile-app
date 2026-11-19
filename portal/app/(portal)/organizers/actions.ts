'use server';

import { api } from '@/lib/api';
import { attempt, idFrom, text, type ActionResult } from '@/lib/actions';

const DONE: Record<string, string> = {
  approved: 'Organizer approved. They can now create events.',
  rejected: 'Application rejected.',
  suspended: 'Organizer suspended.',
};

export async function decideOrganizer(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  const status = text(form, 'status') ?? '';
  return attempt(
    () => api(`admin/organizers/${id}/status`, { method: 'PATCH', body: { status, reason: text(form, 'reason') } }),
    DONE[status] ?? 'Saved.',
  );
}
