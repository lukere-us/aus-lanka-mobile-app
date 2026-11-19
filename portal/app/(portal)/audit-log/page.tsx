import type { Metadata } from 'next';
import Link from 'next/link';
import { PeriodPicker } from '@/components/PeriodPicker';
import { ApiErrorPanel, Empty, Forbidden, PageHeader } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { addDays, count, dateTime, isYmd, todayLocal } from '@/lib/format';
import { can, requireUser } from '@/lib/session';

export const metadata: Metadata = { title: 'Audit log' };

interface AuditEntry {
  id: number;
  at: string;
  action: string;
  actor: { id: number; name: string; email: string } | null;
  subject: { type: string; id: number } | null;
  changes: unknown;
  ip_address: string | null;
  request_id: string | null;
}

interface AuditPage {
  data: AuditEntry[];
  meta: { total: number; current_page: number };
}

const FILTERS = ['action', 'actor_id', 'subject_type', 'subject_id', 'request_id'] as const;
const PER_PAGE = 50;

export default async function AuditLogPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const user = await requireUser();
  if (!can(user, 'audit.view')) return <Forbidden />;

  const params = await searchParams;
  const to = isYmd(params.to) ? params.to : todayLocal();
  const from = isYmd(params.from) ? params.from : addDays(to, -6);
  const filters: Record<string, string> = {};
  for (const key of FILTERS) {
    const value = params[key]?.trim();
    if (value) filters[key] = value;
  }
  const page = Math.max(1, Number.parseInt(params.page ?? '1', 10) || 1);

  let result: AuditPage | null = null;
  let error: ApiError | null = null;
  try {
    result = await api<AuditPage>('admin/audit-logs', { query: { from, to, page, ...filters } });
  } catch (e) {
    if (!(e instanceof ApiError)) throw e;
    error = e;
  }

  const qs = (extra: Record<string, string | number>) =>
    new URLSearchParams({ from, to, ...filters, ...Object.fromEntries(Object.entries(extra).map(([k, v]) => [k, String(v)])) }).toString();
  const lastPage = result ? Math.max(1, Math.ceil(result.meta.total / PER_PAGE)) : 1;

  return (
    <>
      <PageHeader
        title="Audit log"
        subtitle="Who changed what. Newest first; times in Sydney."
        actions={
          <a className="btn btn-sm" href={`/download/admin/audit-logs?${qs({})}`} download>
            Download CSV
          </a>
        }
      />
      <PeriodPicker path="/audit-log" from={from} to={to} extra={filters} />

      <form method="get" action="/audit-log" className="filters">
        <input type="hidden" name="from" value={from} />
        <input type="hidden" name="to" value={to} />
        <label className="field">
          <span>Action</span>
          <input name="action" defaultValue={filters.action} placeholder="e.g. refund.approved or refund.*" />
        </label>
        <label className="field">
          <span>Person (user ID)</span>
          <input name="actor_id" inputMode="numeric" pattern="\d*" defaultValue={filters.actor_id} />
        </label>
        <label className="field">
          <span>Item type</span>
          <input name="subject_type" defaultValue={filters.subject_type} placeholder="e.g. Order, Event, User" />
        </label>
        <label className="field">
          <span>Item ID</span>
          <input name="subject_id" inputMode="numeric" pattern="\d*" defaultValue={filters.subject_id} />
        </label>
        <label className="field">
          <span>Request ID</span>
          <input name="request_id" defaultValue={filters.request_id} />
        </label>
        <div className="filters-actions">
          <button className="btn btn-sm btn-primary">Search</button>
          <Link className="btn btn-sm btn-ghost" href={`/audit-log?${new URLSearchParams({ from, to })}`}>
            Clear
          </Link>
        </div>
      </form>

      {error && <ApiErrorPanel error={error} />}

      {result && (
        <div className="card card-flush">
          {result.data.length === 0 ? (
            <Empty>Nothing recorded for these filters.</Empty>
          ) : (
            <table className="table">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Action</th>
                  <th>By</th>
                  <th>Item</th>
                  <th>Details</th>
                </tr>
              </thead>
              <tbody>
                {result.data.map((a) => (
                  <tr key={a.id}>
                    <td className="nowrap">{dateTime(a.at)}</td>
                    <td>
                      <code>{a.action}</code>
                    </td>
                    <td>{a.actor ? <span title={a.actor.email}>{a.actor.name}</span> : <span className="muted">System</span>}</td>
                    <td className="nowrap">{a.subject ? `${a.subject.type} #${a.subject.id}` : '—'}</td>
                    <td>
                      <details>
                        <summary className="small">Show</summary>
                        <pre className="json">{JSON.stringify(a.changes, null, 2)}</pre>
                        <div className="small muted">
                          IP {a.ip_address ?? '—'} · Request {a.request_id ?? '—'}
                        </div>
                      </details>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <div className="pager">
            <span className="small muted">
              {count(result.meta.total)} entries · page {result.meta.current_page} of {lastPage}
            </span>
            <span className="pager-links">
              {page > 1 && <Link href={`/audit-log?${qs({ page: page - 1 })}`}>← Newer</Link>}
              {page < lastPage && <Link href={`/audit-log?${qs({ page: page + 1 })}`}>Older →</Link>}
            </span>
          </div>
        </div>
      )}
    </>
  );
}
