import type { Metadata } from 'next';
import { Ledger } from '@/components/Ledger';
import { Card, Forbidden, PageHeader, Stat } from '@/components/ui';
import { api, load } from '@/lib/api';
import { money, percent } from '@/lib/format';
import { can, requireUser } from '@/lib/session';
import type { Balance, LedgerEntry, Listed } from '@/lib/types';
import { OrganizerError } from '../shared';

export const metadata: Metadata = { title: 'Earnings' };

export default async function EarningsPage() {
  const me = await requireUser();
  if (!can(me, 'organizer.events.manage')) return <Forbidden />;

  const [[balance, error], [ledger]] = await Promise.all([
    load(api<{ data: Balance }>('organizer/balance')),
    load(api<Listed<LedgerEntry>>('organizer/ledger')),
  ]);
  if (error) return <OrganizerError title="Earnings" error={error} />;
  const b = balance.data;

  return (
    <>
      <PageHeader
        title="Earnings"
        subtitle={`Ticket sales less the ${percent(b.commission_bps)} platform commission and refunds. Earnings become payable a few days after each event starts; the platform pays them to your bank account.`}
      />
      <div className="stack-lg">
        <div className="stats-grid">
          <Stat label="Ready to be paid" value={money(b.available, b.currency)} />
          <Stat label="On hold" value={money(b.pending, b.currency)} hint="Events that haven’t happened yet" />
          <Stat label="Paid to you so far" value={money(b.paid_out, b.currency)} />
        </div>
        <Card title="History">
          <Ledger entries={ledger?.data ?? []} />
        </Card>
      </div>
    </>
  );
}
