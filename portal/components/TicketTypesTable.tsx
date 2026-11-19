import { Status, humanize } from './Status';
import { Empty } from './ui';
import { count, dateTimeIn, money } from '@/lib/format';
import type { TicketType } from '@/lib/types';

/** Read-only ticket type table for staff views. */
export function TicketTypesTable({ types, timeZone }: { types: TicketType[]; timeZone: string }) {
  if (types.length === 0) return <Empty>No ticket types yet.</Empty>;
  return (
    <table className="table table-compact">
      <thead>
        <tr>
          <th>Ticket</th>
          <th className="num">Price</th>
          <th className="num">Sold</th>
          <th className="num">In checkout</th>
          <th className="num">Left</th>
          <th>Sale window</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        {types.map((t) => (
          <tr key={t.id}>
            <td>
              {t.name} <span className="small muted">{humanize(t.kind)}</span>
            </td>
            <td className="num">{money(t.price.amount, t.price.currency)}</td>
            <td className="num">
              {count(t.quantity_sold)} / {count(t.quantity_total)}
            </td>
            <td className="num">{count(t.quantity_reserved)}</td>
            <td className="num">{count(t.available)}</td>
            <td className="small">
              {t.sale_starts_at || t.sale_ends_at
                ? `${t.sale_starts_at ? dateTimeIn(t.sale_starts_at, timeZone) : 'now'} → ${t.sale_ends_at ? dateTimeIn(t.sale_ends_at, timeZone) : 'event start'}`
                : 'Until the event'}
            </td>
            <td>
              <Status value={t.status === 'hidden' ? 'hidden' : t.sale_state} />
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
