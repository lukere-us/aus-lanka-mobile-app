import type { Metadata } from 'next';
import Link from 'next/link';
import { ActionForm } from '@/components/ActionForm';
import { Pager, pageParam } from '@/components/Pager';
import { Status } from '@/components/Status';
import { Tabs } from '@/components/Tabs';
import { ApiErrorPanel, Empty, Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { dateTime } from '@/lib/format';
import { can, requireUser } from '@/lib/session';
import type { Organizer, Paginated } from '@/lib/types';
import { decideOrganizer } from './actions';

export const metadata: Metadata = { title: 'Organizers' };

export default async function OrganizersPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const me = await requireUser();
  if (!can(me, 'organizers.approve')) return <Forbidden />;

  const params = await searchParams;
  const status = params.status ?? 'pending';
  const search = params.search?.trim() || undefined;
  const page = pageParam(params.page);
  const [result, error] = await load(
    api<Paginated<Organizer>>('admin/organizers', { query: { status: status === 'all' ? undefined : status, search, page } }),
  );

  return (
    <>
      <PageHeader title="Organizers" subtitle="Approve applications before organizers can create events." />
      <Tabs
        path="/organizers"
        current={status}
        keep={{ search }}
        options={[
          { value: 'pending', label: 'Waiting for approval' },
          { value: 'approved', label: 'Approved' },
          { value: 'suspended', label: 'Suspended' },
          { value: 'rejected', label: 'Rejected' },
          { value: 'all', label: 'All' },
        ]}
      />
      <form method="get" className="filters">
        <input type="hidden" name="status" value={status} />
        <label className="field">
          <span>Search by name</span>
          <input name="search" defaultValue={search} />
        </label>
        <div className="filters-actions">
          <button className="btn btn-sm btn-primary">Search</button>
        </div>
      </form>

      {error && <ApiErrorPanel error={error} />}
      {result && (
        <div className="stack">
          {result.data.length === 0 && (
            <div className="card">
              <Empty>No organizers here.</Empty>
            </div>
          )}
          {result.data.map((o) => (
            <div key={o.id} className="card">
              <div className="row-between">
                <div>
                  <h2 className="h-inline">{o.name}</h2> <Status value={o.status} />
                  <div className="small muted">
                    {o.contact_email}
                    {o.contact_phone && ` · ${o.contact_phone}`}
                    {o.business_registration_no && ` · ABN/reg. ${o.business_registration_no}`} · applied {dateTime(o.created_at)}
                  </div>
                  {o.status_reason && <div className="small">Reason: {o.status_reason}</div>}
                </div>
                {can(me, 'payouts.manage') && o.status !== 'pending' && (
                  <Link className="btn btn-sm" href={`/payouts/${o.id}`}>
                    Balance & payouts
                  </Link>
                )}
              </div>
              <div className="actions-row">
                {o.status !== 'approved' && (
                  <ActionForm action={decideOrganizer} submitLabel={o.status === 'pending' ? 'Approve' : 'Re-approve'} inline>
                    <input type="hidden" name="id" value={o.id} />
                    <input type="hidden" name="status" value="approved" />
                  </ActionForm>
                )}
                {o.status === 'pending' && (
                  <ActionForm action={decideOrganizer} submitLabel="Reject" variant="danger" inline>
                    <input type="hidden" name="id" value={o.id} />
                    <input type="hidden" name="status" value="rejected" />
                    <input name="reason" placeholder="Reason (sent to the applicant)" required maxLength={500} />
                  </ActionForm>
                )}
                {o.status === 'approved' && (
                  <ActionForm action={decideOrganizer} submitLabel="Suspend" variant="danger" inline confirm={`Suspend ${o.name}? They won't be able to manage events.`}>
                    <input type="hidden" name="id" value={o.id} />
                    <input type="hidden" name="status" value="suspended" />
                    <input name="reason" placeholder="Reason" required maxLength={500} />
                  </ActionForm>
                )}
              </div>
            </div>
          ))}
          <Pager path="/organizers" params={{ status, search }} page={result.meta.current_page} lastPage={result.meta.last_page} total={result.meta.total} />
        </div>
      )}
    </>
  );
}
