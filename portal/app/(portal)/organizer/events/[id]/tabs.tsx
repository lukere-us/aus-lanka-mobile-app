import Link from 'next/link';
import { ActionForm } from '@/components/ActionForm';
import { Pager, pageParam } from '@/components/Pager';
import { Status, humanize } from '@/components/Status';
import { ApiErrorPanel, Card, Empty, Stat } from '@/components/ui';
import { api, load } from '@/lib/api';
import { BASE_CURRENCY } from '@/lib/config';
import { count, dateTime, money, toLocalInput } from '@/lib/format';
import type { Attendee, EventItem, EventSales, EventStats, Listed, Organizer, TicketType, Venue } from '@/lib/types';
import {
  addGate,
  addTicketType,
  admitTicket,
  assignStaff,
  removeGate,
  submitEvent,
  unassignStaff,
  updateEvent,
  updateTicketType,
} from '../../actions';
import { EventFields } from '../../EventFields';
import { TICKET_KINDS } from '../../shared';

const tzOf = (e: EventItem) => e.venue?.timezone ?? 'Australia/Sydney';

// --- Overview -----------------------------------------------------------------------------------

export async function OverviewTab({ event, canSeeAttendees }: { event: EventItem; canSeeAttendees: boolean }) {
  const [venues] = await load(api<{ data: Venue[] }>('organizer/venues'));
  const editable = event.status === 'draft' || event.status === 'published';
  const hasTickets = event.ticket_types.length > 0;

  return (
    <div className="stack-lg">
      {event.status === 'draft' && (
        <Card title="Send for approval" className="card-highlight">
          {event.review_note && <p className="alert alert-error">Sent back by the platform: {event.review_note}</p>}
          <ul className="checks small">
            <li className="ok">Event details saved</li>
            <li className={hasTickets ? 'ok' : 'todo'}>
              {hasTickets ? 'Ticket types added' : <Link href={`/organizer/events/${event.id}?tab=tickets`}>Add at least one ticket type</Link>}
            </li>
          </ul>
          <ActionForm action={submitEvent} submitLabel="Send for approval" confirm="Send this event to the platform for approval?">
            <input type="hidden" name="id" value={event.id} />
          </ActionForm>
        </Card>
      )}
      {event.status === 'pending_review' && <p className="alert alert-info">Waiting for the platform to approve this event. You can’t edit it meanwhile.</p>}
      {event.status === 'cancelled' && <p className="alert alert-error">This event was cancelled{event.review_note ? `: ${event.review_note}` : '.'}</p>}

      <Card title="Event details">
        {editable ? (
          <ActionForm action={updateEvent} submitLabel="Save changes">
            <input type="hidden" name="id" value={event.id} />
            <EventFields event={event} venues={venues?.data ?? []} />
          </ActionForm>
        ) : (
          <p className="muted">Details can’t be changed while the event is {humanize(event.status).toLowerCase()}.</p>
        )}
      </Card>
      {canSeeAttendees && event.status === 'published' && (
        <p className="small muted">
          Download the guest list from the <Link href={`/organizer/events/${event.id}?tab=attendees`}>Attendees</Link> tab.
        </p>
      )}
    </div>
  );
}

// --- Ticket types -------------------------------------------------------------------------------

function TicketTypeFields({ t, tz }: { t?: TicketType; tz: string }) {
  return (
    <div className="form-grid">
      <input type="hidden" name="timezone" value={tz} />
      <label className="field">
        <span>Name</span>
        <input name="name" required maxLength={80} defaultValue={t?.name} placeholder="General admission" />
      </label>
      <label className="field">
        <span>Kind</span>
        <select name="kind" defaultValue={t?.kind ?? 'general'}>
          {TICKET_KINDS.map((k) => (
            <option key={k.value} value={k.value}>
              {k.label}
            </option>
          ))}
        </select>
      </label>
      <label className="field">
        <span>Price ({t?.price.currency ?? BASE_CURRENCY})</span>
        <input name="price" inputMode="decimal" required pattern="\d+(\.\d{1,2})?" defaultValue={t?.price.amount} />
      </label>
      <label className="field">
        <span>Number of tickets</span>
        <input name="quantity_total" type="number" min={1} max={200000} required defaultValue={t?.quantity_total} />
      </label>
      <label className="field">
        <span>Most per order</span>
        <input name="max_per_order" type="number" min={1} max={50} defaultValue={t?.max_per_order ?? 10} />
      </label>
      <label className="field">
        <span>Visible to customers</span>
        <select name="status" defaultValue={t?.status ?? 'active'}>
          <option value="active">Yes, on sale</option>
          <option value="hidden">No, hidden</option>
        </select>
      </label>
      <label className="field">
        <span>Sale starts (optional, venue time)</span>
        <input type="datetime-local" name="sale_starts_at" defaultValue={toLocalInput(t?.sale_starts_at, tz)} />
      </label>
      <label className="field">
        <span>Sale ends (optional, venue time)</span>
        <input type="datetime-local" name="sale_ends_at" defaultValue={toLocalInput(t?.sale_ends_at, tz)} />
      </label>
      <label className="field span-2">
        <span>Description (optional)</span>
        <input name="description" maxLength={300} defaultValue={t?.description ?? ''} />
      </label>
    </div>
  );
}

