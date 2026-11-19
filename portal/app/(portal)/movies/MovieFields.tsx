import type { Movie } from '@/lib/types';

/** Movie detail inputs shared by the create and edit forms. */
export function MovieFields({ movie }: { movie?: Movie }) {
  return (
    <div className="form-grid">
      <label className="field span-2">
        <span>Title</span>
        <input name="title" required maxLength={150} defaultValue={movie?.title} />
      </label>
      <label className="field span-2">
        <span>Synopsis</span>
        <textarea name="synopsis" rows={4} maxLength={5000} defaultValue={movie?.synopsis ?? ''} />
      </label>
      <label className="field">
        <span>Release year</span>
        <input name="release_year" type="number" min={1900} max={2100} defaultValue={movie?.release_year ?? ''} />
      </label>
      <label className="field">
        <span>Runtime (minutes)</span>
        <input name="runtime_minutes" type="number" min={1} max={600} defaultValue={movie?.runtime_minutes ?? ''} />
      </label>
      <label className="field">
        <span>Classification</span>
        <input name="classification" maxLength={10} placeholder="G, PG, M, MA15+" defaultValue={movie?.classification ?? ''} />
      </label>
      <label className="field">
        <span>Genres (comma separated)</span>
        <input name="genres" placeholder="Drama, Comedy" defaultValue={movie?.genres?.join(', ') ?? ''} />
      </label>
      <label className="field">
        <span>Languages (comma separated)</span>
        <input name="languages" placeholder="Sinhala, English" defaultValue={movie?.languages?.join(', ') ?? ''} />
      </label>
      <label className="field">
        <span>Trailer link</span>
        <input name="trailer_url" type="url" defaultValue={movie?.trailer_url ?? ''} />
      </label>
      <label className="field">
        <span>Poster image link</span>
        <input name="poster_url" type="url" defaultValue={movie?.poster_url ?? ''} />
      </label>
      <label className="field">
        <span>Backdrop image link</span>
        <input name="backdrop_url" type="url" defaultValue={movie?.backdrop_url ?? ''} />
      </label>
    </div>
  );
}
