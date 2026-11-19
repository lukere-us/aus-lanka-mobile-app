import Link from 'next/link';
import { addDays, todayLocal } from '@/lib/format';

/** Date range form (plain GET, works without JavaScript) plus quick presets. Days are Sydney local days. */
export function PeriodPicker({ path, from, to, extra }: { path: string; from: string; to: string; extra?: Record<string, string> }) {
  const today = todayLocal();
  const presets = [
    { label: 'Last 7 days', from: addDays(today, -6), to: today },
    { label: 'Last 30 days', from: addDays(today, -29), to: today },
    { label: 'Last 90 days', from: addDays(today, -89), to: today },
    { label: 'This month', from: today.slice(0, 8) + '01', to: today },
  ];
  const href = (f: string, t: string) => `${path}?${new URLSearchParams({ ...extra, from: f, to: t })}`;

  return (
    <div className="period">
      <form method="get" action={path} className="period-form">
        {Object.entries(extra ?? {}).map(([k, v]) => (
          <input key={k} type="hidden" name={k} value={v} />
        ))}
        <label className="field field-inline">
          <span>From</span>
          <input type="date" name="from" defaultValue={from} max={today} />
        </label>
        <label className="field field-inline">
          <span>To</span>
          <input type="date" name="to" defaultValue={to} max={today} />
        </label>
        <button className="btn btn-sm">Apply</button>
      </form>
      <div className="period-presets">
        {presets.map((p) => (
          <Link key={p.label} href={href(p.from, p.to)} className={'chip' + (p.from === from && p.to === to ? ' chip-active' : '')}>
            {p.label}
          </Link>
        ))}
      </div>
    </div>
  );
}
