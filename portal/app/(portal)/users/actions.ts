'use server';

import { api } from '@/lib/api';
import { attempt, idFrom, text, type ActionResult } from '@/lib/actions';
import { ROLES } from '@/lib/roles';

export async function saveRoles(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  const roles = ROLES.map((r) => r.slug).filter((slug) => form.get(`role_${slug}`) === 'on');
  return attempt(() => api(`admin/users/${id}/roles`, { method: 'PUT', body: { roles } }), 'Roles saved. The change applies to their next request.');
}

export async function setStatus(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  const status = text(form, 'status');
  return attempt(
    () => api(`admin/users/${id}/status`, { method: 'PATCH', body: { status, reason: text(form, 'reason') } }),
    status === 'suspended' ? 'Account suspended and signed out everywhere.' : 'Account reactivated.',
  );
}
