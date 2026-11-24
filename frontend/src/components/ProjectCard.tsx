import Link from 'next/link';
import { formatBudget, timeAgo } from '@/lib/format';
import type { PublicProject } from '@/lib/types';
import { Icon } from './Icon';

/** Carte « ticket » d'un projet publié (liste, accueil, projets similaires). */
export function ProjectCard({ project, compact = false, showOfferHint = false }: { project: PublicProject; compact?: boolean; showOfferHint?: boolean }) {
  const budget = formatBudget(project.budget_min, project.budget_max, project.currency);

  return (
    <article className="card ticket project-card" style={compact ? { padding: '32px 44px 32px 32px' } : undefined}>
      <div className="project-card__top">
        <div className="project-card__pills">
          <span className="pill pill--status">Ouvert</span>
          {budget ? <span className="pill pill--budget">{budget}</span> : null}
        </div>
        <time className="project-card__date" dateTime={project.published_at ?? undefined}>
          {timeAgo(project.published_at)}
        </time>
      </div>
      <h2 className="project-card__title" style={compact ? { fontSize: 20, lineHeight: '28px' } : undefined}>
        <Link href={`/projets/${project.uuid}`} className="project-card__link">{project.title}</Link>
      </h2>
      {project.description && !compact ? <p className="project-card__excerpt">{project.description}</p> : null}
      <div className="project-card__meta">
        {project.remote_ok ? <span>Réalisable à distance</span> : null}
        <span>{project.country}</span>
      </div>
      <div className="project-card__footer">
        <div className="project-card__pills">{project.category ? <span className="pill pill--tag">{project.category.name}</span> : null}</div>
        <span className="link-arrow" aria-hidden="true">
          Voir le projet <Icon name="arrowRight" size={16} />
        </span>
      </div>
      {showOfferHint ? (
        <span className="project-card__hint">
          <Icon name="lock" size={12} /> Connectez-vous en tant que prestataire pour lire le détail et faire une offre
        </span>
      ) : null}
    </article>
  );
}
