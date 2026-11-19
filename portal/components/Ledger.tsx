import { humanize } from './Status';
import { Empty } from './ui';
import { dateTime, money } from '@/lib/format';
import type { LedgerEntry } from '@/lib/types';

/** Organizer ledger: sales in, commission/refunds/payouts out. */
export function Ledger({ entries }: { entries: LedgerEntry[] }) {
  if (entries.length === 0) return <Empty>No entries yet.</Empty>;
  return (
    <table className="table table-compact">
      <thead>
        <tr>
          <th>When</th>
          <th>Type</th>
          <th>Description</th>
          <th className="num">Amount</th>
        </tr>
      </thead>
      <tbody>
        {entries.map((e) => (
          <tr key={e.id}>
            <td className="nowrap">{dateTime(e.created_at)}</td>
            <td>{humanize(e.type)}</td>
            <td>{e.description}</td>
            <td className={'num' + (Number(e.amount.amount) < 0 ? ' text-danger' : '')}>{money(e.amount.amount, e.amount.currency)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