export function TicketsTab({ event }: { event: EventItem }) {
  const tz = tzOf(event);
  const locked = event.status === 'cancelled' || event.status === 'completed';
  return (
    <div className="stack">
      {event.ticket_types.length === 0 && <p className="muted">No ticket types yet.</p>}
      {event.ticket_types.map((t) => (
        <details key={t.id} className="subcard">
          <summary>
            <strong>{t.name}</strong> · {money(t.price.amount, t.price.currency)} · {count(t.quantity_sold)} sold of {count(t.quantity_total)} ·{' '}
            {count(t.available)} left <Status value={t.status === 'hidden' ? 'hidden' : t.sale_state} />
          </summary>
          {locked ? (
            <p className="muted small">This event can no longer be changed.</p>
          ) : (
            <ActionForm action={updateTicketType} submitLabel="Save ticket type">
              <input type="hidden" name="id" value={event.id} />
              <input type="hidden" name="ticket_type_id" value={t.id} />
              <p className="small muted">You can’t set the number of tickets below what’s already sold or held in checkouts.</p>
              <TicketTypeFields t={t} tz={tz} />
            </ActionForm>
          )}
        </details>
      ))}
      {!locked && (
        <details className="subcard" open={event.ticket_types.length === 0}>
          <summary>Add a ticket type</summary>
          <ActionForm action={addTicketType} submitLabel="Add ticket type">
            <input type="hidden" name="id" value={event.id} />
            <TicketTypeFields tz={tz} />
          </ActionForm>
        </details>
      )}
    </div>
  );
}

// --- Sales ---------------------------------------------------------------------------------------

