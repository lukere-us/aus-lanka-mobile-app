import type { Permission } from './session';

export interface NavItem {
  href: string;
  label: string;
  /** Shown when the user has any of these permissions. */
  anyOf: Permission[];
  /** If set, the user must also have one of these roles (organizer screens need an actual organizer account). */
  roles?: string[];
}

export interface NavGroup {
  label: string;
  items: NavItem[];
}

const ORGANIZER_ROLES = ['organizer'];

export const NAV: NavGroup[] = [
  {
    label: 'Overview',
    items: [{ href: '/dashboard', label: 'Dashboard', anyOf: ['reports.view'] }],
  },
  {
    label: 'Catalogue',
    items: [
      { href: '/movies', label: 'Movies & rentals', anyOf: ['movies.manage'] },
      { href: '/events', label: 'Events', anyOf: ['events.manage', 'events.approve'] },
    ],
  },
  {
    label: 'People',
    items: [
      { href: '/users', label: 'Users & roles', anyOf: ['users.view'] },
      { href: '/organizers', label: 'Organizers', anyOf: ['organizers.approve'] },
    ],
  },
  {
    label: 'Finance',
    items: [
      { href: '/orders', label: 'Orders & payments', anyOf: ['orders.view'] },
      { href: '/refunds', label: 'Refunds', anyOf: ['refunds.approve'] },
      { href: '/payouts', label: 'Payouts', anyOf: ['payouts.manage'] },
      { href: '/reports', label: 'CSV downloads', anyOf: ['reports.view'] },
    ],
  },
  {
    label: 'My organizer account',
    items: [
      { href: '/organizer/events', label: 'My events', anyOf: ['organizer.events.manage'], roles: ORGANIZER_ROLES },
      { href: '/organizer/earnings', label: 'Earnings', anyOf: ['organizer.events.manage'], roles: ORGANIZER_ROLES },
      { href: '/organizer/team', label: 'Team', anyOf: ['organizer.events.manage'], roles: ORGANIZER_ROLES },
      { href: '/organizer/devices', label: 'Scanner devices', anyOf: ['organizer.events.manage'], roles: ORGANIZER_ROLES },
    ],
  },
  {
    label: 'System',
    items: [
      { href: '/settings', label: 'Settings & currencies', anyOf: ['settings.manage'] },
      { href: '/audit-log', label: 'Audit log', anyOf: ['audit.view'] },
    ],
  },
];
