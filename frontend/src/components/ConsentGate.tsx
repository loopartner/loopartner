'use client';

import Link from 'next/link';
import { useCallback, useEffect, useState } from 'react';
import { api, ApiError } from '@/lib/api';
import { useSession } from '@/lib/session';
import type { LegalDocumentSummary } from '@/lib/types';

interface LegalState {
  consent_required: boolean;
  documents: Array<LegalDocumentSummary & { accepted: boolean }>;
}

/**
 * Nouvelle version des CGU ou de la politique de confidentialité (décision 14) : l'API répond
 * 403 `consent_required` à toute route connectée. Cette fenêtre s'ouvre alors, montre les
 * documents à lire et enregistre l'acceptation (`POST /me/legal/accept`).
 */
export function ConsentGate() {
  const { refresh, logout } = useSession();
  const [state, setState] = useState<LegalState | null>(null);
  const [checked, setChecked] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    try {
      const response = await api<{ data: LegalState }>('me/legal');
      if (response.data.consent_required) setState(response.data);
    } catch {
      // Sans session, rien à demander.
    }
  }, []);

  useEffect(() => {
    const onRequired = () => void load();
    window.addEventListener('loopartner:consent-required', onRequired);
    return () => window.removeEventListener('loopartner:consent-required', onRequired);
  }, [load]);

  if (state === null) return null;

  const pending = state.documents.filter((document) => !document.accepted);

  async function accept() {
    if (state === null) return;
    setSaving(true);
    setError(null);
    try {
      const documents = Object.fromEntries(state.documents.map((document) => [document.type, document.version]));
      await api('me/legal/accept', { method: 'POST', body: { documents } });
      setState(null);
      await refresh();
      window.location.reload();
    } catch (exception) {
      setError(exception instanceof ApiError ? exception.message : 'Enregistrement impossible.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="consent-title"
      style={{ position: 'fixed', inset: 0, background: 'rgba(15, 23, 42, 0.45)', display: 'grid', placeItems: 'center', zIndex: 50, padding: 16 }}
    >
      <div className="auth-card">
        <h2 id="consent-title" className="auth-card__title">
          Nos conditions ont changé
        </h2>
        <p className="auth-card__subtitle">Pour continuer à utiliser Loopartner, lisez et acceptez les nouvelles versions.</p>
        <ul className="form-stack" style={{ listStyle: 'none', padding: 0, margin: '0 0 24px' }}>
          {pending.map((document) => (
            <li key={document.type}>
              <Link className="link-arrow" href={`/legal/${document.type}`} target="_blank">
                {document.title} (version {document.version})
              </Link>
            </li>
          ))}
        </ul>
        <label className="checkbox" style={{ marginBottom: 20 }}>
          <input type="checkbox" checked={checked} onChange={(event) => setChecked(event.target.checked)} />
          <span>J&apos;ai lu et j&apos;accepte ces documents.</span>
        </label>
        {error ? <p className="alert alert--error">{error}</p> : null}
        <button type="button" className="btn btn--lime btn--block btn--lg" disabled={!checked || saving} onClick={accept}>
          {saving ? 'Enregistrement…' : 'Accepter et continuer'}
        </button>
        <button
          type="button"
          className="btn btn--ghost btn--block"
          style={{ marginTop: 8 }}
          onClick={async () => {
            await logout();
            setState(null);
          }}
        >
          Me déconnecter
        </button>
      </div>
    </div>
  );
}
