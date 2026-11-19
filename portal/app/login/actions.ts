'use server';

import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { api, ApiError } from '@/lib/api';
import { DEVICE_NAME, SESSION_COOKIE, SESSION_COOKIE_SECURE } from '@/lib/config';
import { canUsePortal, type CurrentUser } from '@/lib/session';

export interface LoginState {
  error?: string;
  email?: string;
}

interface LoginResponse {
  data: { token: string; expires_at: string; user: CurrentUser };
}

export async function login(_prev: LoginState, form: FormData): Promise<LoginState> {
  const email = String(form.get('email') ?? '').trim();
  const password = String(form.get('password') ?? '');
  if (!email || !password) return { error: 'Enter your email and password.', email };

  let result: LoginResponse['data'];
  try {
    result = (await api<LoginResponse>('auth/login', {
      method: 'POST',
      anonymous: true,
      body: { email, password, device_name: DEVICE_NAME },
    })).data;
  } catch (e) {
    if (e instanceof ApiError) {
      if (e.status === 429) return { error: 'Too many attempts. Wait a minute and try again.', email };
      return { error: e.field('email') ?? e.message, email };
    }
    throw e;
  }

  // Customers and gate-only staff have nothing to do here; don't leave their new token lying around.
  const user = (await api<{ data: CurrentUser }>('me', { token: result.token })).data;
  if (!canUsePortal(user)) {
    await api('auth/logout', { method: 'POST', token: result.token }).catch(() => undefined);
    return { error: 'This account has no access to the admin website. Use the mobile app instead.', email };
  }

  (await cookies()).set(SESSION_COOKIE, result.token, {
    httpOnly: true,
    secure: SESSION_COOKIE_SECURE,
    sameSite: 'lax',
    path: '/',
    expires: new Date(result.expires_at),
  });

  const next = String(form.get('next') ?? '');
  redirect(next.startsWith('/') && !next.startsWith('//') ? next : '/');
}

export async function logout(): Promise<void> {
  const jar = await cookies();
  const token = jar.get(SESSION_COOKIE)?.value;
  if (token) {
    // Revoke the token on the API too, so a copied cookie stops working.
    await api('auth/logout', { method: 'POST', token }).catch(() => undefined);
  }
  jar.delete(SESSION_COOKIE);
  redirect('/login');
}
