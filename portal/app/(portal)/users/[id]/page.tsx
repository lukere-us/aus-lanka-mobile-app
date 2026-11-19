import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { ActionForm } from '@/components/ActionForm';
import { Status } from '@/components/Status';
import { ApiErrorPanel, Card, Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { dateTime } from '@/lib/format';
import { ROLES } from '@/lib/roles';
import { can, requireUser } from '@/lib/session';
import type { User } from '@/lib/types';
import { saveRoles, setStatus } from '../actions';

export const metadata: Metadata = { title: 'User' };

export default async function UserPage({ params }: { params: Promise<{ id: string }> }) {
  const me = await requireUser();
  if (!can(me, 'users.view')) return <Forbidden />;

  const id = Number((await params).id);
  if (!Number.isInteger(id) || id <= 0) notFound();
  const [result, error] = await load(api<{ data: User }>(`admin/users/${id}`));
  if (error?.status === 404) notFound();

  const user = result?.data;
  const isSelf = user?.id === me.id;
  const meIsSuper = me.roles.includes('super_admin');

  return (
    <>
      <PageHeader title={user?.name ?? 'User'} subtitle={<Link href="/users">← All users</Link>} />
      {error && <ApiErrorPanel error={error} />}
      {user && (
        <div className="stack-lg">
          <Card>
            <dl className="details">
              <dt>Email</dt>
              <dd>
                {user.email} {user.email_verified ? <span className="small muted">verified</span> : <span className="small muted">not verified</span>}
              </dd>
              <dt>Phone</dt>
              <dd>
                {user.phone ?? '—'} {user.phone && (user.phone_verified ? <span className="small muted">verified</span> : <span className="small muted">not verified</span>)}
              </dd>
              <dt>Status</dt>
              <dd>
                <Status value={user.status} />
              </dd>
              <dt>Joined</dt>
              <dd>{dateTime(user.created_at)}</dd>
              <dt>Last sign-in</dt>
              <dd>{dateTime(user.last_login_at)}</dd>
              <dt>User ID</dt>
              <dd>{user.id}</dd>
            </dl>
          </Card>

          <div className="grid-2">
            <Card title="Roles">
              {can(me, 'roles.assign') ? (
                <ActionForm action={saveRoles} submitLabel="Save roles">
                  <input type="hidden" name="id" value={user.id} />
                  <div className="checklist">
                    {ROLES.map((r) => {
                      const locked = r.slug === 'super_admin' && !meIsSuper;
                      return (
                        <label key={r.slug} className={'check' + (locked ? ' check-disabled' : '')}>
                          <input type="checkbox" name={`role_${r.slug}`} defaultChecked={user.roles.includes(r.slug)} disabled={locked} />
                          <span>
                            <strong>{r.name}</strong>
                            <span className="small muted"> {r.text}</span>
                          </span>
                          {locked && user.roles.includes(r.slug) && <input type="hidden" name={`role_${r.slug}`} value="on" />}
                        </label>
                      );
                    })}
                  </div>
                </ActionForm>
              ) : (
                <p>{user.roles.join(', ')}</p>
              )}
            </Card>

            <Card title="Account status">
              {!can(me, 'users.manage') ? (
                <p className="muted">You can’t change account status.</p>
              ) : isSelf ? (
                <p className="muted">You can’t suspend your own account.</p>
              ) : user.status === 'active' ? (
                <ActionForm action={setStatus} submitLabel="Suspend account" variant="danger" confirm={`Suspend ${user.name}? They will be signed out everywhere.`}>
                  <input type="hidden" name="id" value={user.id} />
                  <input type="hidden" name="status" value="suspended" />
                  <p className="small">A suspended person is signed out everywhere and can’t sign in or buy until reactivated.</p>
                  <label className="field">
                    <span>Reason (kept in the audit log)</span>
                    <textarea name="reason" rows={2} required maxLength={500} />
                  </label>
                </ActionForm>
              ) : (
                <ActionForm action={setStatus} submitLabel="Reactivate account">
                  <input type="hidden" name="id" value={user.id} />
                  <input type="hidden" name="status" value="active" />
                  <p className="small">This account is suspended. Reactivating lets them sign in again.</p>
                </ActionForm>
              )}
            </Card>
          </div>
        </div>
      )}
    </>
  );
}
