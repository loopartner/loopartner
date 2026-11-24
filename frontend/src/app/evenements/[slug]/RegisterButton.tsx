'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { api, ApiError } from '@/lib/api';
import { useSession } from '@/lib/session';
import type { PublicEvent } from '@/lib/types';

interface Registration {
  uuid: string;
  event: { online_url?: string | null };
}

/**
 * Inscription à un événement gratuit (`POST /events/{slug}/registration`) ou lien vers la
 * billetterie externe. Le lien de connexion n'apparaît qu'aux inscrits, jamais dans un email.
 */
export function RegisterButton({ event }: { event: PublicEvent }) {
  const { user, loading } = useSession();
  const [registered, setRegistered] = useState<Registration | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!user || event.registration.mode !== 'internal') return;
    api<{ data: Registration }>(`me/event-registrations/${event.slug}`)
      .then((response) => setRegistered(response.data))
      .catch(() => setRegistered(null));
  }, [user, event.slug, event.registration.mode]);

  if (event.registration.mode === 'external') {
    return event.ticket_url ? (
      <a href={event.ticket_url} target="_blank" rel="noopener noreferrer nofollow" className="btn btn--dark btn--block btn--lg">
        Réserver sur la billetterie
      </a>
    ) : null;
  }

  if (loading) return <div className="skeleton" style={{ height: 56 }} />;

  if (!user) {
    return (
      <Link href={`/connexion?next=/evenements/${event.slug}`} className="btn btn--dark btn--block btn--lg">
        Se connecter pour s&apos;inscrire
      </Link>
    );
  }

  if (registered) {
    return (
      <div className="form-stack" style={{ gap: 12 }}>
        <p className="alert alert--success">Vous êtes inscrit. Un email de confirmation vous a été envoyé.</p>
        {registered.event.online_url ? (
          <a href={registered.event.online_url} target="_blank" rel="noopener noreferrer" className="btn btn--blue btn--block">
            Rejoindre la visioconférence
          </a>
        ) : null}
        <a href={`${process.env.NEXT_PUBLIC_API_URL ?? ''}/api/v1/me/event-registrations/${event.slug}/calendar.ics`} className="btn btn--outline btn--block">
          Ajouter à mon agenda
        </a>
        <button
          type="button"
          className="btn btn--ghost btn--block"
          disabled={busy}
          onClick={async () => {
            setBusy(true);
            setError(null);
            try {
              await api(`events/${event.slug}/registration`, { method: 'DELETE' });
              setRegistered(null);
            } catch (exception) {
              setError(exception instanceof ApiError ? exception.message : 'Désinscription impossible.');
            } finally {
              setBusy(false);
            }
          }}
        >
          Me désinscrire
        </button>
        {error ? <p className="field__error">{error}</p> : null}
      </div>
    );
  }

  const closed = !event.registration.is_open || event.registration.is_full;

  return (
    <div className="form-stack" style={{ gap: 12 }}>
      <button
        type="button"
        className="btn btn--dark btn--block btn--lg"
        disabled={busy || closed}
        onClick={async () => {
          setBusy(true);
          setError(null);
          try {
            await api(`events/${event.slug}/registration`, { method: 'POST' });
            const response = await api<{ data: Registration }>(`me/event-registrations/${event.slug}`);
            setRegistered(response.data);
          } catch (exception) {
            setError(
              exception instanceof ApiError && exception.status === 403 && !user.email_verified
                ? "Vérifiez d'abord votre adresse email (lien reçu à l'inscription)."
                : exception instanceof ApiError
                  ? exception.message
                  : 'Inscription impossible.',
            );
          } finally {
            setBusy(false);
          }
        }}
      >
        {event.registration.is_full ? 'Complet' : closed ? 'Inscriptions fermées' : "S'inscrire"}
      </button>
      <p className="field__hint">L&apos;organisateur verra votre nom et votre type de compte.</p>
      {error ? <p className="field__error">{error}</p> : null}
    </div>
  );
}
