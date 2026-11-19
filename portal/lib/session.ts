import { cache } from 'react';
import { redirect } from 'next/navigation';
import { api, sessionToken } from './api';

export interface CurrentUser {
  id: number;
  name: string;
  email: string;
  status: string;
  roles: string[];
  /** ['*'] for a super admin. */
  permissions: string[];
}

/** Permissions that give access to at least one screen of this website. Customers and gate-only staff have none. */
export const PORTAL_PERMISSIONS = [
  'users.view',
  'users.manage',
  'roles.assign',
  'organizers.approve',
  'movies.manage',
  'events.manage',
  'events.approve',
  'orders.view',
  'refunds.approve',
  'payouts.manage',
  'reports.view',
  'settings.manage',
  'audit.view',
  'organizer.events.manage',
] as const;

export type Permission = (typeof PORTAL_PERMISSIONS)[number] | 'organizer.attendees.view' | 'organizer.staff.manage';

export function can(user: Pick<CurrentUser, 'permissions'>, permission: Permission): boolean {
  return user.permissions.includes('*') || user.permissions.includes(permission);
}

export function canUsePortal(user: Pick<CurrentUser, 'permissions'>): boolean {
  return PORTAL_PERMISSIONS.some((p) => can(user, p));
}

/** The signed-in user, fetched once per request. Null when nobody is signed in. */
export const currentUser = cache(async (): Promise<CurrentUser | null> => {
  if (!(await sessionToken())) return null;
  const res = await api<{ data: CurrentUser }>('me');
  return res.data;
});

/** Route guard for pages: sends visitors to sign-in. */
export async function requireUser(): Promise<CurrentUser> {
  const user = await currentUser();
  if (!user) redirect('/login');
  return user;
}
