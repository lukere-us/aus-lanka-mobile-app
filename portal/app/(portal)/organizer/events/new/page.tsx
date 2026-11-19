import type { Metadata } from 'next';
import Link from 'next/link';
import { ActionForm } from '@/components/ActionForm';
import { Card, Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { can, requireUser } from '@/lib/session';
import type { Venue } from '@/lib/types';
import { createEvent } from '../../actions';
import { EventFields } from '../../EventFields';
import { OrganizerError } from '../../shared';

export const metadata: Metadata = { title: 'New event' };

export default async function NewEventPage() {
  const me = await requireUser();
  if (!can(me, 'organizer.events.manage')) return <Forbidden />;

  const [venues, error] = await load(api<{ data: Venue[] }>('organizer/venues'));
  if (error) return <OrganizerError title="New event" error={error} />;

  return (
    <>
      <PageHeader title="New event" subtitle={<Link href="/organizer/events">← My events</Link>} />
      {venues.data.length === 0 ? (
        <div className="card">
          <p>
            First add the venue where the event takes place: <Link href="/organizer/venues">add a venue</Link>.
          </p>
        </div>
      ) : (
        <Card>
          <p className="small muted">
            The event is saved as a draft. Add ticket types next, then send it for approval. Venue missing?{' '}
            <Link href="/organizer/venues">Add a venue</Link>.
          </p>
          <ActionForm action={createEvent} submitLabel="Create draft">
            <EventFields venues={venues.data} />
          </ActionForm>
        </Card>
      )}
    </>
  );
}
