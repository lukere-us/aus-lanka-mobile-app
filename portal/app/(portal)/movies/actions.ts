'use server';

import { redirect } from 'next/navigation';
import { api, ApiError } from '@/lib/api';
import { attempt, compact, idFrom, int, list, nullableText, text, type ActionResult } from '@/lib/actions';
import type { Movie, RentalPackage } from '@/lib/types';

function movieFields(form: FormData) {
  return {
    title: text(form, 'title'),
    synopsis: nullableText(form, 'synopsis'),
    release_year: int(form, 'release_year') ?? null,
    runtime_minutes: int(form, 'runtime_minutes') ?? null,
    classification: nullableText(form, 'classification'),
    genres: list(form, 'genres'),
    languages: list(form, 'languages'),
    poster_url: nullableText(form, 'poster_url'),
    backdrop_url: nullableText(form, 'backdrop_url'),
    trailer_url: nullableText(form, 'trailer_url'),
  };
}

export async function createMovie(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  let movie: Movie;
  try {
    movie = (await api<{ data: Movie }>('admin/movies', { method: 'POST', body: movieFields(form) })).data;
  } catch (e) {
    if (e instanceof ApiError) return { error: e.message, details: e.details, at: Date.now() };
    throw e;
  }
  redirect(`/movies/${movie.id}?created=1`);
}

export async function updateMovie(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  return attempt(() => api(`admin/movies/${id}`, { method: 'PATCH', body: movieFields(form) }), 'Details saved.');
}

export async function setMovieStatus(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  const status = text(form, 'status');
  const done = status === 'published' ? 'Published. Customers can now rent it.' : 'Unpublished. It no longer shows in the app.';
  return attempt(() => api(`admin/movies/${id}`, { method: 'PATCH', body: { status } }), done);
}

export async function addAsset(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  return attempt(
    () =>
      api(`admin/movies/${id}/assets`, {
        method: 'POST',
        body: compact({
          kind: text(form, 'kind'),
          provider: text(form, 'provider'),
          provider_asset_id: text(form, 'provider_asset_id'),
          duration_seconds: int(form, 'duration_minutes') !== undefined ? int(form, 'duration_minutes')! * 60 : undefined,
        }),
      }),
    'Video added.',
  );
}

function packageFields(form: FormData): Partial<Record<keyof RentalPackage, unknown>> {
  return compact({
    name: text(form, 'name'),
    quality: text(form, 'quality'),
    price: text(form, 'price'),
    watch_hours: int(form, 'watch_hours'),
    start_within_days: int(form, 'start_within_days'),
    status: text(form, 'status'),
  });
}

export async function addPackage(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  return attempt(() => api(`admin/movies/${id}/packages`, { method: 'POST', body: packageFields(form) }), 'Rental package added.');
}

export async function updatePackage(_prev: ActionResult, form: FormData): Promise<ActionResult> {
  const id = idFrom(form);
  const packageId = idFrom(form, 'package_id');
  return attempt(() => api(`admin/movies/${id}/packages/${packageId}`, { method: 'PATCH', body: packageFields(form) }), 'Package saved.');
}
