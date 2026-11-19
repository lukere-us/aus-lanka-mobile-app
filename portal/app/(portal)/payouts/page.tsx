import type { Metadata } from 'next';
import Link from 'next/link';
import { Pager, pageParam } from '@/components/Pager';
import { ApiErrorPanel, Card, Empty, Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { dateTime, money, percent } from '@/lib/format';
import { can, requireUser } from '@/lib/session';
import type { Balance, Listed, Organizer, Paginated, Payout } from '@/lib/types';

export const metadata: Metadata = { title: 'Payouts' };

export default async function PayoutsPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const me = await requireUser();
  if (!can(me, 'payouts.manage')) return <Forbidden />;

  const params = await searchParams;
  const page = pageParam(params.page);
  const [orgs, orgError] = await load(api<Paginated<Organizer>>('admin/organizers', { query: { status: 'approved', page } }));
  const balances = orgs
    ? await Promise.all(orgs.data.map((o) => load(api<{ data: Balance }>(`admin/organizers/${o.id}/balance`)).then(([b]) => b?.data ?? null)))
    : [];
  const [payouts, payoutError] = await load(api<Listed<Payout>>('admin/payouts'));

  return (
    <>
      <PageHeader
        title="Payouts"
        subtitle="What each organizer has earned from ticket sales after commission and refunds. Earnings become payable after the hold period once the event has started."
      />
      {orgError && <ApiErrorPanel error={orgError} />}
      {orgs && (
        <div className="card card-flush">
          {orgs.data.length === 0 ? (
            <Empty>No approved organizers yet.</Empty>
          ) : (
            <table className="table">
              <thead>
                <tr>
                  <th>Organizer</th>
                  <th className="num">Commission</th>
                  <th className="num">Payable now</th>
                  <th className="num">On hold</th>
                  <th className="num">Paid out</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {orgs.data.map((o, i) => {
                  const b = balances[i];
                  return (
                    <tr key={o.id}>
                      <td>{o.name}</td>
                      <td className="num">{b ? percent(b.commission_bps) : '—'}</td>
                      <td className="num">
                        <strong>{b ? money(b.available, b.currency) : '—'}</strong>
                      </td>
                      <td className="num">{b ? money(b.pending, b.currency) : '—'}</td>
                      <td className="num">{b ? money(b.paid_out, b.currency) : '—'}</td>
                      <td className="num">
                        <Link href={`/payouts/${o.id}`}>Open</Link>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
          <Pager path="/payouts" params={{}} page={orgs.meta.current_page} lastPage={orgs.meta.last_page} total={orgs.meta.total} />
        </div>
      )}

      <Card title="Recent payouts" className="mt">
        {payoutError && <ApiErrorPanel error={payoutError} />}
        {payouts && payouts.data.length === 0 && <Empty>No payouts recorded yet.</Empty>}
        {payouts && payouts.data.length > 0 && (
          <table className="table table-compact">
            <thead>
              <tr>
                <th>Recorded</th>
                <th>Organizer</th>
                <th>Bank reference</th>
                <th className="num">Amount</th>
              </tr>
            </thead>
            <tbody>
              {payouts.data.map((p) => (
                <tr key={p.id}>
                  <td className="nowrap">{dateTime(p.created_at)}</td>
                  <td>
                    <Link href={`/payouts/${p.organizer_id}`}>{p.organizer_name ?? `Organizer #${p.organizer_id}`}</Link>
                  </td>
                  <td>{p.reference}</td>
                  <td className="num">{money(p.amount, p.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
    </>
  );
}
