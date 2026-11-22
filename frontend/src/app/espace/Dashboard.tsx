'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { CountrySelect } from '@/components/CountrySelect';
import { Dialog } from '@/components/Dialog';
import { FormError, TextField } from '@/components/Field';
import { Icon, type IconName } from '@/components/Icon';
import { MediaUpload } from '@/components/MediaUpload';
import { api, ApiError } from '@/lib/api';
import { countryName, dateBadge, formatBudget, formatDate, formatTime, initials } from '@/lib/format';
import { useSession } from '@/lib/session';
import { useUnreadCount } from '@/lib/unread';
import type { Me, OwnProject, OwnProviderProfile, Paginated } from '@/lib/types';

const STATUS_LABELS: Record<OwnProject['status'], string> = { draft: 'Brouillon', published: 'Publié', closed: 'Clos' };

const ROLE_LABELS: Record<string, string> = {
  particulier: 'Particulier',
  entrepreneur: 'Entrepreneur',
  prestataire: 'Prestataire',
  redacteur: 'Rédaction Loopartner',
  admin: 'Équipe Loopartner',
};

/**
 * Mon espace, présenté comme un profil : photo, informations et ce que les autres voient de
 * vous ; puis l'agenda des 30 prochains jours, les messages en attente et vos projets ou offres.
 */
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

  const isProvider = hasRole('prestataire');
  const isClient = hasRole('particulier', 'entrepreneur');

  return (
    <div className="form-stack" style={{ gap: 28 }}>
      {!user.email_verified ? <VerifyEmailNotice /> : null}
      {user.two_factor_required && !user.two_factor_enabled ? (
        <p className="alert alert--error">
          La double authentification est obligatoire pour l&apos;équipe Loopartner. Activez-la avant d&apos;utiliser les fonctions d&apos;administration.
        </p>
      ) : null}

      <div className="with-aside with-aside--right">
        <div className="form-stack" style={{ gap: 28 }}>
          <ProfileCard user={user} isProvider={isProvider} />
          {user.email_verified ? <AgendaCard /> : null}
          {isClient ? <OwnProjects /> : null}
        </div>
        <aside className="aside-stack">
          <MessagesCard verified={user.email_verified} />
          <div className="card aside-card">
            <h3>Raccourcis</h3>
            <nav className="shortcut-list">
              {isClient ? <Shortcut href="/publier" icon="plus" label="Publier un projet" /> : null}
              {isProvider ? <Shortcut href="/projets" icon="briefcase" label="Projets à pourvoir" /> : null}
              {isProvider ? <Shortcut href="/espace/offres" icon="wallet" label="Mes offres" /> : null}
              {isProvider ? <Shortcut href="/espace/identite" icon="shield" label="Identité professionnelle" /> : null}
              <Shortcut href="/prestataires" icon="search" label="Trouver un prestataire" />
              <Shortcut href="/evenements" icon="calendar" label="Agenda des événements" />
              {hasRole('prestataire', 'entrepreneur', 'redacteur', 'admin') ? <Shortcut href="/mes-evenements" icon="video" label="Mes webinars" /> : null}
              {hasRole('prestataire', 'entrepreneur', 'redacteur', 'admin') ? <Shortcut href="/articles/rediger" icon="book" label="Rédiger un article" /> : null}
            </nav>
          </div>
        </aside>
      </div>
    </div>
  );
}

function Shortcut({ href, icon, label }: { href: string; icon: IconName; label: string }) {
  return (
    <Link href={href}>
      <Icon name={icon} size={18} /> {label}
    </Link>
  );
}

