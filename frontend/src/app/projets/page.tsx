import type { Metadata } from 'next';
import Link from 'next/link';
import { ProjectsAsideCtas, ProviderSignupBanner } from './RoleCtas';
import { Highlight, PageShell } from '@/components/PageShell';
import { Pagination } from '@/components/Pagination';
import { ProjectCard } from '@/components/ProjectCard';
import { apiOrNull } from '@/lib/api';
import type { Category, Paginated, PublicProject } from '@/lib/types';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: 'Tous les projets' };

/** Vitrine publique des projets (décision 71) : titre, budget, catégorie et date seulement. */
export default async function ProjectsPage({ searchParams }: { searchParams: Promise<{ page?: string; categorie?: string }> }) {
  const { page: rawPage, categorie } = await searchParams;
  const page = Math.max(1, Number.parseInt(rawPage ?? '1', 10) || 1);

  const [projects, categories] = await Promise.all([
    apiOrNull<Paginated<PublicProject>>('public/projects', { query: { page, per_page: 10, category: categorie } }),
    apiOrNull<{ data: Category[] }>('categories', { revalidate: 600 }),
  ]);

  const href = (target: number) => `/projets?${new URLSearchParams({ ...(categorie ? { categorie } : {}), page: String(target) })}`;

  return (
    <PageShell
      title={
        <>
          Tous les <Highlight>projets</Highlight>
        </>
      }
    >
      <div className="with-aside">
        <aside className="aside-stack" aria-label="Raccourcis">
          <ProjectsAsideCtas />
          {categories && categories.data.length > 0 ? (
            <nav className="card aside-card" aria-label="Filtrer par catégorie">
              <h3>Catégories</h3>
              <div className="chips">
                <Link href="/projets" className="pill pill--tag" aria-current={!categorie ? 'page' : undefined} style={!categorie ? { background: 'var(--lime)' } : undefined}>
                  Toutes
                </Link>
                {categories.data.map((category) => (
                  <Link
                    key={category.slug}
                    href={`/projets?categorie=${category.slug}`}
                    className="pill pill--tag"
                    aria-current={categorie === category.slug ? 'page' : undefined}
                    style={categorie === category.slug ? { background: 'var(--lime)' } : undefined}
                  >
                    {category.name}
                  </Link>
                ))}
              </div>
            </nav>
          ) : null}
        </aside>

        <section aria-label="Projets publiés" style={{ display: 'flex', flexDirection: 'column', gap: 32 }}>
          {projects === null ? (
            <div className="card empty">Les projets ne peuvent pas être chargés pour le moment. Réessayez dans un instant.</div>
          ) : projects.data.length === 0 ? (
            <div className="card empty">Aucun projet ouvert dans cette catégorie pour le moment.</div>
          ) : (
            projects.data.map((project, index) => (
              <div key={project.uuid} style={{ display: 'contents' }}>
                <ProjectCard project={project} showOfferHint={index === 1} />
                {index === 1 ? <ProviderSignupBanner /> : null}
              </div>
            ))
          )}
          {projects ? <Pagination current={projects.meta.current_page} last={projects.meta.last_page} href={href} /> : null}
        </section>
      </div>
    </PageShell>
  );
}
