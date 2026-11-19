import type { Metadata } from 'next';
import Link from 'next/link';
import { Pager, pageParam } from '@/components/Pager';
import { Status, humanize } from '@/components/Status';
import { Tabs } from '@/components/Tabs';
import { ApiErrorPanel, Empty, Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { count, dateTimeIn } from '@/lib/format';
import { can, requireUser } from '@/lib/session';
import type { EventItem, Paginated } from '@/lib/types';

export const metadata: Metadata = { title: 'Events' };

const STATUSES = ['pending_review', 'published', 'draft', 'cancelled', 'completed'];

export default async function EventsPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const me = await requireUser();
  if (!can(me, 'events.approve') && !can(me, 'events.manage')) return <Forbidden />;

  const params = await searchParams;
  const status = params.status === 'all' ? 'all' : STATUSES.includes(params.status ?? '') ? params.status! : 'pending_review';
  const search = params.search?.trim() || undefined;
  const page = pageParam(params.page);
  const [result, error] = await load(
    api<Paginated<EventItem>>('admin/events', { query: { status: status === 'all' ? undefined : status, search, page } }),
  );

  return (
    <>
      <PageHeader title="Events" subtitle="Organizers submit events for approval. Times are shown in each venue’s timezone." />
      <Tabs
        path="/events"
        current={status}
        keep={{ search }}
        options={[
          { value: 'pending_review', label: 'Waiting for approval' },
          { value: 'published', label: 'Published' },
          { value: 'draft', label: 'Drafts' },
          { value: 'cancelled', label: 'Cancelled' },
          { value: 'completed', label: 'Completed' },
          { value: 'all', label: 'All' },
        ]}
      />
      <form method="get" className="filters">
        <input type="hidden" name="status" value={status} />
        <label className="field">
          <span>Search by title</span>
          <input name="search" defaultValue={search} />
        </label>
        <div className="filters-actions">
          <button className="btn btn-sm btn-primary">Search</button>
        </div>
      </form>

      {error && <ApiErrorPanel error={error} />}
      {result && (
        <div className="card card-flush">
          {result.data.length === 0 ? (
            <Empty>No events here.</Empty>
          ) : (
            <table className="table">
              <thead>
                <tr>
                  <th>Event</th>
                  <th>Starts</th>
                  <th>Venue</th>
                  <th>Organizer</th>
                  <th className="num">Sold</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {result.data.map((e) => {
                  const sold = e.ticket_types.reduce((n, t) => n + (t.quantity_sold ?? 0), 0);
                  const total = e.ticket_types.reduce((n, t) => n + (t.quantity_total ?? 0), 0);
                  return (
                    <tr key={e.id}>
                      <td>
                        <Link href={`/events/${e.id}`}>{e.title}</Link>
                        <div className="small muted">{humanize(e.category)}</div>
                      </td>
                      <td className="nowrap">{dateTimeIn(e.starts_at, e.venue?.timezone ?? 'Australia/Sydney')}</td>
                      <td>{e.venue ? `${e.venue.name}, ${e.venue.city}` : '—'}</td>
                      <td>{e.organizer?.name ?? '—'}</td>
                      <td className="num">
                        {count(sold)} / {count(total)}
                      </td>
                      <td>
                        <Status value={e.status} />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
          <Pager path="/events" params={{ status, search }} page={result.meta.current_page} lastPage={result.meta.last_page} total={result.meta.total} />
        </div>
      )}
    </>
  );
}
