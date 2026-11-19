import type { Metadata } from 'next';
import Link from 'next/link';
import { ActionForm } from '@/components/ActionForm';
import { Card, Empty, Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { can, requireUser } from '@/lib/session';
import type { Venue } from '@/lib/types';
import { addVenue } from '../actions';
import { OrganizerError, TIMEZONES } from '../shared';

export const metadata: Metadata = { title: 'Venues' };

export default async function VenuesPage() {
  const me = await requireUser();
  if (!can(me, 'organizer.events.manage')) return <Forbidden />;

  const [venues, error] = await load(api<{ data: Venue[] }>('organizer/venues'));
  if (error) return <OrganizerError title="Venues" error={error} />;

  return (
    <>
      <PageHeader title="Venues" subtitle={<Link href="/organizer/events">← My events</Link>} />
      <div className="grid-2">
        <Card title="Your venues">
          {venues.data.length === 0 ? (
            <Empty>No venues yet.</Empty>
          ) : (
            <table className="table table-compact">
              <tbody>
                {venues.data.map((v) => (
                  <tr key={v.id}>
                    <td>
                      <strong>{v.name}</strong>
                      <div className="small muted">
                        {v.address}, {v.city}, {v.country} · {v.timezone}
                        {v.capacity ? ` · ${v.capacity} people` : ''}
                        {v.organizer_id === null ? ' · shared venue' : ''}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
        <Card title="Add a venue">
          <ActionForm action={addVenue} submitLabel="Add venue">
            <label className="field">
              <span>Name</span>
              <input name="name" required maxLength={150} />
            </label>
            <label className="field">
              <span>Street address</span>
              <input name="address" required maxLength={255} />
            </label>
            <div className="form-grid">
              <label className="field">
                <span>City</span>
                <input name="city" required maxLength={80} />
              </label>
              <label className="field">
                <span>Country code</span>
                <input name="country" required minLength={2} maxLength={2} defaultValue="AU" />
              </label>
              <label className="field">
                <span>Timezone</span>
                <select name="timezone" defaultValue="Australia/Sydney">
                  {TIMEZONES.map((tz) => (
                    <option key={tz}>{tz}</option>
                  ))}
                </select>
              </label>
              <label className="field">
                <span>Capacity (optional)</span>
                <input name="capacity" type="number" min={1} />
              </label>
            </div>
          </ActionForm>
        </Card>
      </div>
    </>
  );
}
