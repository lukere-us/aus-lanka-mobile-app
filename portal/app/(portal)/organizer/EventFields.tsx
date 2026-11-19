import { humanize } from '@/components/Status';
import { toLocalInput } from '@/lib/format';
import type { EventItem, Venue } from '@/lib/types';
import { CATEGORIES } from './shared';

/** Event inputs shared by the create and edit forms. Times are typed in the venue's local time. */
export function EventFields({ event, venues }: { event?: EventItem; venues: Venue[] }) {
  const tz = event?.venue?.timezone ?? 'Australia/Sydney';
  const startsAt = event ? toLocalInput(event.starts_at, tz) : '';
  return (
    <div className="form-grid">
      <label className="field span-2">
        <span>Title</span>
        <input name="title" required maxLength={150} defaultValue={event?.title} />
      </label>
      <label className="field">
        <span>Category</span>
        <select name="category" required defaultValue={event?.category ?? 'concert'}>
          {CATEGORIES.map((c) => (
            <option key={c} value={c}>
              {humanize(c)}
            </option>
          ))}
        </select>
      </label>
      <label className="field">
        <span>Venue</span>
        <select name="venue_id" required defaultValue={event?.venue?.id ?? ''}>
          <option value="" disabled>
            Choose a venue
          </option>
          {venues.map((v) => (
            <option key={v.id} value={v.id}>
              {v.name}, {v.city}
            </option>
          ))}
        </select>
      </label>
      <label className="field">
        <span>Starts (venue time)</span>
        <input type="datetime-local" name="starts_at" required defaultValue={startsAt} />
        <input type="hidden" name="starts_at_original" value={startsAt} />
      </label>
      <label className="field">
        <span>Doors open (optional)</span>
        <input type="datetime-local" name="doors_open_at" defaultValue={toLocalInput(event?.doors_open_at, tz)} />
      </label>
      <label className="field">
        <span>Ends (optional)</span>
        <input type="datetime-local" name="ends_at" defaultValue={toLocalInput(event?.ends_at, tz)} />
      </label>
      <label className="field">
        <span>Minimum age (optional)</span>
        <input type="number" name="min_age" min={0} max={21} defaultValue={event?.min_age ?? ''} />
      </label>
      <label className="field span-2">
        <span>Description</span>
        <textarea name="description" rows={5} maxLength={10000} defaultValue={event?.description ?? ''} />
      </label>
      <label className="field span-2">
        <span>Image link (optional)</span>
        <input type="url" name="image_url" maxLength={500} defaultValue={event?.image_url ?? ''} />
      </label>
      <label className="field span-2">
        <span>Terms and conditions (optional)</span>
        <textarea name="terms" rows={3} maxLength={5000} defaultValue={event?.terms ?? ''} />
      </label>
    </div>
  );
}
