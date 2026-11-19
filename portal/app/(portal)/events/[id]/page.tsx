import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { ActionForm } from '@/components/ActionForm';
import { Status, humanize } from '@/components/Status';
import { TicketTypesTable } from '@/components/TicketTypesTable';
import { ApiErrorPanel, Card, Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { dateTimeIn } from '@/lib/format';
import { can, requireUser } from '@/lib/session';
import type { EventItem } from '@/lib/types';
import { cancelEvent, reviewEvent } from '../actions';

export const metadata: Metadata = { title: 'Event' };

export default async function EventPage({ params }: { params: Promise<{ id: string }> }) {
  const me = await requireUser();
  if (!can(me, 'events.approve') && !can(me, 'events.manage')) return <Forbidden />;

  const id = Number((await params).id);
  if (!Number.isInteger(id) || id <= 0) notFound();
  const [result, error] = await load(api<{ data: EventItem }>(`admin/events/${id}`));
  if (error?.status === 404) notFound();
  const e = result?.data;
  const tz = e?.venue?.timezone ?? 'Australia/Sydney';

  return (
    <>
      <PageHeader title={e?.title ?? 'Event'} subtitle={<Link href="/events">← All events</Link>} />
      {error && <ApiErrorPanel error={error} />}
      {e && (
        <div className="stack-lg">
          {e.status === 'pending_review' && can(me, 'events.approve') && (
            <Card title="Approval" className="card-highlight">
              <p className="small">Check the details and ticket prices below. Approving publishes the event and opens ticket sales.</p>
              <div className="actions-row">
                <ActionForm action={reviewEvent} submitLabel="Approve and publish" inline>
                  <input type="hidden" name="id" value={e.id} />
                  <input type="hidden" name="decision" value="approve" />
                </ActionForm>
                <ActionForm action={reviewEvent} submitLabel="Send back" variant="danger" inline>
                  <input type="hidden" name="id" value={e.id} />
                  <input type="hidden" name="decision" value="reject" />
                  <input name="note" placeholder="What the organizer must change" required maxLength={500} />
                </ActionForm>
              </div>
            </Card>
          )}

          <div className="grid-2">
            <Card title="Details">
              <dl className="details">
                <dt>Status</dt>
                <dd>
                  <Status value={e.status} />
                  {e.review_note && <div className="small">Note: {e.review_note}</div>}
                </dd>
                <dt>Organizer</dt>
                <dd>{e.organizer?.name ?? '—'}</dd>
                <dt>Category</dt>
                <dd>{humanize(e.category)}</dd>
                <dt>Starts</dt>
                <dd>{dateTimeIn(e.starts_at, tz)}</dd>
                <dt>Ends</dt>
                <dd>{e.ends_at ? dateTimeIn(e.ends_at, tz) : '—'}</dd>
                <dt>Doors open</dt>
                <dd>{e.doors_open_at ? dateTimeIn(e.doors_open_at, tz) : '—'}</dd>
                <dt>Minimum age</dt>
                <dd>{e.min_age ?? 'All ages'}</dd>
              </dl>
            </Card>
            <Card title="Venue">
              {e.venue ? (
                <dl className="details">
                  <dt>Name</dt>
                  <dd>{e.venue.name}</dd>
                  <dt>Address</dt>
                  <dd>
                    {e.venue.address}, {e.venue.city}, {e.venue.country}
                  </dd>
                  <dt>Timezone</dt>
                  <dd>{e.venue.timezone}</dd>
                </dl>
              ) : (
                <p className="muted">—</p>
              )}
            </Card>
          </div>

          {(e.description || e.terms) && (
            <Card title="Description and terms">
              {e.description && <p className="prewrap">{e.description}</p>}
              {e.terms && (
                <>
                  <h3 className="h-small">Terms</h3>
                  <p className="prewrap small">{e.terms}</p>
                </>
              )}
            </Card>
          )}

          <Card title="Tickets">
            <TicketTypesTable types={e.ticket_types} timeZone={tz} />
          </Card>

          {can(me, 'events.manage') && ['published', 'pending_review', 'draft'].includes(e.status) && (
            <Card title="Cancel event">
              <ActionForm
                action={cancelEvent}
                submitLabel="Cancel event and refund everyone"
                variant="danger"
                confirm={`Cancel "${e.title}"? Sales stop, every ticket holder is told, and all valid tickets are refunded in full. This can't be undone.`}
              >
                <input type="hidden" name="id" value={e.id} />
                <p className="small">Stops sales, tells ticket holders, and refunds every valid ticket in full, including fees.</p>
                <label className="field">
                  <span>Reason (shown to ticket holders)</span>
                  <input name="reason" required maxLength={300} />
                </label>
              </ActionForm>
            </Card>
          )}
        </div>
      )}
    </>
  );
}
