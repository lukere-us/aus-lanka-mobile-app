import type { Metadata } from 'next';
import { LoginForm } from './LoginForm';

export const metadata: Metadata = { title: 'Sign in' };

export default async function LoginPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const params = await searchParams;
  const notice = params.expired ? 'Your session has ended. Please sign in again.' : undefined;

  return (
    <main className="auth-page">
      <div className="auth-card card">
        <div className="brand brand-lg">
          <span className="brand-mark" aria-hidden>AL</span>
          <span>Aus Lanka</span>
        </div>
        <h1>Sign in</h1>
        <p className="muted">For administrators, finance, content and event teams, and organizers.</p>
        <LoginForm next={params.next} notice={notice} />
      </div>
    </main>
  );
}
