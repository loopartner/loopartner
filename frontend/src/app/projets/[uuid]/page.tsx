import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Icon } from '@/components/Icon';
import { PageShell } from '@/components/PageShell';
import { ProjectCard } from '@/components/ProjectCard';
import { apiOrNull } from '@/lib/api';
import { countryName, formatBudget, timeAgo } from '@/lib/format';
import type { Paginated, PublicProject } from '@/lib/types';
import { ProviderPanel } from './ProviderPanel';
import { ReportButton } from '@/components/ReportButton';

export const dynamic = 'force-dynamic';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

async function loadProject(uuid: string): Promise<PublicProject | null> {
  if (!UUID.test(uuid)) return null;
  const response = await apiOrNull<{ data: PublicProject }>(`public/projects/${uuid}`);
  return response?.data ?? null;
}

export async function generateMetadata({ params }: { params: Promise<{ uuid: string }> }): Promise<Metadata> {
  const project = await loadProject((await params).uuid);
  return { title: project?.title ?? 'Projet' };
}

export default async function ProjectPage({ params }: { params: Promise<{ uuid: string }> }) {
  const { uuid } = await params;
  const project = await loadProject(uuid);
  if (project === null) notFound();

  const similar = await apiOrNull<Paginated<PublicProject>>('public/projects', {
    query: { category: project.category?.slug, per_page: 3 },
  });
  const others = (similar?.data ?? []).filter((item) => item.uuid !== project.uuid).slice(0, 2);
  const budget = formatBudget(project.budget_min, project.budget_max, project.currency);

  return (
    <PageShell title={project.title}>
      <div className="with-aside with-aside--right">
        <article className="card" style={{ padding: 40 }}>
          <div className="project-card__top">
            <div className="project-card__pills">
              <span className="pill pill--status">Ouvert</span>
              {budget ? <span className="pill pill--budget">{budget}</span> : null}
            </div>
          </div>

          {project.description ? (
            <div style={{ borderTop: '1px solid var(--border)', marginTop: 24, paddingTop: 24 }}>
              <p className="preline">{project.description}</p>
            </div>
          ) : null}

          <ProviderPanel uuid={project.uuid} />

          <div className="facts">
            <div className="fact">
              <span className="fact__icon">
                <Icon name="wallet" size={18} />
              </span>
              <span>
                <small>Budget</small>
                <strong>{budget ?? 'À définir avec vous'}</strong>
              </span>
            </div>
            <div className="fact">
              <span className="fact__icon">
                <Icon name="globe" size={18} />
              </span>
              <span>
                <small>Lieu</small>
                <strong>
                  {countryName(project.country)}
                  {project.remote_ok ? ' · à distance possible' : ''}
                </strong>
              </span>
            </div>
            <div className="fact">
              <span className="fact__icon">
                <Icon name="clock" size={18} />
              </span>
              <span>
                <small>Publication</small>
                <strong>{timeAgo(project.published_at)}</strong>
              </span>
            </div>
          </div>

          {project.category ? (
            <div className="project-card__pills" style={{ marginTop: 32 }}>
              <span className="pill pill--tag">{project.category.name}</span>
            </div>
          ) : null}
        </article>

        <aside className="aside-stack">
          <div className="card locked">
            <span className="locked__icon">
              <Icon name="lock" size={18} />
            </span>
            <p>Le nom du porteur de projet vous est révélé lorsqu&apos;il accepte votre proposition.</p>
            <Link href="/devenir-prestataire" className="btn btn--outline btn--block">
              Devenir prestataire
            </Link>
          </div>
          <div className="card aside-card">
            <h3>Vous avez un projet similaire ?</h3>
            <p>Publiez-le gratuitement et recevez des propositions de professionnels.</p>
            <Link href={`/publier${project.category ? `?categorie=${project.category.slug}` : ''}`} className="btn btn--lime btn--block">
              Publier un projet similaire
            </Link>
          </div>
          <div style={{ textAlign: 'center' }}>
            <ReportButton type="project" reference={project.uuid} label="Signaler ce projet" />
          </div>
        </aside>
      </div>

      {others.length > 0 ? (
        <section style={{ marginTop: 64 }} aria-labelledby="similar-title">
          <h2 id="similar-title" className="section-title">
            Projets similaires
          </h2>
          <div className="card-grid card-grid--2">
            {others.map((item) => (
              <ProjectCard key={item.uuid} project={item} compact />
            ))}
          </div>
        </section>
      ) : null}
    </PageShell>
  );
}
