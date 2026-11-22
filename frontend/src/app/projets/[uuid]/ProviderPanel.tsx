'use client';

import Link from 'next/link';
import { useEffect, useState, type FormEvent } from 'react';
import { FormError, TextAreaField, TextField } from '@/components/Field';
import { Icon } from '@/components/Icon';
import { api, ApiError } from '@/lib/api';
import { useSession } from '@/lib/session';
import type { BrowsableProject } from '@/lib/types';

type State =
  | { kind: 'loading' }
  | { kind: 'visitor' }
  | { kind: 'not-provider' }
  | { kind: 'denied'; message: string }
  | { kind: 'ready'; project: BrowsableProject };

/**
 * Partie du détail réservée aux prestataires connectés avec un profil publié : proposition
 * (POST /projects/{uuid}/proposals). La description est publique (décision 80) ; GET
 * /projects/{uuid} sert à vérifier que le prestataire peut répondre (profil publié, accès anticipé).
 */
export function ProviderPanel({ uuid }: { uuid: string }) {
  const { user, loading, hasRole } = useSession();
  const [fetched, setFetched] = useState<State>({ kind: 'loading' });
  const [open, setOpen] = useState(false);
  const isProvider = hasRole('prestataire');

  useEffect(() => {
    if (loading || !user || !isProvider) return;
    api<{ data: BrowsableProject }>(`projects/${uuid}`)
      .then((response) => setFetched({ kind: 'ready', project: response.data }))
      .catch((error: unknown) =>
        setFetched({
          kind: 'denied',
          message:
            error instanceof ApiError && error.status === 404
              ? "Ce projet est encore réservé aux prestataires Premium pendant ses premières heures. Revenez un peu plus tard."
              : error instanceof ApiError
                ? error.message
                : 'Le détail du projet ne peut pas être chargé.',
        }),
      );
  }, [loading, user, isProvider, uuid]);

  const state: State = loading ? { kind: 'loading' } : !user ? { kind: 'visitor' } : !isProvider ? { kind: 'not-provider' } : fetched;

  if (state.kind === 'loading') {
    return <div className="skeleton" style={{ height: 140, margin: '32px 0' }} />;
  }

  if (state.kind === 'visitor' || state.kind === 'not-provider') {
    return (
      <div style={{ margin: '32px 0', borderTop: '1px solid var(--border)', paddingTop: 32 }}>
        <p className="note">
          <span className="note__icon">
            <Icon name="lock" size={16} />
          </span>
          <span>
            Seuls les prestataires inscrits peuvent répondre à ce projet.{' '}
            {state.kind === 'visitor' ? (
              <>
                <Link href={`/connexion?next=/projets/${uuid}`} style={{ color: 'var(--blue-ink)', fontWeight: 700 }}>
                  Connectez-vous
                </Link>{' '}
                ou{' '}
                <Link href="/inscription?profil=prestataire" style={{ color: 'var(--blue-ink)', fontWeight: 700 }}>
                  créez un compte prestataire
                </Link>{' '}
                pour faire une offre.
              </>
            ) : (
              'Votre compte ne permet pas de répondre aux projets.'
            )}
          </span>
        </p>
      </div>
    );
  }

  if (state.kind === 'denied') {
    return (
      <p className="alert alert--info" style={{ margin: '32px 0' }}>
        {state.message}{' '}
        {state.message.includes('profil') ? (
          <Link href="/espace" style={{ fontWeight: 700 }}>
            Mon espace
          </Link>
        ) : null}
      </p>
    );
  }

  return (
    <>
      <div style={{ display: 'flex', gap: 16, margin: '32px 0', flexWrap: 'wrap' }}>
        <button type="button" className="btn btn--dark btn--lg" style={{ flex: 1 }} onClick={() => setOpen(true)} aria-expanded={open}>
          Faire une offre
        </button>
      </div>
      {open ? <ProposalForm uuid={uuid} onClose={() => setOpen(false)} /> : null}
    </>
  );
}

function ProposalForm({ uuid, onClose }: { uuid: string; onClose: () => void }) {
  const [message, setMessage] = useState('');
  const [price, setPrice] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setError(null);
    try {
      const amount = price.trim() === '' ? null : Number.parseInt(price, 10);
      await api(`projects/${uuid}/proposals`, {
        method: 'POST',
        body: { message: message.trim(), ...(amount !== null ? { price_amount: amount, price_currency: 'EUR' } : {}) },
      });
      setSent(true);
    } catch (exception) {
      if (exception instanceof ApiError) {
        setErrors(Object.fromEntries(Object.entries(exception.errors).map(([key, messages]) => [key, messages[0]])));
        if (Object.keys(exception.errors).length === 0) setError(exception.message);
      } else {
        setError("L'envoi a échoué.");
      }
    } finally {
      setBusy(false);
    }
  }

  if (sent) {
    return (
      <p className="alert alert--success" style={{ marginBottom: 32 }}>
        Votre proposition est envoyée. Elle apparaît dans vos messages ; le porteur de projet est prévenu et vous recevrez un email dès qu&apos;il répond.
      </p>
    );
  }

  return (
    <form className="card card--flat form-stack" style={{ background: 'var(--surface-soft)', marginBottom: 32 }} onSubmit={submit} noValidate>
      <TextAreaField
        label="Votre message"
        value={message}
        onChange={(event) => setMessage(event.target.value)}
        minLength={20}
        maxLength={5000}
        counter
        required
        placeholder="Présentez-vous et expliquez comment vous pouvez aider ce porteur de projet."
        error={errors.message}
        hint="N'indiquez pas vos coordonnées : les échanges se poursuivent dans la messagerie."
      />
      <TextField
        label="Prix indicatif (facultatif, en euros)"
        type="number"
        min={1}
        inputMode="numeric"
        value={price}
        onChange={(event) => setPrice(event.target.value)}
        error={errors.price_amount}
      />
      <FormError message={error} />
      <div style={{ display: 'flex', gap: 12, justifyContent: 'flex-end' }}>
        <button type="button" className="btn btn--ghost" onClick={onClose}>
          Annuler
        </button>
        <button type="submit" className="btn btn--dark" disabled={busy || message.trim().length < 20}>
          {busy ? 'Envoi…' : 'Envoyer ma proposition'}
        </button>
      </div>
    </form>
  );
}
