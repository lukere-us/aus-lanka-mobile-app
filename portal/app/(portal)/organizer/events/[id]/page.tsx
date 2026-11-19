import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Status } from '@/components/Status';
import { Tabs } from '@/components/Tabs';
import { Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { dateTimeIn } from '@/lib/format';
import { can, requireUser } from '@/lib/session';
import type { EventItem } from '@/lib/types';
import { OrganizerError } from '../../shared';
import { AttendeesTab, GatesTab, LiveTab, OverviewTab, SalesTab, TicketsTab } from './tabs';

export const metadata: Metadata = { title: 'Event' };

const TABS = ['overview', 'tickets', 'sales', 'attendees', 'gates', 'live'] as const;
type Tab = (typeof TABS)[number];

export default async function OrganizerEventPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const me = await requireUser();
  if (!can(me, 'organizer.events.manage')) return <Forbidden />;

  const id = Number((await params).id);
  if (!Number.isInteger(id) || id <= 0) notFound();
  const sp = await searchParams;
  const tab: Tab = (TABS as readonly string[]).includes(sp.tab ?? '') ? (sp.tab as Tab) : 'overview';

  const [result, error] = await load(api<{ data: EventItem }>(`organizer/events/${id}`));
  if (error?.status === 404) notFound();
  if (error) return <OrganizerError title="Event" error={error} />;
  const event = result.data;
  const tz = event.venue?.timezone ?? 'Australia/Sydney';

  return (
    <>
      <PageHeader
        title={event.title}
        subtitle={
          <>
            <Link href="/organizer/events">← My events</Link> · {dateTimeIn(event.starts_at, tz)} · {event.venue?.name} · <Status value={event.status} />
          </>
        }
      />
      {sp.created === '1' && <p className="alert alert-success">Draft created. Add at least one ticket type, then send the event for approval on the Overview tab.</p>}
      <Tabs
        path={`/organizer/events/${id}`}
        param="tab"
        current={tab === 'overview' ? undefined : tab}
        options={[
          { value: undefined, label: 'Overview' },
          { value: 'tickets', label: 'Ticket types' },
          { value: 'sales', label: 'Sales' },
          { value: 'attendees', label: 'Attendees' },
          { value: 'gates', label: 'Gates & staff' },
          { value: 'live', label: 'Event day' },
        ]}
      />
      {tab === 'overview' && <OverviewTab event={event} canSeeAttendees={can(me, 'organizer.attendees.view')} />}
      {tab === 'tickets' && <TicketsTab event={event} />}
      {tab === 'sales' && <SalesTab event={event} />}
      {tab === 'attendees' && (can(me, 'organizer.attendees.view') ? <AttendeesTab event={event} search={sp.search} status={sp.status} page={sp.page} /> : <Forbidden />)}
      {tab === 'gates' && <GatesTab event={event} />}
      {tab === 'live' && <LiveTab event={event} />}
    </>
  );
}
