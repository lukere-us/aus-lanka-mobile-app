import type { Metadata } from 'next';
import Link from 'next/link';
import { ActionForm } from '@/components/ActionForm';
import { Card, Forbidden, PageHeader } from '@/components/ui';
import { can, requireUser } from '@/lib/session';
import { createMovie } from '../actions';
import { MovieFields } from '../MovieFields';

export const metadata: Metadata = { title: 'Add movie' };

export default async function NewMoviePage() {
  const me = await requireUser();
  if (!can(me, 'movies.manage')) return <Forbidden />;

  return (
    <>
      <PageHeader title="Add movie" subtitle={<Link href="/movies">← All movies</Link>} />
      <Card>
        <p className="small muted">The movie starts as a draft. Add its video and rental packages on the next screen, then publish.</p>
        <ActionForm action={createMovie} submitLabel="Create draft">
          <MovieFields />
        </ActionForm>
      </Card>
    </>
  );
}
