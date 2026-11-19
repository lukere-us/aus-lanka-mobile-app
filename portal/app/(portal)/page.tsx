import { redirect } from 'next/navigation';
import { PageHeader } from '@/components/ui';
import { NAV } from '@/lib/nav';
import { can, requireUser } from '@/lib/session';

/** Home: staff with reports go to the dashboard; everyone else gets a short welcome. */
export default async function Home() {
  const user = await requireUser();
  if (can(user, 'reports.view')) redirect('/dashboard');
  const first = NAV.flatMap((g) => g.items).find((i) => i.anyOf.some((p) => can(user, p)) && (!i.roles || i.roles.some((r) => user.roles.includes(r))));
  if (first) redirect(first.href);

  return (
    <>
      <PageHeader title={`Welcome, ${user.name}`} />
      <div className="card">
        <p>There’s nothing for your role here yet.</p>
      </div>
    </>
  );
}
