import { Sidebar } from '@/components/Sidebar';
import { NAV } from '@/lib/nav';
import { can, canUsePortal, requireUser } from '@/lib/session';
import { roleName } from '@/lib/roles';
import { logout } from '../login/actions';


/** Every page inside (portal) requires a signed-in user with portal access. */
export default async function PortalLayout({ children }: { children: React.ReactNode }) {
  const user = await requireUser();

  if (!canUsePortal(user)) {
    return (
      <main className="auth-page">
        <div className="auth-card card stack">
          <h1>No access</h1>
          <p>
            {user.email} is signed in but has no access to the admin website. Customers and gate staff use the mobile
            apps.
          </p>
          <form action={logout}>
            <button className="btn">Sign out</button>
          </form>
        </div>
      </main>
    );
  }

  const groups = NAV.map((g) => ({ ...g, items: g.items.filter((i) => i.anyOf.some((p) => can(user, p)) && (!i.roles || i.roles.some((r) => user.roles.includes(r)))) })).filter(
    (g) => g.items.length > 0,
  );
  const roles = user.roles.filter((r) => r !== 'customer').map(roleName);

  return (
    <div className="shell">
      <aside className="sidebar">
        <div className="brand">
          <span className="brand-mark" aria-hidden>AL</span>
          <span>Aus Lanka</span>
        </div>
        <Sidebar groups={groups} />
        <div className="sidebar-user">
          <div className="sidebar-user-name">{user.name}</div>
          <div className="muted small">{roles.join(', ')}</div>
          <form action={logout}>
            <button className="btn btn-ghost btn-sm">Sign out</button>
          </form>
        </div>
      </aside>
      <main className="content">{children}</main>
    </div>
  );
}
