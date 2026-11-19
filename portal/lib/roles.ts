/** Roles from backend config/permissions.php, in the order shown on screen. */
export const ROLES: { slug: string; name: string; text: string }[] = [
  { slug: 'super_admin', name: 'Super admin', text: 'Everything, including other admins. Only a super admin can grant this.' },
  { slug: 'administrator', name: 'Administrator', text: 'Users, organizers, movies, events, settings and audit log.' },
  { slug: 'finance_manager', name: 'Finance', text: 'Orders, refunds, payouts and reports.' },
  { slug: 'content_manager', name: 'Content', text: 'Movies, videos and rental packages.' },
  { slug: 'event_manager', name: 'Events', text: 'Approve organizers and events, cancel events.' },
  { slug: 'organizer', name: 'Organizer', text: 'Given automatically when an organizer is approved.' },
  { slug: 'gate_staff', name: 'Gate staff', text: 'Scanner app only.' },
  { slug: 'customer', name: 'Customer', text: 'Buys rentals and tickets in the app.' },
];

export const ROLE_NAMES: Record<string, string> = Object.fromEntries(ROLES.map((r) => [r.slug, r.name]));

export function roleName(slug: string): string {
  return ROLE_NAMES[slug] ?? slug;
}
