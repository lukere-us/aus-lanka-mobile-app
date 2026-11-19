import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { API_BASE_URL, SESSION_COOKIE } from './config';

/** The API's error envelope: {error:{code,message,details},request_id}. */
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly details: Record<string, string[]> | null = null,
    public readonly requestId: string | null = null,
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** First message for one field of a validation error, if any. */
  field(name: string): string | undefined {
    return this.details?.[name]?.[0];
  }
}

type Query = Record<string, string | number | undefined | null>;

export function buildUrl(path: string, query?: Query): string {
  const url = new URL(API_BASE_URL + '/' + path.replace(/^\/+/, ''));
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value));
  }
  return url.toString();
}

export async function sessionToken(): Promise<string | null> {
  return (await cookies()).get(SESSION_COOKIE)?.value ?? null;
}

async function toApiError(res: Response): Promise<ApiError> {
  let body: { error?: { code?: string; message?: string; details?: Record<string, string[]> }; request_id?: string } = {};
  try {
    body = await res.json();
  } catch {
    // not JSON (for example a PHP fatal error page); fall through to a generic message
  }
  return new ApiError(
    res.status,
    body.error?.code ?? 'http_' + res.status,
    body.error?.message ?? `The server answered ${res.status}.`,
    body.error?.details ?? null,
    body.request_id ?? res.headers.get('x-request-id'),
  );
}

interface FetchOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  query?: Query;
  body?: unknown;
  /** Send without the signed-in user's token (sign-in itself). */
  anonymous?: boolean;
  /** Token to use instead of the cookie (right after sign-in, before the cookie exists). */
  token?: string;
}

/**
 * For pages: returns [data, null] or [null, error] so an API refusal can be shown on the page
 * instead of failing it. Redirects (expired session) still pass through.
 */
export async function load<T>(promise: Promise<T>): Promise<[T, null] | [null, ApiError]> {
  try {
    return [await promise, null];
  } catch (e) {
    if (e instanceof ApiError) return [null, e];
    throw e;
  }
}

/**
 * Calls the Laravel API from the server with the signed-in user's token.
 * A 401 (token missing, expired or revoked) sends the user back to the sign-in page.
 */
export async function api<T>(path: string, opts: FetchOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  const token = opts.anonymous ? null : (opts.token ?? (await sessionToken()));
  if (!opts.anonymous && !token) redirect('/login');
  if (token) headers.Authorization = `Bearer ${token}`;
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';

  let res: Response;
  try {
    res = await fetch(buildUrl(path, opts.query), {
      method: opts.method ?? 'GET',
      headers,
      body: opts.body === undefined ? undefined : JSON.stringify(opts.body),
      cache: 'no-store',
    });
  } catch {
    throw new ApiError(503, 'api_unreachable', `Cannot reach the API at ${API_BASE_URL}. Is the Laravel server running?`);
  }

  if (res.status === 401 && !opts.anonymous) redirect('/login?expired=1');
  if (!res.ok) throw await toApiError(res);
  if (res.status === 204) return undefined as T;
  return (await res.json()) as T;
}
