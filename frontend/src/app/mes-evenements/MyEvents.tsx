'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import { Icon } from '@/components/Icon';
import { api, ApiError } from '@/lib/api';
import { formatDate, formatTime, timeAgo } from '@/lib/format';
import { useSession } from '@/lib/session';
import type { OwnEvent, Paginated } from '@/lib/types';

const STATUS: Record<OwnEvent['status'], string> = {
  draft: 'Brouillon',
  pending_review: 'En validation',
  published: 'Publié',
  rejected: 'Refusé',
  cancelled: 'Annulé',
  past: 'Terminé',
};

/** Tableau de bord des webinars de l'organisateur (`GET /me/events`). */
export function MyEvents() {
  const params = useSearchParams();
  const { user, loading } = useSession();
  const [events, setEvents] = useState<OwnEvent[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!user) return;
    api<Paginated<OwnEvent & { submitted_at: string | null; created_at: string; registration_limit: number | null }>>('me/events', { query: { per_page: 50 } })
      .then((response) => setEvents(response.data))
      .catch((exception: unknown) => setError(exception instanceof ApiError ? exception.message : 'Chargement impossible.'));
  }, [user]);

  if (loading) return <div className="skeleton" style={{ height: 300 }} />;
  if (!user) {
    return (
      <div className="card empty">
        <Link href="/connexion?next=/mes-evenements" className="btn btn--lime">
          Se connecter
        </Link>
      </div>
    );
  }

  const counts = {
    published: events?.filter((event) => event.status === 'published').length ?? 0,
    pending: events?.filter((event) => event.status === 'pending_review').length ?? 0,
    registrations: events?.reduce((sum, event) => sum + (event.status === 'published' ? (event.registrations_count ?? 0) : 0), 0) ?? 0,
  };

  return (
    <div className="form-stack" style={{ gap: 32 }}>
      {params.get('envoye') ? <p className="alert alert--success">Votre webinar est envoyé à l&apos;équipe. Vous recevrez un email après sa relecture.</p> : null}
      <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
        <Link href="/evenements/proposer" className="btn btn--dark">
          <Icon name="plus" size={16} /> Proposer un webinar
        </Link>
      </div>
      <div className="stats">
        <div className="card stat">
          <strong>{counts.published}</strong>
          <span>publiés</span>
        </div>
        <div className="card stat">
          <strong>{counts.pending}</strong>
          <span>en validation</span>
        </div>
        <div className="card stat">
          <strong>{counts.registrations}</strong>
          <span>inscrits aux webinars publiés</span>
        </div>
      </div>
      {error ? <p className="alert alert--error">{error}</p> : null}
      <div className="card" style={{ padding: 24 }}>
        {events === null ? (
          <div className="skeleton" style={{ height: 120 }} />
        ) : events.length === 0 ? (
          <p className="empty">Vous n&apos;avez proposé aucun webinar pour le moment.</p>
        ) : (
          <table className="table">
            <thead>
              <tr>
                <th scope="col">Statut</th>
                <th scope="col">Webinar</th>
                <th scope="col">Date</th>
                <th scope="col">Inscrits</th>
              </tr>
            </thead>
            <tbody>
              {events.map((event) => {
                const extra = event as OwnEvent & { submitted_at?: string | null; created_at?: string };
                return (
                  <tr key={event.uuid}>
                    <td>
                      <span className={`status status--${event.status}`}>{STATUS[event.status]}</span>
                    </td>
                    <td>
                      <strong>{event.title}</strong>
                      <br />
                      <small style={{ color: 'var(--muted)' }}>
                        {event.status === 'rejected' && event.rejection_reason
                          ? `Motif : ${event.rejection_reason}`
                          : event.status === 'pending_review' && extra.submitted_at
                            ? `Envoyé ${timeAgo(extra.submitted_at).toLowerCase()}`
                            : extra.created_at
                              ? `Créé le ${formatDate(extra.created_at)}`
                              : ''}
                      </small>
                    </td>
                    <td>{event.starts_at ? `${formatDate(event.starts_at, { day: 'numeric', month: 'short', year: 'numeric', timeZone: event.timezone })} à ${formatTime(event.starts_at, event.timezone)}` : 'Date non définie'}</td>
                    <td>{event.status === 'published' || event.status === 'past' ? `${event.registrations_count ?? 0} inscrits` : '—'}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
