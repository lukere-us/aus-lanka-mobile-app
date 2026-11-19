import type { Metadata } from 'next';
import { ActionForm } from '@/components/ActionForm';
import { ApiErrorPanel, Card, Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { dateTime } from '@/lib/format';
import { can, requireUser } from '@/lib/session';
import type { Currency, Setting } from '@/lib/types';
import { saveCurrency, saveSettings } from './actions';

export const metadata: Metadata = { title: 'Settings & currencies' };

const LABELS: Record<string, { label: string; unit: string; percent?: boolean; step?: string }> = {
  ticket_commission_bps: { label: 'Commission on ticket sales', unit: '%', percent: true, step: '0.01' },
  booking_fee_per_ticket: { label: 'Booking fee per ticket', unit: 'AUD', step: '0.01' },
  booking_fee_bps: { label: 'Booking fee as a share of the tickets', unit: '%', percent: true, step: '0.01' },
  ticket_refund_cutoff_hours: { label: 'Ticket refunds close before the event', unit: 'hours' },
  rental_refund_window_hours: { label: 'Unwatched rentals can be refunded for', unit: 'hours after buying' },
  payout_hold_days: { label: 'Hold organizer earnings after the event starts', unit: 'days' },
};

export default async function SettingsPage() {
  const me = await requireUser();
  if (!can(me, 'settings.manage')) return <Forbidden />;

  const [[settings, settingsError], [currencies, currencyError]] = await Promise.all([
    load(api<{ data: Setting[] }>('admin/settings')),
    load(api<{ data: Currency[] }>('admin/currencies')),
  ]);

  return (
    <>
      <PageHeader title="Settings & currencies" subtitle="Changes are recorded in the audit log." />
      <div className="stack-lg">
        <Card title="Fees, refunds and payouts">
          {settingsError && <ApiErrorPanel error={settingsError} />}
          {settings && (
            <ActionForm action={saveSettings} submitLabel="Save settings" confirm="Save these settings? They apply to every new order.">
              <div className="settings-list">
                {settings.data.map((s) => {
                  const meta = LABELS[s.key] ?? { label: s.key, unit: '' };
                  const value = meta.percent ? Number(s.value) / 100 : s.value;
                  return (
                    <label key={s.key} className="setting">
                      <span className="setting-label">
                        {meta.label}
                        <span className="small muted">{s.description}</span>
                      </span>
                      <span className="setting-input">
                        <input name={s.key} type="number" min={0} step={meta.step ?? '1'} defaultValue={value} required />
                        <span className="small muted">{meta.unit}</span>
                      </span>
                    </label>
                  );
                })}
              </div>
            </ActionForm>
          )}
        </Card>

        <Card title="Currencies">
          <p className="small">
            Prices are set in AUD. Customers can pay in any active currency at the rate below, which is locked into each order when it’s
            placed. Update rates regularly.
          </p>
          {currencyError && <ApiErrorPanel error={currencyError} />}
          {currencies && (
            <table className="table">
              <thead>
                <tr>
                  <th>Currency</th>
                  <th>1 AUD =</th>
                  <th>Customers can pay in it</th>
                  <th>Rate updated</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {currencies.data.map((c) => (
                  <tr key={c.code}>
                    <td>
                      <strong>{c.code}</strong> {c.name} <span className="muted">{c.symbol}</span>
                    </td>
                    {c.is_base ? (
                      <>
                        <td>1 (base)</td>
                        <td>Always</td>
                        <td>—</td>
                        <td />
                      </>
                    ) : (
                      <td colSpan={4}>
                        <ActionForm action={saveCurrency} submitLabel="Save" inline variant="default">
                          <input type="hidden" name="code" value={c.code} />
                          <input type="hidden" name="is_active_present" value="1" />
                          <input name="rate_per_base" inputMode="decimal" placeholder="Rate" defaultValue={c.rate_per_base ? Number(c.rate_per_base) : ''} aria-label={`${c.code} per AUD`} />
                          <label className="check">
                            <input type="checkbox" name="is_active" defaultChecked={c.is_active} />
                            <span>Active</span>
                          </label>
                          <span className="small muted">{c.rate_updated_at ? dateTime(c.rate_updated_at) : 'never'}</span>
                        </ActionForm>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <details className="subcard">
            <summary>Add a currency</summary>
            <ActionForm action={saveCurrency} submitLabel="Add currency">
              <div className="form-grid">
                <label className="field">
                  <span>Code</span>
                  <input name="code" required minLength={3} maxLength={3} placeholder="NZD" />
                </label>
                <label className="field">
                  <span>Name</span>
                  <input name="name" required maxLength={60} placeholder="New Zealand Dollar" />
                </label>
                <label className="field">
                  <span>Symbol</span>
                  <input name="symbol" required maxLength={8} placeholder="NZ$" />
                </label>
                <label className="field">
                  <span>1 AUD =</span>
                  <input name="rate_per_base" inputMode="decimal" required />
                </label>
              </div>
              <p className="small muted">New currencies start inactive. Tick “Active” in the table when you’re ready.</p>
            </ActionForm>
          </details>
        </Card>
      </div>
    </>
  );
}
