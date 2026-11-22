'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { Icon } from '@/components/Icon';
import { api, ApiError } from '@/lib/api';
import { formatBudget, formatDate } from '@/lib/format';
import { useSession } from '@/lib/session';
import type { OwnProject, Paginated } from '@/lib/types';

const STATUS_LABELS: Record<OwnProject['status'], string> = { draft: 'Brouillon', published: 'Publié', closed: 'Clos' };

/** Tableau de bord minimal : email à vérifier, projets du porteur, raccourcis selon le rôle. */
export function Dashboard() {
  const { user, loading, hasRole } = useSession();

  if (loading) return <div className="skeleton" style={{ height: 200 }} />;

  if (!user) {
    return (
      <div className="card empty">
        <p>Connectez-vous pour accéder à votre espace.</p>
        <Link href="/connexion?next=/espace" className="btn btn--lime">
          Se connecter
        </Link>
      </div>
    );
  }

  return (
    <div className="form-stack" style={{ gap: 32 }}>
      {!user.email_verified ? <VerifyEmailNotice /> : null}
      {user.two_factor_required && !user.two_factor_enabled ? (
        <p className="alert alert--error">
          La double authentification est obligatoire pour l&apos;équipe Loopartner. Activez-la avant d&apos;utiliser les fonctions d&apos;administration.
        </p>
      ) : null}

      <div className="card-grid card-grid--3">
        <Shortcut href="/messages" title="Mes messages" text="Vos conversations avec les prestataires et les porteurs de projets." />
        <Shortcut href="/demandes" title="Mes demandes" text="Propositions et demandes de contact reçues ou envoyées." />
        {hasRole('particulier', 'entrepreneur') ? (
          <Shortcut href="/publier" title="Publier un projet" text="Décrivez votre besoin et recevez des propositions." />
        ) : null}
        {hasRole('prestataire') ? <Shortcut href="/projets" title="Voir les projets" text="Répondez aux projets qui vous correspondent." /> : null}
        {hasRole('prestataire') ? <Shortcut href="/espace/profil" title="Mon profil" text="Votre page publique : présentation, catégories, logo." /> : null}
        {hasRole('prestataire') ? <Shortcut href="/espace/offres" title="Mes offres" text="Vos prestations et leur prix indicatif." /> : null}
        {hasRole('prestataire') ? <Shortcut href="/espace/identite" title="Identité professionnelle" text="Obligatoire pour publier des offres et envoyer des devis." /> : null}
        {hasRole('prestataire', 'entrepreneur', 'redacteur', 'admin') ? (
          <Shortcut href="/articles/rediger" title="Rédiger un article" text="Partagez votre expertise avec la communauté." />
        ) : null}
        {hasRole('prestataire', 'entrepreneur', 'redacteur', 'admin') ? (
          <Shortcut href="/mes-evenements" title="Mes webinars" text="Proposez et suivez vos événements." />
        ) : null}
        <Shortcut href="/evenements" title="Agenda des événements" text="Inscrivez-vous aux prochains webinars." />
      </div>

      {hasRole('particulier', 'entrepreneur') ? <OwnProjects /> : null}
    </div>
  );
}

function Shortcut({ href, title, text }: { href: string; title: string; text: string }) {
  return (
    <Link href={href} className="card aside-card" style={{ display: 'block' }}>
      <h3>{title}</h3>
      <p style={{ marginBottom: 12 }}>{text}</p>
      <span className="link-arrow">
        Ouvrir <Icon name="arrowRight" size={16} />
      </span>
    </Link>
  );
}

function VerifyEmailNotice() {
  const [sent, setSent] = useState<string | null>(null);

  return (
    <div className="alert alert--info" style={{ display: 'flex', justifyContent: 'space-between', gap: 16, alignItems: 'center', flexWrap: 'wrap' }}>
      <span>{sent ?? 'Vérifiez votre adresse email pour publier un projet, répondre aux projets ou écrire un article.'}</span>
      {sent ? null : (
        <button
          type="button"
          className="btn btn--dark"
          onClick={async () => {
            try {
              await api('auth/email/verification-notification', { method: 'POST' });
              setSent('Un nouveau lien vient de partir. Il est valable 60 minutes.');
            } catch (error) {
              setSent(error instanceof ApiError ? error.message : "L'envoi a échoué.");
            }
          }}
        >
          Renvoyer le lien
        </button>
      )}
    </div>
  );
}

function OwnProjects() {
  const [projects, setProjects] = useState<OwnProject[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = () =>
    api<Paginated<OwnProject>>('me/projects', { query: { per_page: 50 } })
      .then((response) => setProjects(response.data))
      .catch((exception: unknown) => setError(exception instanceof ApiError ? exception.message : 'Chargement impossible.'));

  useEffect(() => {
    api<Paginated<OwnProject>>('me/projects', { query: { per_page: 50 } })
      .then((response) => setProjects(response.data))
      .catch((exception: unknown) => setError(exception instanceof ApiError ? exception.message : 'Chargement impossible.'));
  }, []);

  async function action(uuid: string, verb: 'publish' | 'close') {
    setError(null);
    try {
      await api(`me/projects/${uuid}/${verb}`, { method: 'POST' });
      await load();
    } catch (exception) {
      setError(exception instanceof ApiError ? exception.message : 'Action impossible.');
    }
  }

  return (
    <section className="card" aria-labelledby="own-projects">
      <h2 id="own-projects" className="section-title" style={{ fontSize: 22 }}>
        Mes projets
      </h2>
      {error ? <p className="alert alert--error">{error}</p> : null}
      {projects === null ? (
        <div className="skeleton" style={{ height: 80 }} />
      ) : projects.length === 0 ? (
        <p className="empty">
          Vous n&apos;avez pas encore de projet. <Link href="/publier">Publier un projet</Link>
        </p>
      ) : (
        <table className="table">
          <thead>
            <tr>
              <th scope="col">Projet</th>
              <th scope="col">Budget</th>
              <th scope="col">Statut</th>
              <th scope="col">
                <span className="visually-hidden">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {projects.map((project) => (
              <tr key={project.uuid}>
                <td>
                  <strong>{project.title}</strong>
                  <br />
                  <small style={{ color: 'var(--muted)' }}>
                    {project.published_at ? `Publié le ${formatDate(project.published_at)}` : 'Jamais publié'}
                    {project.expires_at && project.status === 'published' ? ` · visible jusqu'au ${formatDate(project.expires_at)}` : ''}
                  </small>
                </td>
                <td>{formatBudget(project.budget_min, project.budget_max, project.currency) ?? '—'}</td>
                <td>
                  <span className={`status status--${project.status}`}>{STATUS_LABELS[project.status]}</span>
                </td>
                <td style={{ textAlign: 'right' }}>
                  {project.status === 'published' ? (
                    <>
                      <Link href={`/projets/${project.uuid}`} className="btn btn--ghost">
                        Voir
                      </Link>
                      <button type="button" className="btn btn--outline" onClick={() => action(project.uuid, 'close')}>
                        Clore
                      </button>
                    </>
                  ) : (
                    <button type="button" className="btn btn--lime" onClick={() => action(project.uuid, 'publish')}>
                      Publier
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
