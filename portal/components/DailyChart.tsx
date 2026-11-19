import { money, shortDay } from '@/lib/format';

export interface DailyPoint {
  date: string;
  orders: number;
  gross: string;
}

/** Bar chart of daily gross sales, drawn on the server as SVG (no chart library). */
export function DailyChart({ points, currency }: { points: DailyPoint[]; currency: string }) {
  const values = points.map((p) => Number(p.gross));
  const max = Math.max(...values, 0);
  if (points.length === 0 || max === 0) return <p className="empty">No paid orders in this period.</p>;

  const width = 720;
  const height = 200;
  const pad = { top: 12, right: 8, bottom: 24, left: 8 };
  const innerH = height - pad.top - pad.bottom;
  const slot = (width - pad.left - pad.right) / points.length;
  const bar = Math.max(1, Math.min(28, slot * 0.7));
  const labelEvery = Math.ceil(points.length / 8);

  return (
    <figure className="chart">
      <svg viewBox={`0 0 ${width} ${height}`} role="img" aria-label="Daily gross sales">
        <line x1={pad.left} x2={width - pad.right} y1={height - pad.bottom} y2={height - pad.bottom} className="chart-axis" />
        {points.map((p, i) => {
          const v = values[i];
          const h = v === 0 ? 0 : Math.max(2, (v / max) * innerH);
          const x = pad.left + i * slot + (slot - bar) / 2;
          return (
            <g key={p.date}>
              <rect x={x} y={height - pad.bottom - h} width={bar} height={h} rx={Math.min(3, bar / 3)} className="chart-bar">
                <title>{`${shortDay(p.date)}: ${money(p.gross, currency)} from ${p.orders} order${p.orders === 1 ? '' : 's'}`}</title>
              </rect>
              {i % labelEvery === 0 && (
                <text x={x + bar / 2} y={height - 6} textAnchor="middle" className="chart-label">
                  {shortDay(p.date)}
                </text>
              )}
            </g>
          );
        })}
      </svg>
      <figcaption className="small muted">Highest day: {money(max, currency)}. Hover a bar for the figures.</figcaption>
    </figure>
  );
}
