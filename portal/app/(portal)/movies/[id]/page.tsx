import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { ActionForm } from '@/components/ActionForm';
import { Status, humanize } from '@/components/Status';
import { ApiErrorPanel, Card, Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { BASE_CURRENCY } from '@/lib/config';
import { dateTime } from '@/lib/format';
import { can, requireUser } from '@/lib/session';
import type { Movie, RentalPackage } from '@/lib/types';
import { addAsset, addPackage, setMovieStatus, updateMovie, updatePackage } from '../actions';
import { MovieFields } from '../MovieFields';

export const metadata: Metadata = { title: 'Movie' };

function PackageFields({ p }: { p?: RentalPackage }) {
  return (
    <>
      <label className="field">
        <span>Name</span>
        <input name="name" required maxLength={80} defaultValue={p?.name} placeholder="48-hour rental" />
      </label>
      <label className="field">
        <span>Price ({BASE_CURRENCY})</span>
        <input name="price" inputMode="decimal" required pattern="\d+(\.\d{1,2})?" defaultValue={p?.price.amount} />
      </label>
      <label className="field">
        <span>Quality</span>
        <select name="quality" defaultValue={p?.quality ?? 'HD'}>
          <option>SD</option>
          <option>HD</option>
          <option>4K</option>
        </select>
      </label>
      <label className="field">
        <span>Watch window (hours after pressing play)</span>
        <input name="watch_hours" type="number" min={1} max={720} required defaultValue={p?.watch_hours ?? 48} />
      </label>
      <label className="field">
        <span>Must start within (days of buying)</span>
        <input name="start_within_days" type="number" min={1} max={90} required defaultValue={p?.start_within_days ?? 30} />
      </label>
    </>
  );
}

export default async function MoviePage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const me = await requireUser();
  if (!can(me, 'movies.manage')) return <Forbidden />;

  const id = Number((await params).id);
  if (!Number.isInteger(id) || id <= 0) notFound();
  const justCreated = (await searchParams).created === '1';
  const [result, error] = await load(api<{ data: Movie }>(`admin/movies/${id}`));
  if (error?.status === 404) notFound();
  const movie = result?.data;
  const hasVideo = movie?.assets.some((a) => a.kind === 'feature' && a.status === 'ready');
  const hasPackage = movie?.rental_packages.some((p) => p.status === 'active');

  return (
    <>
      <PageHeader title={movie?.title ?? 'Movie'} subtitle={<Link href="/movies">← All movies</Link>} />
      {error && <ApiErrorPanel error={error} />}
      {movie && (
        <div className="stack-lg">
          {justCreated && <p className="alert alert-success">Draft created. Add a video and a rental package, then publish.</p>}

          <Card title="Publishing">
            <div className="row-between">
              <div>
                <Status value={movie.status} />
                {movie.published_at && <span className="small muted"> first published {dateTime(movie.published_at)}</span>}
                <ul className="checks small">
                  <li className={hasVideo ? 'ok' : 'todo'}>{hasVideo ? 'Video ready' : 'Add the movie video'}</li>
                  <li className={hasPackage ? 'ok' : 'todo'}>{hasPackage ? 'Rental package active' : 'Add an active rental package'}</li>
                </ul>
              </div>
              {movie.status === 'published' ? (
                <ActionForm action={setMovieStatus} submitLabel="Unpublish" variant="danger" inline confirm="Hide this movie from the app? Existing rentals keep working.">
                  <input type="hidden" name="id" value={movie.id} />
                  <input type="hidden" name="status" value="unpublished" />
                </ActionForm>
              ) : (
                <ActionForm action={setMovieStatus} submitLabel="Publish" inline>
                  <input type="hidden" name="id" value={movie.id} />
                  <input type="hidden" name="status" value="published" />
                </ActionForm>
              )}
            </div>
          </Card>

          <Card title="Details">
            <ActionForm action={updateMovie} submitLabel="Save details">
              <input type="hidden" name="id" value={movie.id} />
              <MovieFields movie={movie} />
            </ActionForm>
          </Card>

          <Card title="Rental packages">
            {movie.rental_packages.length > 0 && (
              <div className="stack">
                {movie.rental_packages.map((p) => (
                  <details key={p.id} className="subcard">
                    <summary>
                      <strong>{p.name}</strong> · {p.quality} · {p.watch_hours} h to watch · start within {p.start_within_days} days ·{' '}
                      {p.price.currency} {p.price.amount} <Status value={p.status} />
                    </summary>
                    <ActionForm action={updatePackage} submitLabel="Save package">
                      <input type="hidden" name="id" value={movie.id} />
                      <input type="hidden" name="package_id" value={p.id} />
                      <div className="form-grid">
                        <PackageFields p={p} />
                        <label className="field">
                          <span>On sale</span>
                          <select name="status" defaultValue={p.status}>
                            <option value="active">Active</option>
                            <option value="inactive">Off sale</option>
                          </select>
                        </label>
                      </div>
                    </ActionForm>
                  </details>
                ))}
              </div>
            )}
            <details className="subcard" open={movie.rental_packages.length === 0}>
              <summary>Add a package</summary>
              <ActionForm action={addPackage} submitLabel="Add package">
                <input type="hidden" name="id" value={movie.id} />
                <div className="form-grid">
                  <PackageFields />
                </div>
              </ActionForm>
            </details>
          </Card>

          <Card title="Videos">
            {movie.assets.length === 0 ? (
              <p className="muted">No video yet.</p>
            ) : (
              <table className="table table-compact">
                <thead>
                  <tr>
                    <th>Kind</th>
                    <th>Provider</th>
                    <th>Length</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {movie.assets.map((a) => (
                    <tr key={a.id}>
                      <td>{humanize(a.kind)}</td>
                      <td>{humanize(a.provider)}</td>
                      <td>{a.duration_seconds ? `${Math.round(a.duration_seconds / 60)} min` : '—'}</td>
                      <td>
                        <Status value={a.status} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
            <details className="subcard" open={!hasVideo}>
              <summary>Add a video</summary>
              <p className="small muted">
                Until the client picks a video service, use the “Sample” provider: it plays a public test video for every movie. Put any
                label in the video ID.
              </p>
              <ActionForm action={addAsset} submitLabel="Add video">
                <input type="hidden" name="id" value={movie.id} />
                <div className="form-grid">
                  <label className="field">
                    <span>Kind</span>
                    <select name="kind" defaultValue="feature">
                      <option value="feature">Full movie</option>
                      <option value="trailer">Trailer</option>
                    </select>
                  </label>
                  <label className="field">
                    <span>Provider</span>
                    <select name="provider" defaultValue="sample">
                      <option value="sample">Sample (test video)</option>
                    </select>
                  </label>
                  <label className="field">
                    <span>Video ID at the provider</span>
                    <input name="provider_asset_id" required maxLength={500} defaultValue={`sample-${movie.slug}`} />
                  </label>
                  <label className="field">
                    <span>Length (minutes)</span>
                    <input name="duration_minutes" type="number" min={1} defaultValue={movie.runtime_minutes ?? ''} />
                  </label>
                </div>
              </ActionForm>
            </details>
          </Card>
        </div>
      )}
    </>
  );
}