/** Carte de profil : photo, nom, rôle, pays, et ce que les autres voient du compte. */
function ProfileCard({ user, isProvider }: { user: Me; isProvider: boolean }) {
  const { refresh, logout } = useSession();
  const router = useRouter();
  const [editing, setEditing] = useState(false);
  const [provider, setProvider] = useState<OwnProviderProfile | null | undefined>(undefined);
  const role = user.roles.map((item) => ROLE_LABELS[item]).filter(Boolean)[0] ?? '';

  useEffect(() => {
    if (!isProvider) return;
    let active = true;
    api<{ data: OwnProviderProfile }>('me/provider-profile')
      .then((response) => active && setProvider(response.data))
      .catch(() => active && setProvider(null));
    return () => {
      active = false;
    };
  }, [isProvider]);

  return (
    <section className="card profile-card">
      <div className="profile-head">
        <button type="button" className="profile-card__photo" onClick={() => setEditing(true)} aria-label="Changer ma photo">
          {user.avatar_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={user.avatar_url} alt="" />
          ) : (
            <span>{initials(user.name)}</span>
          )}
          <span className="profile-card__photo-edit">
            <Icon name="image" size={14} />
          </span>
        </button>
        <div style={{ flex: 1, minWidth: 200 }}>
          <h2 style={{ margin: '0 0 6px', fontSize: 26 }}>{user.name}</h2>
          <div className="project-card__pills">
            {role ? <span className="pill pill--tag">{role}</span> : null}
            {user.premium?.active ? <span className="pill pill--budget">Premium</span> : null}
            {user.country ? <span className="pill pill--tag">{countryName(user.country)}</span> : null}
            {user.email_verified ? <span className="badge-verified">Email vérifié</span> : null}
          </div>
          <p style={{ margin: '10px 0 0', color: 'var(--muted)', fontSize: 14 }}>{user.email}</p>
        </div>
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
          <button type="button" className="btn btn--outline btn--sm" onClick={() => setEditing(true)}>
            Modifier
          </button>
          <button
            type="button"
            className="btn btn--ghost btn--sm"
            onClick={async () => {
              await logout();
              router.push('/');
              router.refresh();
            }}
          >
            <Icon name="logout" size={16} /> Déconnexion
          </button>
        </div>
      </div>

      <div className="profile-card__public">
        <h3>Ce que les autres voient</h3>
        {isProvider ? (
          provider === undefined ? (
            <div className="skeleton" style={{ height: 60 }} />
          ) : provider === null ? (
            <p>
              Vous n&apos;avez pas encore de profil prestataire : les clients ne peuvent pas vous trouver.{' '}
              <Link href="/espace/profil">Créer mon profil</Link>
            </p>
          ) : (
            <>
              <p style={{ margin: '0 0 6px' }}>
                <strong>{provider.display_name}</strong>
                {provider.headline ? ` · ${provider.headline}` : ''}
                {provider.city ? ` · ${provider.city}` : ''}
              </p>
              {provider.bio ? <p className="project-card__excerpt">{provider.bio}</p> : null}
              <div className="project-card__pills" style={{ margin: '8px 0 14px' }}>
                {provider.categories.map((category) => (
                  <span key={category.slug} className="pill pill--tag">
                    {category.name}
                  </span>
                ))}
                <span className={`status status--${provider.status === 'published' ? 'published' : 'pending_review'}`}>
                  {provider.status === 'published' ? 'Profil publié' : 'Profil non publié'}
                </span>
              </div>
              <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                {provider.status === 'published' && provider.slug ? (
                  <Link href={`/prestataires/${provider.slug}`} className="btn btn--dark btn--sm">
                    Voir ma page publique
                  </Link>
                ) : null}
                <Link href="/espace/profil" className="btn btn--outline btn--sm">
                  Modifier mon profil prestataire
                </Link>
              </div>
            </>
          )
        ) : (
          <p>
            Les prestataires voient votre type de compte et votre pays. Votre nom ne leur est montré qu&apos;après avoir accepté leur proposition ; votre email
            et votre téléphone, jamais.
          </p>
        )}
      </div>

      {editing ? (
        <EditProfileDialog
          user={user}
          onClose={() => setEditing(false)}
          onSaved={async () => {
            await refresh();
            setEditing(false);
          }}
        />
      ) : null}
    </section>
  );
}

function EditProfileDialog({ user, onClose, onSaved }: { user: Me; onClose: () => void; onSaved: () => Promise<void> }) {
  const [name, setName] = useState(user.name);
  const [country, setCountry] = useState(user.country ?? 'FR');
  const [avatar, setAvatar] = useState<string | null | undefined>(undefined);
  const [error, setError] = useState<ApiError | null>(null);
  const [saving, setSaving] = useState(false);

  return (
    <Dialog title="Modifier mon profil" onClose={onClose}>
      <form
        className="form-stack"
        onSubmit={async (event) => {
          event.preventDefault();
          setSaving(true);
          setError(null);
          try {
            const body: Record<string, unknown> = { name: name.trim(), country };
            if (avatar !== undefined) body.avatar = avatar;
            await api('me', { method: 'PATCH', body });
            await onSaved();
          } catch (exception) {
            setError(exception instanceof ApiError ? exception : new ApiError(0, {}));
            setSaving(false);
          }
        }}
      >
        <MediaUpload purpose="avatar" label="Photo de profil" initialUrl={user.avatar_url} onUploaded={(uuid) => setAvatar(uuid)} />
        {user.avatar_url ? (
          <button type="button" className="report-link" onClick={() => setAvatar(null)}>
            Retirer ma photo
          </button>
        ) : null}
        <TextField label="Nom" value={name} onChange={(event) => setName(event.target.value)} maxLength={255} error={error?.field('name')} hint="Montré aux prestataires seulement après acceptation de leur proposition." />
        <CountrySelect id="me-country" label="Pays" value={country} onChange={setCountry} error={error?.field('country')} />
        <FormError message={error && !error.field('name') && !error.field('country') ? error.message : null} />
        <button type="submit" className="btn btn--lime btn--block" disabled={saving || name.trim().length < 2}>
          {saving ? 'Enregistrement…' : 'Enregistrer'}
        </button>
      </form>
    </Dialog>
  );
}

