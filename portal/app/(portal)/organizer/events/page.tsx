import type { Metadata } from 'next';
import Link from 'next/link';
import { Pager, pageParam } from '@/components/Pager';
import { Status } from '@/components/Status';
import { Empty, Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { count, dateTimeIn } from '@/lib/format';
import { can, requireUser } from '@/lib/session';
import type { EventItem, Paginated } from '@/lib/types';
import { OrganizerError } from '../shared';

export const metadata: Metadata = { title: 'My events' };

export default async function MyEventsPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const me = await requireUser();
  if (!can(me, 'organizer.events.manage')) return <Forbidden />;

  const page = pageParam((await searchParams).page);
  const [result, error] = await load(api<Paginated<EventItem>>('organizer/events', { query: { page } }));
  if (error) return <OrganizerError title="My events" error={error} />;

  return (
    <>
      <PageHeader
        title="My events"
        subtitle="Create an event, add ticket types, then send it for approval. Times are in each venue’s timezone."
        actions={
          <>
            <Link className="btn btn-sm" href="/organizer/venues">
              Venues
            </Link>
            <Link className="btn btn-sm btn-primary" href="/organizer/events/new">
              New event
            </Link>
          </>
        }
      />
      <div className="card card-flush">
        {result.data.length === 0 ? (
          <Empty>
            No events yet. <Link href="/organizer/events/new">Create your first event</Link>.
          </Empty>
        ) : (
          <table className="table">
            <thead>
              <tr>
                <th>Event</th>
                <th>Starts</th>
                <th>Venue</th>
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
                      <Link href={`/organizer/events/${e.id}`}>{e.title}</Link>
                      {e.review_note && e.status === 'draft' && <div className="small text-danger">Sent back: {e.review_note}</div>}
                    </td>
                    <td className="nowrap">{dateTimeIn(e.starts_at, e.venue?.timezone ?? 'Australia/Sydney')}</td>
                    <td>{e.venue?.name ?? '—'}</td>
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
        <Pager path="/organizer/events" params={{}} page={result.meta.current_page} lastPage={result.meta.last_page} total={result.meta.total} />
      </div>
    </>
  );
}
