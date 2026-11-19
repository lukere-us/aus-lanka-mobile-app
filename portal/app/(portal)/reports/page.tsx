import type { Metadata } from 'next';
import { PeriodPicker } from '@/components/PeriodPicker';
import { Card, Forbidden, PageHeader } from '@/components/ui';
import { addDays, isYmd, todayLocal } from '@/lib/format';
import { can, requireUser } from '@/lib/session';

export const metadata: Metadata = { title: 'CSV downloads' };

const REPORTS = [
  { file: 'orders', title: 'Orders', text: 'Every order created in the period: amounts in the customer’s currency and in AUD, rate used, payment status.' },
  { file: 'refunds', title: 'Refunds', text: 'Refunds requested in the period with status, amounts, reason and review note.' },
  { file: 'payouts', title: 'Payouts', text: 'Payouts recorded to organizers in the period, with bank reference.' },
  { file: 'ticket-sales', title: 'Ticket sales', text: 'Stock and sales for each ticket type of events starting in the period.' },
  { file: 'rentals', title: 'Rentals', text: 'Rentals bought in the period with movie, package, price and status.' },
];

export default async function ReportsPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const user = await requireUser();
  if (!can(user, 'reports.view')) return <Forbidden />;

  const params = await searchParams;
  const to = isYmd(params.to) ? params.to : todayLocal();
  const from = isYmd(params.from) ? params.from : addDays(to, -29);
  const period = new URLSearchParams({ from, to }).toString();

  return (
    <>
      <PageHeader title="CSV downloads" subtitle="Files open in Excel. Times are in UTC; amounts show their currency." />
      <PeriodPicker path="/reports" from={from} to={to} />

      <div className="report-grid">
        {REPORTS.map((r) => (
          <Card key={r.file} title={r.title}>
            <p className="small">{r.text}</p>
            <a className="btn btn-sm" href={`/download/admin/reports/${r.file}.csv?${period}`} download>
              Download CSV
            </a>
          </Card>
        ))}
        <Card title="Organizer balances">
          <p className="small">Each organizer’s balance right now: available, pending and paid out. Ignores the period.</p>
          <a className="btn btn-sm" href="/download/admin/reports/organizer-balances.csv" download>
            Download CSV
          </a>
        </Card>
      </div>
    </>
  );
}
