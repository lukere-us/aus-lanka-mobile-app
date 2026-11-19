/** Coloured label for any status the API returns. */
const TONES: Record<string, 'green' | 'amber' | 'red' | 'grey' | 'blue'> = {
  active: 'green',
  approved: 'green',
  published: 'green',
  paid: 'green',
  succeeded: 'green',
  valid: 'green',
  ready: 'green',
  on_sale: 'green',
  pending: 'amber',
  pending_review: 'amber',
  pending_payment: 'amber',
  requested: 'amber',
  processing: 'amber',
  review: 'amber',
  initiated: 'amber',
  partially_refunded: 'amber',
  draft: 'grey',
  hidden: 'grey',
  inactive: 'grey',
  unpublished: 'grey',
  expired: 'grey',
  completed: 'blue',
  used: 'blue',
  refunded: 'blue',
  suspended: 'red',
  rejected: 'red',
  failed: 'red',
  cancelled: 'red',
  revoked: 'red',
  sold_out: 'red',
};

export function humanize(value: string): string {
  const s = value.replace(/_/g, ' ');
  return s.charAt(0).toUpperCase() + s.slice(1);
}

export function Status({ value }: { value: string | null | undefined }) {
  if (!value) return <span className="muted">—</span>;
  return <span className={`status status-${TONES[value] ?? 'grey'}`}>{humanize(value)}</span>;
}
