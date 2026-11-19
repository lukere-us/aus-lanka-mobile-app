import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { ActionForm } from '@/components/ActionForm';
import { Ledger } from '@/components/Ledger';
import { ApiErrorPanel, Card, Forbidden, PageHeader, Stat } from '@/components/ui';
import { api, load } from '@/lib/api';
import { money, percent } from '@/lib/format';
import { can, requireUser } from '@/lib/session';
import type { Balance, LedgerEntry, Listed } from '@/lib/types';
import { recordPayout, setCommission } from '../actions';

export const metadata: Metadata = { title: 'Organizer balance' };

export default async function OrganizerPayoutsPage({ params }: { params: Promise<{ organizerId: string }> }) {
  const me = await requireUser();
  if (!can(me, 'payouts.manage')) return <Forbidden />;

  const id = Number((await params).organizerId);
  if (!Number.isInteger(id) || id <= 0) notFound();
  const [[balance, error], [ledger]] = await Promise.all([
    load(api<{ data: Balance }>(`admin/organizers/${id}/balance`)),
    load(api<Listed<LedgerEntry>>(`admin/organizers/${id}/ledger`)),
  ]);
  if (error?.status === 404) notFound();
  const b = balance?.data;

  return (
    <>
      <PageHeader title="Organizer balance" subtitle={<Link href="/payouts">← All organizers</Link>} />
      {error && <ApiErrorPanel error={error} />}
      {b && (
        <div className="stack-lg">
          <div className="stats-grid">
            <Stat label="Payable now" value={money(b.available, b.currency)} />
            <Stat label="On hold" value={money(b.pending, b.currency)} hint="Events not started yet, or inside the hold period" />
            <Stat label="Paid out so far" value={money(b.paid_out, b.currency)} />
            <Stat label="Commission" value={percent(b.commission_bps)} />
          </div>

          <div className="grid-2">
            <Card title="Record a payout">
              <ActionForm action={recordPayout} submitLabel="Record payout" confirm="Record this payout? Make sure the bank transfer has been made.">
                <input type="hidden" name="id" value={id} />
                <p className="small">
                  Make the bank transfer first, then record it here. It can’t be more than the payable amount ({money(b.available, b.currency)}).
                </p>
                <label className="field">
                  <span>Amount ({b.currency})</span>
                  <input name="amount" inputMode="decimal" pattern="\d+(\.\d{1,2})?" required />
                </label>
                <label className="field">
                  <span>Bank transfer reference</span>
                  <input name="reference" required maxLength={120} />
                </label>
                <label className="field">
                  <span>Note (optional)</span>
                  <input name="note" maxLength={500} />
                </label>
              </ActionForm>
            </Card>
            <Card title="Commission for this organizer">
              <ActionForm action={setCommission} submitLabel="Save commission">
                <input type="hidden" name="id" value={id} />
                <p className="small">Applies to new ticket sales only. Leave empty to use the platform setting.</p>
                <label className="field">
                  <span>Commission (%)</span>
                  <input name="percent" inputMode="decimal" defaultValue={b.commission_bps / 100} />
                </label>
              </ActionForm>
            </Card>
          </div>

          <Card title="Ledger">
            <Ledger entries={ledger?.data ?? []} />
            {ledger && ledger.meta.total > ledger.data.length && (
              <p className="small muted">Showing the latest {ledger.data.length} of {ledger.meta.total} entries.</p>
            )}
          </Card>
        </div>
      )}
    </>
  );
}
