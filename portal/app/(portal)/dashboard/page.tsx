import type { Metadata } from 'next';
import Link from 'next/link';
import { DailyChart, type DailyPoint } from '@/components/DailyChart';
import { PeriodPicker } from '@/components/PeriodPicker';
import { ApiErrorPanel, Card, Empty, Forbidden, PageHeader, Stat } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { addDays, count, isYmd, money, todayLocal } from '@/lib/format';
import { can, requireUser } from '@/lib/session';

export const metadata: Metadata = { title: 'Dashboard' };

interface Dashboard {
  period: { from: string; to: string; timezone: string };
  currency: string;
  revenue: {
    gross: string;
    tickets: string;
    rentals: string;
    booking_fees: string;
    refunds: string;
    net: string;
    ticket_commission: string;
    platform_revenue_before_refunds: string;
  };
  counts: {
    paid_orders: number;
    tickets_sold: number;
    rentals_sold: number;
    new_customers: number;
    paying_customers: number;
    tickets_checked_in: number;
    rentals_started: number;
  };
  daily: DailyPoint[];
  top_events: { event_id: number; title: string; tickets: number; revenue: string }[];
  top_movies: { movie_id: number; title: string; rentals: number; revenue: string }[];
  needs_attention: Record<string, number>;
}

const ATTENTION: { key: string; label: string }[] = [
  { key: 'refund_requests', label: 'Refund requests waiting for a decision' },
  { key: 'refunds_failed', label: 'Refunds the payment provider rejected' },
  { key: 'payments_in_review', label: 'Payments held for review (amount mismatch)' },
  { key: 'organizers_pending', label: 'Organizer applications to approve' },
  { key: 'events_pending_review', label: 'Events waiting for approval' },
  { key: 'unfulfilled_ticket_orders', label: 'Paid orders whose tickets could not be issued' },
];

export default async function DashboardPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const user = await requireUser();
  if (!can(user, 'reports.view')) return <Forbidden />;

  const params = await searchParams;
  const to = isYmd(params.to) ? params.to : todayLocal();
  const from = isYmd(params.from) ? params.from : addDays(to, -29);

  let data: Dashboard | null = null;
  let error: ApiError | null = null;
  try {
    data = (await api<{ data: Dashboard }>('admin/dashboard', { query: { from, to } })).data;
  } catch (e) {
    if (!(e instanceof ApiError)) throw e; // keeps Next.js redirects working
    error = e;
  }

  return (
    <>
      <PageHeader
        title="Dashboard"
        subtitle="Amounts in AUD. Days are Sydney time."
        actions={<Link href={`/reports?from=${from}&to=${to}`} className="btn btn-sm">CSV downloads</Link>}
      />
      <PeriodPicker path="/dashboard" from={from} to={to} />

      {error && <ApiErrorPanel error={error} />}

      {data && <DashboardView data={data} />}
    </>
  );
}

function DashboardView({ data }: { data: Dashboard }) {
  return (
    <div className="stack-lg">
      <div className="stats-grid">
        <Stat label="Gross sales" value={money(data.revenue.gross, data.currency)} hint={`${count(data.counts.paid_orders)} paid orders`} />
        <Stat label="Refunded" value={money(data.revenue.refunds, data.currency)} />
        <Stat label="Net sales" value={money(data.revenue.net, data.currency)} />
        <Stat
          label="Platform revenue"
          value={money(data.revenue.platform_revenue_before_refunds, data.currency)}
          hint="Ticket commission + rentals + booking fees, before refunds"
        />
      </div>

      <div className="grid-2">
        <Card title="Sales by type">
          <table className="table table-compact">
            <tbody>
              <tr>
                <th scope="row">Event tickets</th>
                <td className="num">{count(data.counts.tickets_sold)} sold</td>
                <td className="num">{money(data.revenue.tickets, data.currency)}</td>
              </tr>
              <tr>
                <th scope="row">Movie rentals</th>
                <td className="num">{count(data.counts.rentals_sold)} sold</td>
                <td className="num">{money(data.revenue.rentals, data.currency)}</td>
              </tr>
              <tr>
                <th scope="row">Booking fees</th>
                <td />
                <td className="num">{money(data.revenue.booking_fees, data.currency)}</td>
              </tr>
              <tr>
                <th scope="row">Commission on tickets</th>
                <td />
                <td className="num">{money(data.revenue.ticket_commission, data.currency)}</td>
              </tr>
            </tbody>
          </table>
        </Card>
        <Card title="Activity">
          <div className="stats-grid stats-grid-sm">
            <Stat label="New customers" value={count(data.counts.new_customers)} />
            <Stat label="Paying customers" value={count(data.counts.paying_customers)} />
            <Stat label="Tickets checked in" value={count(data.counts.tickets_checked_in)} />
            <Stat label="Rentals started" value={count(data.counts.rentals_started)} />
          </div>
        </Card>
      </div>

      <Card title="Daily gross sales">
        <DailyChart points={data.daily} currency={data.currency} />
      </Card>

      <div className="grid-2">
        <Card title="Top events">
          {data.top_events.length === 0 ? (
            <Empty>No ticket sales in this period.</Empty>
          ) : (
            <table className="table table-compact">
              <thead>
                <tr>
                  <th>Event</th>
                  <th className="num">Tickets</th>
                  <th className="num">Revenue</th>
                </tr>
              </thead>
              <tbody>
                {data.top_events.map((e) => (
                  <tr key={e.event_id}>
                    <td>{e.title}</td>
                    <td className="num">{count(e.tickets)}</td>
                    <td className="num">{money(e.revenue, data.currency)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
        <Card title="Top movies">
          {data.top_movies.length === 0 ? (
            <Empty>No rentals in this period.</Empty>
          ) : (
            <table className="table table-compact">
              <thead>
                <tr>
                  <th>Movie</th>
                  <th className="num">Rentals</th>
                  <th className="num">Revenue</th>
                </tr>
              </thead>
              <tbody>
                {data.top_movies.map((m) => (
                  <tr key={m.movie_id}>
                    <td>{m.title}</td>
                    <td className="num">{count(m.rentals)}</td>
                    <td className="num">{money(m.revenue, data.currency)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      </div>

      <Card title="Needs attention">
        <p className="small muted">Counted right now, whatever period is selected.</p>
        <ul className="attention">
          {ATTENTION.map(({ key, label }) => {
            const n = data.needs_attention[key] ?? 0;
            return (
              <li key={key} className={n > 0 ? 'attention-on' : ''}>
                <span className="attention-count">{count(n)}</span>
                <span>{label}</span>
              </li>
            );
          })}
        </ul>
      </Card>
    </div>
  );
}
