import Link from 'next/link';
import type { ApiError } from '@/lib/api';

export function PageHeader({ title, subtitle, actions }: { title: string; subtitle?: React.ReactNode; actions?: React.ReactNode }) {
  return (
    <header className="page-header">
      <div>
        <h1>{title}</h1>
        {subtitle && <p className="muted">{subtitle}</p>}
      </div>
      {actions && <div className="page-actions">{actions}</div>}
    </header>
  );
}

export function Card({ title, children, className }: { title?: string; children: React.ReactNode; className?: string }) {
  return (
    <section className={'card' + (className ? ' ' + className : '')}>
      {title && <h2 className="card-title">{title}</h2>}
      {children}
    </section>
  );
}

export function Stat({ label, value, hint }: { label: string; value: React.ReactNode; hint?: React.ReactNode }) {
  return (
    <div className="stat">
      <div className="stat-label">{label}</div>
      <div className="stat-value">{value}</div>
      {hint && <div className="stat-hint">{hint}</div>}
    </div>
  );
}

export function Forbidden() {
  return (
    <>
      <PageHeader title="Not available" />
      <div className="card">
        <p>Your role doesn’t include this screen. Ask an administrator if you need it.</p>
        <p>
          <Link href="/">Back to home</Link>
        </p>
      </div>
    </>
  );
}

/** Shows an API failure in plain words, with the request ID for support. */
export function ApiErrorPanel({ error }: { error: ApiError }) {
  return (
    <div className="alert alert-error" role="alert">
      <strong>{error.message}</strong>
      {error.details && (
        <ul>
          {Object.entries(error.details).map(([field, messages]) => (
            <li key={field}>{messages.join(' ')}</li>
          ))}
        </ul>
      )}
      {error.requestId && <div className="small muted">Request ID: {error.requestId}</div>}
    </div>
  );
}

export function Empty({ children }: { children: React.ReactNode }) {
  return <p className="empty">{children}</p>;
}
