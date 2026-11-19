import type { Metadata } from 'next';
import Link from 'next/link';
import { ActionForm } from '@/components/ActionForm';
import { Pager, pageParam } from '@/components/Pager';
import { Status } from '@/components/Status';
import { Tabs } from '@/components/Tabs';
import { ApiErrorPanel, Empty, Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { dateTime, money } from '@/lib/format';
import { can, requireUser } from '@/lib/session';
import type { Listed, Refund } from '@/lib/types';
import { approveRefund, rejectRefund } from './actions';

export const metadata: Metadata = { title: 'Refunds' };

const PER_PAGE = 25;

export default async function RefundsPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const me = await requireUser();
  if (!can(me, 'refunds.approve')) return <Forbidden />;

  const params = await searchParams;
  const status = params.status ?? 'requested';
  const page = pageParam(params.page);
  const [result, error] = await load(api<Listed<Refund>>('admin/refunds', { query: { status: status === 'all' ? undefined : status, page } }));

  return (
    <>
      <PageHeader title="Refunds" subtitle="Customer refund requests wait here for a decision. Approving sends the money back through the payment provider." />
      <Tabs
        path="/refunds"
        current={status}
        options={[
          { value: 'requested', label: 'Waiting for decision' },
          { value: 'processing', label: 'Processing' },
          { value: 'failed', label: 'Failed' },
          { value: 'succeeded', label: 'Refunded' },
          { value: 'rejected', label: 'Rejected' },
          { value: 'all', label: 'All' },
        ]}
      />
      {status === 'failed' && (
        <p className="alert alert-info small">
          The payment provider refused these. Check the reason, fix it in the provider’s dashboard if needed, and contact the customer.
        </p>
      )}
      {error && <ApiErrorPanel error={error} />}
      {result && (
        <div className="card card-flush">
          {result.data.length === 0 ? (
            <Empty>Nothing here.</Empty>
          ) : (
            <table className="table">
              <thead>
                <tr>
                  <th>Requested</th>
                  <th>Order</th>
                  <th>What</th>
                  <th className="num">Amount</th>
                  <th>Reason</th>
                  <th>Status</th>
                  {status === 'requested' && <th>Decision</th>}
                </tr>
              </thead>
              <tbody>
                {result.data.map((r) => (
                  <tr key={r.id}>
                    <td className="nowrap">{dateTime(r.created_at)}</td>
                    <td className="nowrap">{r.order_reference ? <Link href={`/orders/${r.order_reference}`}>{r.order_reference}</Link> : '—'}</td>
                    <td className="small">
                      {[
                        r.ticket_ids?.length ? `${r.ticket_ids.length} ticket${r.ticket_ids.length > 1 ? 's' : ''}` : null,
                        r.rental_ids?.length ? `${r.rental_ids.length} rental${r.rental_ids.length > 1 ? 's' : ''}` : null,
                        r.includes_fees ? 'with fees' : null,
                      ]
                        .filter(Boolean)
                        .join(', ') || '—'}
                    </td>
                    <td className="num">
                      {money(r.amount.amount, r.amount.currency)}
                      {r.amount.currency !== r.base_amount.currency && <div className="small muted">{money(r.base_amount.amount, r.base_amount.currency)}</div>}
                    </td>
                    <td className="small">
                      {r.reason}
                      {r.review_note && <div className="muted">Note: {r.review_note}</div>}
                      {r.failure_reason && <div className="text-danger">Provider: {r.failure_reason}</div>}
                    </td>
                    <td>
                      <Status value={r.status} />
                      {r.completed_at && <div className="small muted">{dateTime(r.completed_at)}</div>}
                    </td>
                    {status === 'requested' && (
                      <td className="decision">
                        <ActionForm action={approveRefund} submitLabel="Approve" inline confirm={`Refund ${money(r.amount.amount, r.amount.currency)} to the customer?`}>
                          <input type="hidden" name="id" value={r.id} />
                        </ActionForm>
                        <ActionForm action={rejectRefund} submitLabel="Reject" variant="danger" inline>
                          <input type="hidden" name="id" value={r.id} />
                          <input name="note" placeholder="Why (sent to customer)" required maxLength={500} />
                        </ActionForm>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <Pager path="/refunds" params={{ status }} page={result.meta.current_page ?? page} lastPage={Math.max(1, Math.ceil(result.meta.total / PER_PAGE))} total={result.meta.total} />
        </div>
      )}
    </>
  );
}
