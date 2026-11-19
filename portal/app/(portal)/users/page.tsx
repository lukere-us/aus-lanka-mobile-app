import type { Metadata } from 'next';
import Link from 'next/link';
import { Pager, pageParam } from '@/components/Pager';
import { Status } from '@/components/Status';
import { ApiErrorPanel, Empty, Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { dateTime } from '@/lib/format';
import { ROLES, roleName } from '@/lib/roles';
import { can, requireUser } from '@/lib/session';
import type { Paginated, User } from '@/lib/types';

export const metadata: Metadata = { title: 'Users & roles' };

export default async function UsersPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const me = await requireUser();
  if (!can(me, 'users.view')) return <Forbidden />;

  const params = await searchParams;
  const page = pageParam(params.page);
  const filters = { search: params.search?.trim() || undefined, role: params.role || undefined, status: params.status || undefined };
  const [result, error] = await load(api<Paginated<User>>('admin/users', { query: { ...filters, page } }));

  return (
    <>
      <PageHeader title="Users & roles" subtitle="Customers, staff and organizers. Open someone to change their roles or suspend them." />

      <form method="get" className="filters">
        <label className="field">
          <span>Search</span>
          <input name="search" defaultValue={filters.search} placeholder="Name, email or phone" />
        </label>
        <label className="field">
          <span>Role</span>
          <select name="role" defaultValue={filters.role ?? ''}>
            <option value="">Any role</option>
            {ROLES.map((r) => (
              <option key={r.slug} value={r.slug}>
                {r.name}
              </option>
            ))}
          </select>
        </label>
        <label className="field">
          <span>Status</span>
          <select name="status" defaultValue={filters.status ?? ''}>
            <option value="">Any</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
          </select>
        </label>
        <div className="filters-actions">
          <button className="btn btn-sm btn-primary">Search</button>
          <Link className="btn btn-sm btn-ghost" href="/users">
            Clear
          </Link>
        </div>
      </form>

      {error && <ApiErrorPanel error={error} />}
      {result && (
        <div className="card card-flush">
          {result.data.length === 0 ? (
            <Empty>No one matches these filters.</Empty>
          ) : (
            <table className="table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Roles</th>
                  <th>Status</th>
                  <th>Last sign-in</th>
                </tr>
              </thead>
              <tbody>
                {result.data.map((u) => (
                  <tr key={u.id}>
                    <td>
                      <Link href={`/users/${u.id}`}>{u.name}</Link>
                    </td>
                    <td>
                      {u.email} {!u.email_verified && <span className="small muted">(not verified)</span>}
                    </td>
                    <td>{u.roles.map(roleName).join(', ') || '—'}</td>
                    <td>
                      <Status value={u.status} />
                    </td>
                    <td className="nowrap">{dateTime(u.last_login_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <Pager path="/users" params={{ ...filters }} page={result.meta.current_page} lastPage={result.meta.last_page} total={result.meta.total} />
        </div>
      )}
    </>
  );
}
