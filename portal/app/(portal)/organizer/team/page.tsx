import type { Metadata } from 'next';
import { ActionForm } from '@/components/ActionForm';
import { humanize } from '@/components/Status';
import { Card, Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { can, requireUser } from '@/lib/session';
import type { Organizer } from '@/lib/types';
import { addTeamMember, removeTeamMember } from '../actions';
import { OrganizerError } from '../shared';

export const metadata: Metadata = { title: 'Team' };

export default async function TeamPage() {
  const me = await requireUser();
  if (!can(me, 'organizer.events.manage')) return <Forbidden />;

  const [profile, error] = await load(api<{ data: Organizer }>('organizer/profile'));
  if (error) return <OrganizerError title="Team" error={error} />;
  const org = profile.data;
  const members = org.members ?? [];
  const iAmOwner = members.some((m) => m.user_id === me.id && m.role === 'owner');

  return (
    <>
      <PageHeader title="Team" subtitle={`People who work for ${org.name}.`} />
      <div className="grid-2">
        <Card title="Members">
          <ul className="plain-list">
            {members.map((m) => (
              <li key={m.user_id} className="row-between">
                <span>
                  <strong>{m.name}</strong> <span className="small muted">{m.email}</span>
                  <div className="small">{humanize(m.role)}</div>
                </span>
                {iAmOwner && m.role !== 'owner' && can(me, 'organizer.staff.manage') && (
                  <ActionForm action={removeTeamMember} submitLabel="Remove" variant="danger" inline confirm={`Remove ${m.name} from your team?`}>
                    <input type="hidden" name="user_id" value={m.user_id} />
                  </ActionForm>
                )}
              </li>
            ))}
          </ul>
        </Card>
        <Card title="Add someone">
          {iAmOwner && can(me, 'organizer.staff.manage') ? (
            <ActionForm action={addTeamMember} submitLabel="Add to team">
              <p className="small">They need an Aus Lanka account with this email first (they can sign up in the app).</p>
              <label className="field">
                <span>Email</span>
                <input name="email" type="email" required />
              </label>
              <label className="field">
                <span>Role</span>
                <select name="role" defaultValue="gate_staff">
                  <option value="gate_staff">Gate staff: scans tickets at events they’re assigned to</option>
                  <option value="manager">Manager: manages events, tickets and attendees</option>
                </select>
              </label>
            </ActionForm>
          ) : (
            <p className="muted">Only the account owner can add or remove team members.</p>
          )}
        </Card>
      </div>
    </>
  );
}
