'use server';

import { redirect } from 'next/navigation';
import { api, ApiError } from '@/lib/api';
import { attempt, checked, compact, idFrom, int, nullableText, text, type ActionResult } from '@/lib/actions';
import { fromLocalInput } from '@/lib/format';
import type { EventItem, Venue } from '@/lib/types';

/** The venue's timezone, so times typed in the form mean local time at the venue. */
async function venueTimezone(venueId: number | undefined): Promise<string> {
  if (!venueId) return 'Australia/Sydney';
  const venues = (await api<{ data: Venue[] }>('organizer/venues')).data;
  return venues.find((v) => v.id === venueId)?.timezone ?? 'Australia/Sydney';
}

async function eventFields(form: FormData) {
  const venueId = int(form, 'venue_id');
  const tz = await venueTimezone(venueId);
  return {
    title: text(form, 'title'),
    category: text(form, 'category'),
    venue_id: venueId,
    starts_at: fromLocalInput(text(form, 'starts_at'), tz),
    ends_at: fromLocalInput(text(form, 'ends_at'), tz) ?? null,
    doors_open_at: fromLocalInput(text(form, 'doors_open_at'), tz) ?? null,
    description: nullableText(form, 'description'),
    image_url: nullableText(form, 'image_url'),
    terms: nullableText(form, 'terms'),
    min_age: int(form, 'min_age') ?? null,
  };
}

function failed(e: unknown): ActionResult {
  if (e instanceof ApiError) return { error: e.message, details: e.details, at: Date.now() };
  throw e;
}

export async function createEvent(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  let event: EventItem;
  try {
    event = (await api<{ data: EventItem }>('organizer/events', { method: 'POST', body: await eventFields(form) })).data;
  } catch (e) {
    return failed(e);
  }
  redirect(`/organizer/events/${event.id}?tab=tickets&created=1`);
}

export async function updateEvent(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  const fields = await eventFields(form);
  // Don't resend an unchanged start time: the API requires a new start to be in the future.
  if (text(form, 'starts_at') === text(form, 'starts_at_original')) delete (fields as Partial<typeof fields>).starts_at;
  return attempt(() => api(`organizer/events/${id}`, { method: 'PATCH', body: compact(fields) }), 'Event saved.');
}

export async function submitEvent(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  return attempt(() => api(`organizer/events/${id}/submit`, { method: 'POST' }), 'Sent for approval. You’ll be notified when it’s published.');
}

async function ticketTypeFields(form: FormData) {
  const tz = text(form, 'timezone') ?? 'Australia/Sydney';
  return compact({
    name: text(form, 'name'),
    kind: text(form, 'kind'),
    description: nullableText(form, 'description'),
    price: text(form, 'price'),
    quantity_total: int(form, 'quantity_total'),
    max_per_order: int(form, 'max_per_order'),
    sale_starts_at: fromLocalInput(text(form, 'sale_starts_at'), tz) ?? null,
    sale_ends_at: fromLocalInput(text(form, 'sale_ends_at'), tz) ?? null,
    status: text(form, 'status'),
  });
}

export async function addTicketType(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  return attempt(async () => api(`organizer/events/${id}/ticket-types`, { method: 'POST', body: await ticketTypeFields(form) }), 'Ticket type added.');
}

export async function updateTicketType(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  const typeId = idFrom(form, 'ticket_type_id');
  return attempt(
    async () => api(`organizer/events/${id}/ticket-types/${typeId}`, { method: 'PATCH', body: await ticketTypeFields(form) }),
    'Ticket type saved.',
  );
}

export async function addVenue(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  return attempt(
    () =>
      api('organizer/venues', {
        method: 'POST',
        body: compact({
          name: text(form, 'name'),
          address: text(form, 'address'),
          city: text(form, 'city'),
          country: text(form, 'country'),
          timezone: text(form, 'timezone'),
          capacity: int(form, 'capacity'),
        }),
      }),
    'Venue added.',
  );
}

export async function addGate(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  return attempt(() => api(`organizer/events/${id}/gates`, { method: 'POST', body: { name: text(form, 'name') } }), 'Gate added.');
}

export async function removeGate(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  const gate = idFrom(form, 'gate_id');
  return attempt(() => api(`organizer/events/${id}/gates/${gate}`, { method: 'DELETE' }), 'Gate removed.');
}

export async function assignStaff(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  return attempt(
    () => api(`organizer/events/${id}/staff`, { method: 'POST', body: { user_id: int(form, 'user_id'), gate_id: int(form, 'gate_id') ?? null } }),
    'Staff member assigned to this event.',
  );
}

export async function unassignStaff(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  const user = idFrom(form, 'user_id');
  return attempt(() => api(`organizer/events/${id}/staff/${user}`, { method: 'DELETE' }), 'Removed from this event.');
}

export async function admitTicket(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  const ticket = idFrom(form, 'ticket_id');
  const undo = checked(form, 'undo');
  return attempt(
    () => api(`organizer/events/${id}/tickets/${ticket}/${undo ? 'undo-check-in' : 'admit'}`, { method: 'POST', body: { reason: text(form, 'reason') } }),
    undo ? 'Check-in undone. The ticket can be scanned again.' : 'Ticket checked in by hand.',
  );
}

export async function addTeamMember(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  return attempt(
    () => api('organizer/staff', { method: 'POST', body: { email: text(form, 'email'), role: text(form, 'role') } }),
    'Added to your team.',
  );
}

export async function removeTeamMember(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const user = idFrom(form, 'user_id');
  return attempt(() => api(`organizer/staff/${user}`, { method: 'DELETE' }), 'Removed from your team.');
}

export async function revokeDevice(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  return attempt(() => api(`organizer/devices/${id}/revoke`, { method: 'POST' }), 'Device blocked. It can no longer scan tickets.');
}
