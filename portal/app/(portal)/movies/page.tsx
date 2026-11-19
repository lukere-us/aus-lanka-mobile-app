import type { Metadata } from 'next';
import Link from 'next/link';
import { Pager, pageParam } from '@/components/Pager';
import { Status } from '@/components/Status';
import { Tabs } from '@/components/Tabs';
import { ApiErrorPanel, Empty, Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { money } from '@/lib/format';
import { can, requireUser } from '@/lib/session';
import type { Movie, Paginated } from '@/lib/types';

export const metadata: Metadata = { title: 'Movies & rentals' };

export default async function MoviesPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const me = await requireUser();
  if (!can(me, 'movies.manage')) return <Forbidden />;

  const params = await searchParams;
  const status = ['draft', 'published', 'unpublished'].includes(params.status ?? '') ? params.status : undefined;
  const page = pageParam(params.page);
  const [result, error] = await load(api<Paginated<Movie>>('admin/movies', { query: { status, page } }));

  return (
    <>
      <PageHeader
        title="Movies & rentals"
        subtitle="A movie needs a ready video and at least one active rental package before it can be published."
        actions={
          <Link className="btn btn-sm btn-primary" href="/movies/new">
            Add movie
          </Link>
        }
      />
      <Tabs
        path="/movies"
        current={status}
        options={[
          { value: undefined, label: 'All' },
          { value: 'published', label: 'Published' },
          { value: 'draft', label: 'Draft' },
          { value: 'unpublished', label: 'Unpublished' },
        ]}
      />
      {error && <ApiErrorPanel error={error} />}
      {result && (
        <div className="card card-flush">
          {result.data.length === 0 ? (
            <Empty>No movies yet.</Empty>
          ) : (
            <table className="table">
              <thead>
                <tr>
                  <th>Title</th>
                  <th>Year</th>
                  <th>Video</th>
                  <th>Rental packages</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {result.data.map((m) => (
                  <tr key={m.id}>
                    <td>
                      <Link href={`/movies/${m.id}`}>{m.title}</Link>
                      <div className="small muted">{[m.classification, ...(m.languages ?? [])].filter(Boolean).join(' · ')}</div>
                    </td>
                    <td>{m.release_year ?? '—'}</td>
                    <td>{m.assets.some((a) => a.kind === 'feature' && a.status === 'ready') ? 'Ready' : <span className="muted">Missing</span>}</td>
                    <td className="small">
                      {m.rental_packages.length === 0
                        ? '—'
                        : m.rental_packages.map((p) => `${p.name} ${money(p.price.amount, p.price.currency)}${p.status === 'active' ? '' : ' (off)'}`).join(', ')}
                    </td>
                    <td>
                      <Status value={m.status} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <Pager path="/movies" params={{ status }} page={result.meta.current_page} lastPage={result.meta.last_page} total={result.meta.total} />
        </div>
      )}
    </>
  );
}
