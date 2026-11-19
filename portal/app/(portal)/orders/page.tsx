import type { Metadata } from 'next';
import Link from 'next/link';
import { Pager, pageParam } from '@/components/Pager';
import { Status } from '@/components/Status';
import { ApiErrorPanel, Empty, Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { dateTime, isYmd, money } from '@/lib/format';
import { can, requireUser } from '@/lib/session';
import type { Order, Paginated } from '@/lib/types';

export const metadata: Metadata = { title: 'Orders & payments' };

const STATUSES = ['pending_payment', 'paid', 'failed', 'expired', 'cancelled', 'partially_refunded', 'refunded'];

export default async function OrdersPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const me = await requireUser();
  if (!can(me, 'orders.view')) return <Forbidden />;

  const params = await searchParams;
  const page = pageParam(params.page);
  const filters = {
    reference: params.reference?.trim().toUpperCase() || undefined,
    email: params.email?.trim() || undefined,
    status: STATUSES.includes(params.status ?? '') ? params.status : undefined,
    from: isYmd(params.from) ? params.from : undefined,
    to: isYmd(params.to) ? params.to : undefined,
  };
  // The API compares created_at with these dates; include the whole "to" day.
  const query = { ...filters, to: filters.to ? `${filters.to} 23:59:59` : undefined, page };
  const [result, error] = await load(api<Paginated<Order>>('admin/orders', { query }));

  return (
    <>
      <PageHeader title="Orders & payments" subtitle="Find an order by reference or customer email. Newest first." />
      <form method="get" className="filters">
        <label className="field">
          <span>Order reference</span>
          <input name="reference" defaultValue={filters.reference} placeholder="EM-…" />
        </label>
        <label className="field">
          <span>Customer email (exact)</span>
          <input name="email" type="email" defaultValue={filters.email} />
        </label>
        <label className="field">
          <span>Status</span>
          <select name="status" defaultValue={filters.status ?? ''}>
            <option value="">Any</option>
            {STATUSES.map((s) => (
              <option key={s} value={s}>
                {s.replace(/_/g, ' ')}
              </option>
            ))}
          </select>
        </label>
        <label className="field">
          <span>Created from</span>
          <input type="date" name="from" defaultValue={filters.from} />
        </label>
        <label className="field">
          <span>to</span>
          <input type="date" name="to" defaultValue={filters.to} />
        </label>
        <div className="filters-actions">
          <button className="btn btn-sm btn-primary">Search</button>
          <Link className="btn btn-sm btn-ghost" href="/orders">
            Clear
          </Link>
        </div>
      </form>
      <p className="small muted">Dates here are in UTC.</p>

      {error && <ApiErrorPanel error={error} />}
      {result && (
        <div className="card card-flush">
          {result.data.length === 0 ? (
            <Empty>No orders match.</Empty>
          ) : (
            <table className="table">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Created</th>
                  <th>Customer</th>
                  <th>Items</th>
                  <th className="num">Total</th>
                  <th>Order</th>
                  <th>Payment</th>
                </tr>
              </thead>
              <tbody>
                {result.data.map((o) => (
                  <tr key={o.reference}>
                    <td className="nowrap">
                      <Link href={`/orders/${o.reference}`}>{o.reference}</Link>
                    </td>
                    <td className="nowrap">{dateTime(o.created_at)}</td>
                    <td>{o.customer?.email ?? '—'}</td>
                    <td>{o.items.map((i) => `${i.quantity} × ${i.name}`).join('; ')}</td>
                    <td className="num">
                      {money(o.total.amount, o.total.currency)}
                      {o.exchange_rate && <div className="small muted">{money(o.exchange_rate.base_total.amount, o.exchange_rate.base_currency)}</div>}
                    </td>
                    <td>
                      <Status value={o.status} />
                    </td>
                    <td>
                      <Status value={o.payment?.status} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <Pager path="/orders" params={filters} page={result.meta.current_page} lastPage={result.meta.last_page} total={result.meta.total} />
        </div>
      )}
    </>
  );
}
