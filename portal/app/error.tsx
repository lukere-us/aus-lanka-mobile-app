'use client';

/** Last-resort error screen, also used when the signed-in check itself fails (for example the API is down). */
export default function RootError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <main className="auth-page"><div className="auth-card card stack">
      <h1>Something went wrong</h1>
      <p>{error.message || 'The page could not be loaded.'}</p>
      {error.digest && <p className="small muted">Reference: {error.digest}</p>}
      <div>
        <button className="btn" onClick={reset}>
          Try again
        </button>
      </div>
    </div></main>
  );
}
