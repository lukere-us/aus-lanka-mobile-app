import Link from 'next/link';
import { ApiErrorPanel, PageHeader } from '@/components/ui';
import type { ApiError } from '@/lib/api';

export const CATEGORIES = ['concert', 'theatre', 'comedy', 'festival', 'sports', 'family', 'conference', 'other'];
export const TICKET_KINDS = [
  { value: 'general', label: 'General admission' },
  { value: 'early_bird', label: 'Early bird' },
  { value: 'vip', label: 'VIP' },
  { value: 'vvip', label: 'VVIP' },
  { value: 'custom', label: 'Other' },
];
export const TIMEZONES = [
  'Australia/Sydney',
  'Australia/Melbourne',
  'Australia/Brisbane',
  'Australia/Adelaide',
  'Australia/Perth',
  'Australia/Hobart',
  'Australia/Darwin',
  'Pacific/Auckland',
  'Asia/Colombo',
  'Asia/Dubai',
  'Asia/Singapore',
  'Europe/London',
  'America/New_York',
  'America/Toronto',
];

/** Shown when the API says the signed-in person isn't part of an organizer (for example a platform admin). */
export function OrganizerError({ title, error }: { title: string; error: ApiError }) {
  if (error.code === 'not_an_organizer' || error.code === 'organizer_suspended') {
    return (
      <>
        <PageHeader title={title} />
        <div className="card">
          <p>{error.message}</p>
          <p className="small muted">
            These screens are for organizer accounts. Platform staff manage events from <Link href="/events">Events</Link>.
          </p>
        </div>
      </>
    );
  }
  return (
    <>
      <PageHeader title={title} />
      <ApiErrorPanel error={error} />
    </>
  );
}
