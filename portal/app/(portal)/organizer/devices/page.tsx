import type { Metadata } from 'next';
import { ActionForm } from '@/components/ActionForm';
import { Status } from '@/components/Status';
import { Empty, Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { dateTime } from '@/lib/format';
import { can, requireUser } from '@/lib/session';
import type { ScannerDevice } from '@/lib/types';
import { revokeDevice } from '../actions';
import { OrganizerError } from '../shared';

export const metadata: Metadata = { title: 'Scanner devices' };

export default async function DevicesPage() {
  const me = await requireUser();
  if (!can(me, 'organizer.events.manage')) return <Forbidden />;

  const [devices, error] = await load(api<{ data: ScannerDevice[] }>('organizer/devices'));
  if (error) return <OrganizerError title="Scanner devices" error={error} />;

  return (
    <>
      <PageHeader title="Scanner devices" subtitle="Phones registered to scan your tickets. Block a lost or stolen phone straight away." />
      <div className="card card-flush">
        {devices.data.length === 0 ? (
          <Empty>No devices yet. They appear when your gate staff sign in to the scanner app.</Empty>
        ) : (
          <table className="table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Registered</th>
                <th>Last scan</th>
                <th>Status</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {devices.data.map((d) => (
                <tr key={d.id}>
                  <td>
                    {d.name}
                    <div className="small muted">{d.device_uid}</div>
                  </td>
                  <td className="nowrap">{dateTime(d.created_at)}</td>
                  <td className="nowrap">{dateTime(d.last_used_at)}</td>
                  <td>{d.revoked_at ? <Status value="revoked" /> : <Status value="active" />}</td>
                  <td>
                    {!d.revoked_at && (
                      <ActionForm action={revokeDevice} submitLabel="Block" variant="danger" inline confirm={`Block "${d.name}"? It won't be able to scan tickets any more.`}>
                        <input type="hidden" name="id" value={d.id} />
                      </ActionForm>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </>
  );
}
