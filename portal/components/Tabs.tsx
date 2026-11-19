import Link from 'next/link';

/** Filter tabs as links (?status=…), e.g. All · Pending · Approved. */
export function Tabs({
  path,
  param = 'status',
  current,
  options,
  keep,
}: {
  path: string;
  param?: string;
  current: string | undefined;
  options: { value: string | undefined; label: string }[];
  keep?: Record<string, string | undefined>;
}) {
  return (
    <nav className="tabs" aria-label="Filter">
      {options.map((o) => {
        const q = new URLSearchParams();
        for (const [k, v] of Object.entries(keep ?? {})) if (v) q.set(k, v);
        if (o.value) q.set(param, o.value);
        const s = q.toString();
        const active = (current ?? '') === (o.value ?? '');
        return (
          <Link key={o.label} href={s ? `${path}?${s}` : path} className={'tab' + (active ? ' tab-active' : '')} aria-current={active ? 'page' : undefined}>
            {o.label}
          </Link>
        );
      })}
    </nav>
  );
}
