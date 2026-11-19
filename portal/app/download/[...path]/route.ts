import { NextResponse, type NextRequest } from 'next/server';
import { buildUrl } from '@/lib/api';
import { SESSION_COOKIE } from '@/lib/config';

/**
 * CSV downloads. The browser has no API token (it lives in an httpOnly cookie), so links point here and this
 * handler fetches the file from the API with the token and streams it back. Only CSV report paths are allowed,
 * so this can't be used as a general proxy to the API.
 */
const ALLOWED = [/^admin\/reports\/[a-z-]+\.csv$/, /^admin\/audit-logs$/, /^organizer\/events\/\d+\/attendees\.csv$/];

export async function GET(request: NextRequest, { params }: { params: Promise<{ path: string[] }> }) {
  const path = (await params).path.join('/');
  if (!ALLOWED.some((re) => re.test(path))) return new NextResponse('Not found', { status: 404 });

  const token = request.cookies.get(SESSION_COOKIE)?.value;
  if (!token) return NextResponse.redirect(new URL('/login', request.url));

  const query: Record<string, string> = {};
  request.nextUrl.searchParams.forEach((value, key) => (query[key] = value));
  if (path === 'admin/audit-logs') query.format = 'csv';

  let res: Response;
  try {
    res = await fetch(buildUrl(path, query), {
      headers: { Authorization: `Bearer ${token}`, Accept: 'text/csv, application/json' },
      cache: 'no-store',
    });
  } catch {
    return new NextResponse('The API is not reachable. Is the Laravel server running?', { status: 503 });
  }

  if (res.status === 401) return NextResponse.redirect(new URL('/login?expired=1', request.url));
  if (!res.ok) {
    let message = `Download failed (${res.status}).`;
    try {
      const body = await res.json();
      message = body?.error?.message ?? message;
    } catch {
      // keep the generic message
    }
    return new NextResponse(message, { status: res.status, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
  }

  const headers = new Headers({ 'Cache-Control': 'no-store' });
  for (const name of ['content-type', 'content-disposition']) {
    const value = res.headers.get(name);
    if (value) headers.set(name, value);
  }
  return new NextResponse(res.body, { status: 200, headers });
}
