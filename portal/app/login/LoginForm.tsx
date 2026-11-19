'use client';

import { useActionState } from 'react';
import { login, type LoginState } from './actions';

export function LoginForm({ next, notice }: { next?: string; notice?: string }) {
  const [state, action, pending] = useActionState<LoginState, FormData>(login, {});

  return (
    <form action={action} className="stack" noValidate>
      {notice && !state.error && <p className="alert alert-info">{notice}</p>}
      {state.error && (
        <p className="alert alert-error" role="alert">
          {state.error}
        </p>
      )}
      <input type="hidden" name="next" value={next ?? ''} />
      <label className="field">
        <span>Email</span>
        <input name="email" type="email" autoComplete="username" defaultValue={state.email} required autoFocus />
      </label>
      <label className="field">
        <span>Password</span>
        <input name="password" type="password" autoComplete="current-password" required />
      </label>
      <button type="submit" className="btn btn-primary" disabled={pending}>
        {pending ? 'Signing in…' : 'Sign in'}
      </button>
    </form>
  );
}
