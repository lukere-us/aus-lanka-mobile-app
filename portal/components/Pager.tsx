import Link from 'next/link';
import { count } from '@/lib/format';

/** "← Previous · page 2 of 5 · Next →" with links that keep the current filters. */
export function Pager({
  path,
  params,
  page,
  lastPage,
  total,
}: {
  path: string;
  params: Record<string, string | undefined>;
  page: number;
  lastPage: number;
  total: number;
}) {
  const href = (p: number) => {
    const q = new URLSearchParams();
    for (const [k, v] of Object.entries(params)) if (v && k !== 'page') q.set(k, v);
    if (p > 1) q.set('page', String(p));
    const s = q.toString();
    return s ? `${path}?${s}` : path;
  };

  return (
    <div className="pager">
      <span className="small muted">
        {count(total)} {total === 1 ? 'result' : 'results'}
        {lastPage > 1 && ` · page ${page} of ${lastPage}`}
      </span>
      <span className="pager-links">
        {page > 1 && <Link href={href(page - 1)}>← Previous</Link>}
        {page < lastPage && <Link href={href(page + 1)}>Next →</Link>}
      </span>
    </div>
  );
}

/** Reads ?page= safely. */
export function pageParam(value: string | undefined): number {
  const n = Number.parseInt(value ?? '1', 10);
  return Number.isFinite(n) && n > 0 ? n : 1;
}
