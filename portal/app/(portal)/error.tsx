'use client';

/** Shown when a page fails unexpectedly (for example the API is down). */
export default function PortalError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <div className="card stack">
      <h1>Something went wrong</h1>
      <p>{error.message || 'The page could not be loaded.'}</p>
      {error.digest && <p className="small muted">Reference: {error.digest}</p>}
      <div>
        <button className="btn" onClick={reset}>
          Try again
        </button>
      </div>
    </div>
  );
}