export async function SalesTab({ event }: { event: EventItem }) {
  const [result, error] = await load(api<{ data: EventSales }>(`organizer/events/${event.id}/sales`));
  if (error) return <ApiErrorPanel error={error} />;
  const s = result.data;
  return (
    <div className="stack-lg">
      <div className="stats-grid">
        <Stat label="Ticket sales" value={money(s.gross_sales, s.currency)} hint={`${count(s.orders)} orders`} />
        <Stat label="Refunded" value={money(s.refunds, s.currency)} />
        <Stat label="Platform commission" value={money(s.platform_commission, s.currency)} />
        <Stat label="Your earnings" value={money(s.net_to_organizer, s.currency)} hint="Paid out after the event; see Earnings" />
      </div>
      <Card title="By ticket type">
        <table className="table table-compact">
          <thead>
            <tr>
              <th>Ticket</th>
              <th className="num">Price</th>
              <th className="num">Sold</th>
              <th className="num">In checkout</th>
              <th className="num">Left</th>
            </tr>
          </thead>
          <tbody>
            {s.ticket_types.map((t) => (
              <tr key={t.id}>
                <td>{t.name}</td>
                <td className="num">{money(t.price.amount, t.price.currency)}</td>
                <td className="num">
                  {count(t.sold)} / {count(t.quantity_total)}
                </td>
                <td className="num">{count(t.held_in_checkout)}</td>
                <td className="num">{count(t.available)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </div>
  );
}

// --- Attendees -----------------------------------------------------------------------------------

export async function AttendeesTab({ event, search, status, page }: { event: EventItem; search?: string; status?: string; page?: string }) {
  const p = pageParam(page);
  const st = ['valid', 'used', 'cancelled', 'refunded'].includes(status ?? '') ? status : undefined;
  const q = search?.trim() || undefined;
  const [result, error] = await load(api<Listed<Attendee>>(`organizer/events/${event.id}/attendees`, { query: { search: q, status: st, page: p } }));
  const csvQuery = new URLSearchParams(Object.entries({ search: q, status: st }).filter(([, v]) => v) as [string, string][]).toString();

  return (
    <div className="stack">
      <form method="get" className="filters">
        <input type="hidden" name="tab" value="attendees" />
        <label className="field">
          <span>Name, email or order reference</span>
          <input name="search" defaultValue={q} />
        </label>
        <label className="field">
          <span>Tickets</span>
          <select name="status" defaultValue={st ?? ''}>
            <option value="">Valid and checked in</option>
            <option value="valid">Not checked in yet</option>
            <option value="used">Checked in</option>
            <option value="cancelled">Cancelled</option>
            <option value="refunded">Refunded</option>
          </select>
        </label>
        <div className="filters-actions">
          <button className="btn btn-sm btn-primary">Search</button>
          <a className="btn btn-sm" href={`/download/organizer/events/${event.id}/attendees.csv${csvQuery ? `?${csvQuery}` : ''}`} download>
            Download CSV
          </a>
        </div>
      </form>
      <p className="small muted">Emails are partly hidden to protect customers’ privacy.</p>
      {error && <ApiErrorPanel error={error} />}
      {result && (
        <div className="card card-flush">
          {result.data.length === 0 ? (
            <Empty>No tickets match.</Empty>
          ) : (
            <table className="table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Name</th>
                  <th>Ticket</th>
                  <th>Order</th>
                  <th>Status</th>
                  <th>Gate exception</th>
                </tr>
              </thead>
              <tbody>
                {result.data.map((a) => (
                  <tr key={a.ticket_id}>
                    <td>{a.number}</td>
                    <td>
                      {a.name ?? '—'}
                      <div className="small muted">{a.email}</div>
                    </td>
                    <td>{a.ticket_type}</td>
                    <td className="nowrap">{a.order}</td>
                    <td>
                      <Status value={a.status} />
                      {a.checked_in_at && <div className="small muted">{dateTime(a.checked_in_at)}</div>}
                    </td>
                    <td>
                      {(a.status === 'valid' || a.status === 'used') && (
                        <details>
                          <summary className="small">{a.status === 'valid' ? 'Let in by hand' : 'Undo check-in'}</summary>
                          <ActionForm action={admitTicket} submitLabel={a.status === 'valid' ? 'Check in' : 'Undo'} inline variant={a.status === 'valid' ? 'primary' : 'danger'}>
                            <input type="hidden" name="id" value={event.id} />
                            <input type="hidden" name="ticket_id" value={a.ticket_id} />
                            {a.status === 'used' && <input type="hidden" name="undo" value="1" />}
                            <input name="reason" placeholder="Reason" required maxLength={300} />
                          </ActionForm>
                        </details>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <Pager
            path={`/organizer/events/${event.id}`}
            params={{ tab: 'attendees', search: q, status: st }}
            page={p}
            lastPage={Math.max(1, Math.ceil(result.meta.total / 100))}
            total={result.meta.total}
          />
        </div>
      )}
    </div>
  );
}

// --- Gates and staff -----------------------------------------------------------------------------

export async function GatesTab({ event }: { event: EventItem }) {
  const [[gates, gateError], [staff], [profile]] = await Promise.all([
    load(api<{ data: { id: number; name: string }[] }>(`organizer/events/${event.id}/gates`)),
    load(api<{ data: { user_id: number; name: string; email: string; gate: { id: number; name: string } | null }[] }>(`organizer/events/${event.id}/staff`)),
    load(api<{ data: Organizer }>('organizer/profile')),
  ]);
  if (gateError) return <ApiErrorPanel error={gateError} />;
  const assigned = new Set((staff?.data ?? []).map((s) => s.user_id));
  const candidates = (profile?.data.members ?? []).filter((m) => !assigned.has(m.user_id));

  return (
    <div className="grid-2">
      <Card title="Gates">
        <p className="small muted">Optional. Name the entrances so scans and staff can be tied to one.</p>
        {gates.data.length === 0 ? (
          <Empty>No gates. Scanning works without them.</Empty>
        ) : (
          <ul className="plain-list">
            {gates.data.map((g) => (
              <li key={g.id} className="row-between">
                <span>{g.name}</span>
                <ActionForm action={removeGate} submitLabel="Remove" variant="danger" inline confirm={`Remove gate "${g.name}"?`}>
                  <input type="hidden" name="id" value={event.id} />
                  <input type="hidden" name="gate_id" value={g.id} />
                </ActionForm>
              </li>
            ))}
          </ul>
        )}
        <ActionForm action={addGate} submitLabel="Add gate" inline>
          <input type="hidden" name="id" value={event.id} />
          <input name="name" placeholder="Main gate" required maxLength={60} />
        </ActionForm>
      </Card>

      <Card title="Staff at this event">
        <p className="small muted">
          Only staff assigned here can scan this event’s tickets. Add people to your team first on <Link href="/organizer/team">Team</Link>.
        </p>
        {(staff?.data ?? []).length === 0 ? (
          <Empty>No one assigned yet.</Empty>
        ) : (
          <ul className="plain-list">
            {staff!.data.map((s) => (
              <li key={s.user_id} className="row-between">
                <span>
                  {s.name} <span className="small muted">{s.email}</span>
                  {s.gate && <span className="small"> · {s.gate.name}</span>}
                </span>
                <ActionForm action={unassignStaff} submitLabel="Remove" variant="danger" inline>
                  <input type="hidden" name="id" value={event.id} />
                  <input type="hidden" name="user_id" value={s.user_id} />
                </ActionForm>
              </li>
            ))}
          </ul>
        )}
        {candidates.length > 0 && (
          <ActionForm action={assignStaff} submitLabel="Assign" inline>
            <input type="hidden" name="id" value={event.id} />
            <select name="user_id" required defaultValue="">
              <option value="" disabled>
                Team member
              </option>
              {candidates.map((m) => (
                <option key={m.user_id} value={m.user_id}>
                  {m.name} ({humanize(m.role)})
                </option>
              ))}
            </select>
            <select name="gate_id" defaultValue="">
              <option value="">Any gate</option>
              {gates.data.map((g) => (
                <option key={g.id} value={g.id}>
                  {g.name}
                </option>
              ))}
            </select>
          </ActionForm>
        )}
      </Card>
    </div>
  );
}

// --- Event day -----------------------------------------------------------------------------------

export async function LiveTab({ event }: { event: EventItem }) {
  const [result, error] = await load(api<{ data: EventStats }>(`organizer/events/${event.id}/stats`));
  if (error) return <ApiErrorPanel error={error} />;
  const s = result.data;
  const rejected = Object.entries(s.rejected_scans);
  return (
    <div className="stack-lg">
      <div className="row-between">
        <p className="small muted">As of {dateTime(s.as_of)}. Reload the page for the latest numbers.</p>
        <Link className="btn btn-sm" href={`/organizer/events/${event.id}?tab=live`}>
          Refresh
        </Link>
      </div>
      <div className="stats-grid">
        <Stat label="Tickets issued" value={count(s.tickets_issued)} />
        <Stat label="Checked in" value={count(s.checked_in)} hint={s.tickets_issued ? `${Math.round((s.checked_in / s.tickets_issued) * 100)}%` : undefined} />
        <Stat label="Still to arrive" value={count(s.still_to_arrive)} />
        <Stat label="Admitted in last 15 min" value={count(s.admitted_last_15_min)} />
      </div>
      <div className="grid-2">
        <Card title="By ticket type">
          <table className="table table-compact">
            <tbody>
              {s.by_ticket_type.map((t) => (
                <tr key={t.ticket_type}>
                  <td>{t.ticket_type}</td>
                  <td className="num">
                    {count(t.checked_in)} / {count(t.issued)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
        <Card title="By gate">
          {s.by_gate.length === 0 ? (
            <Empty>No one admitted yet.</Empty>
          ) : (
            <table className="table table-compact">
              <tbody>
                {s.by_gate.map((g) => (
                  <tr key={g.gate}>
                    <td>{g.gate}</td>
                    <td className="num">{count(g.admitted)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          {rejected.length > 0 && (
            <>
              <h3 className="h-small">Refused scans</h3>
              <ul className="plain-list small">
                {rejected.map(([reason, n]) => (
                  <li key={reason}>
                    {humanize(reason)}: {count(n)}
                  </li>
                ))}
              </ul>
            </>
          )}
        </Card>
      </div>
    </div>
  );
}