interface AgendaItem {
  source: string;
  kind: string;
  ref: string;
  title: string;
  all_day: boolean;
  date: string | null;
  starts_at: string;
  ends_at: string;
  status: string;
}

const SOURCE_ICONS: Record<string, IconName> = { appointments: 'calendar', events: 'video', compliance: 'file', workspaces: 'check' };
const SOURCE_LABELS: Record<string, string> = { appointments: 'Rendez-vous', events: 'Événement', compliance: 'Échéance', workspaces: 'Étape de projet' };

/** Les 30 prochains jours (GET /me/agenda) : événements, rendez-vous, échéances. */
function AgendaCard() {
  const [items, setItems] = useState<AgendaItem[] | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let active = true;
    const today = new Date();
    const to = new Date(today.getTime() + 30 * 86_400_000);
    const day = (date: Date) => date.toISOString().slice(0, 10);
    api<{ data: AgendaItem[] }>('me/agenda', { query: { from: day(today), to: day(to) } })
      .then((response) => active && setItems(response.data))
      .catch(() => active && setFailed(true));
    return () => {
      active = false;
    };
  }, []);

  return (
    <section className="card">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, marginBottom: 16 }}>
        <h2 className="section-title" style={{ fontSize: 22, margin: 0 }}>
          Mon agenda
        </h2>
        <Link href="/evenements" className="link-arrow" style={{ fontSize: 14 }}>
          Tous les événements <Icon name="arrowRight" size={14} />
        </Link>
      </div>
      {failed ? (
        <p className="empty">L&apos;agenda ne peut pas être chargé pour le moment.</p>
      ) : items === null ? (
        <div className="skeleton" style={{ height: 120 }} />
      ) : items.length === 0 ? (
        <p className="empty">Rien de prévu dans les 30 prochains jours. Inscrivez-vous à un webinar pour bien démarrer.</p>
      ) : (
        <ul className="agenda-list">
          {items.slice(0, 6).map((item) => {
            const badge = dateBadge(item.starts_at);
            return (
              <li key={`${item.source}:${item.ref}`}>
                <span className="date-badge date-badge--sm">
                  <strong>{badge.day}</strong>
                  <span>{badge.month}</span>
                </span>
                <span style={{ flex: 1, minWidth: 0 }}>
                  <strong className="agenda-list__title">{item.title}</strong>
                  <small>
                    <Icon name={SOURCE_ICONS[item.source] ?? 'calendar'} size={13} /> {SOURCE_LABELS[item.source] ?? 'Agenda'}
                    {item.all_day ? '' : ` · ${formatTime(item.starts_at)}`}
                  </small>
                </span>
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}

/** Messages non lus et propositions en attente, mis en avant en haut de l'espace. */
function MessagesCard({ verified }: { verified: boolean }) {
  const unread = useUnreadCount();
  return (
    <div className="card aside-card messages-card">
      <h3>
        <Icon name="message" size={20} /> Messages
      </h3>
      {!verified ? (
        <p>Vérifiez votre email pour échanger avec les prestataires et les clients.</p>
      ) : unread > 0 ? (
        <p>
          <strong>{unread}</strong> élément{unread > 1 ? 's' : ''} en attente : nouveaux messages ou propositions à accepter.
        </p>
      ) : (
        <p>Vous êtes à jour.</p>
      )}
      <Link href="/messages" className="btn btn--dark btn--block">
        Ouvrir la messagerie
      </Link>
    </div>
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
                      <Link href={`/projets/${project.uuid}`} className="btn btn--ghost btn--sm">
                        Voir
                      </Link>
                      <button type="button" className="btn btn--outline btn--sm" onClick={() => action(project.uuid, 'close')}>
                        Clore
                      </button>
                    </>
                  ) : (
                    <button type="button" className="btn btn--lime btn--sm" onClick={() => action(project.uuid, 'publish')}>
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
