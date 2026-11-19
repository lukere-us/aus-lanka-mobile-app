import { revalidatePath } from 'next/cache';
import { ApiError } from './api';

/** What a form's server action reports back to the form. */
export interface ActionResult {
  ok?: string;
  error?: string;
  details?: Record<string, string[]> | null;
  /** Bumped on every result so the form can tell a new result from the previous one. */
  at?: number;
}

/**
 * Runs an API change for a form: turns API errors into a message on the form, and on success refreshes the
 * page data. Next.js redirects (for example to sign-in on an expired session) pass straight through.
 */
export async function attempt(fn: () => Promise<unknown>, ok: string): Promise<ActionResult> {
  try {
    await fn();
  } catch (e) {
    if (e instanceof ApiError) return { error: e.message, details: e.details, at: Date.now() };
    throw e;
  }
  revalidatePath('/', 'layout');
  return { ok, at: Date.now() };
}

/** Trimmed text, or undefined when empty. */
export function text(form: FormData, key: string): string | undefined {
  const v = form.get(key);
  if (typeof v !== 'string') return undefined;
  const t = v.trim();
  return t === '' ? undefined : t;
}

/** Text that may be cleared: empty becomes null (so the API removes the value). */
export function nullableText(form: FormData, key: string): string | null {
  return text(form, key) ?? null;
}

export function int(form: FormData, key: string): number | undefined {
  const t = text(form, key);
  if (t === undefined) return undefined;
  const n = Number(t);
  return Number.isFinite(n) ? Math.trunc(n) : undefined;
}

export function checked(form: FormData, key: string): boolean {
  return form.get(key) === 'on' || form.get(key) === 'true' || form.get(key) === '1';
}

/** Comma-separated list ("Drama, Comedy") → ["Drama", "Comedy"]. */
export function list(form: FormData, key: string): string[] {
  return (text(form, key) ?? '')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);
}

/** Drops undefined values so PATCH requests only send what the form has. */
export function compact<T extends Record<string, unknown>>(o: T): Partial<T> {
  return Object.fromEntries(Object.entries(o).filter(([, v]) => v !== undefined)) as Partial<T>;
}

/** Required path id from a hidden input; refuses anything that isn't a positive whole number. */
export function idFrom(form: FormData, key = 'id'): number {
  const n = Number(form.get(key));
  if (!Number.isInteger(n) || n <= 0) throw new Error(`Missing ${key}`);
  return n;
}
