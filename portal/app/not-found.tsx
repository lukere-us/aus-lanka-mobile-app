import Link from 'next/link';

export default function NotFound() {
  return (
    <main className="auth-page">
      <div className="auth-card card stack">
        <h1>Page not found</h1>
        <p>This screen doesn’t exist yet, or the address is wrong.</p>
        <p>
          <Link href="/">Go to home</Link>
        </p>
      </div>
    </main>
  );
}
