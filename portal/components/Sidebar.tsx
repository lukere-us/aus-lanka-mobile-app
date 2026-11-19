'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import type { NavGroup } from '@/lib/nav';

/** Receives only the groups and items this user may see (filtered on the server). */
export function Sidebar({ groups }: { groups: NavGroup[] }) {
  const pathname = usePathname();

  return (
    <nav className="sidebar-nav" aria-label="Main">
      {groups.map((group) => (
        <div key={group.label} className="nav-group">
          <div className="nav-group-label">{group.label}</div>
          <ul>
            {group.items.map((item) => {
              const active = pathname === item.href || pathname.startsWith(item.href + '/');
              return (
                <li key={item.href}>
                  <Link href={item.href} className={'nav-link' + (active ? ' active' : '')} aria-current={active ? 'page' : undefined}>
                    {item.label}
                  </Link>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </nav>
  );
}
