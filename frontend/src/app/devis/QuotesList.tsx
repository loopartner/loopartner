'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { Icon } from '@/components/Icon';
import { api, ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { formatCents, type Quote, STATUS_CLASS, STATUS_LABELS } from '@/lib/quotes';
import { useSession } from '@/lib/session';
import type { Paginated } from '@/lib/types';

/** Devis rédigés (prestataire, `box=sent`) ou reçus (porteur de projet, `box=received`). */
export function QuotesList() {
  const { hasRole } = useSession();
  const isProvider = hasRole('prestataire');
  const [quotes, setQuotes] = useState<Quote[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    api<Paginated<Quote>>('me/quotes', { query: { box: isProvider ? 'sent' : 'received', per_page: 50 } })
      .then((response) => active && setQuotes(response.data))
      .catch((exception) => active && setError(exception instanceof ApiError ? exception.message : 'Chargement impossible.'));
    return () => {
      active = false;
    };
  }, [isProvider]);

  if (error) return <p className="alert alert--error">{error}</p>;
  if (!quotes) return <div className="skeleton" style={{ height: 240 }} />;

  if (quotes.length === 0) {
    return (
      <div className="card empty">
        {isProvider ? (
          <p>
            Aucun devis pour l&apos;instant. Ouvrez une conversation avec un client dans vos messages, puis cliquez sur « Faire un devis ».
          </p>
        ) : (
          <p>Aucun devis reçu pour l&apos;instant. Les prestataires avec qui vous échangez peuvent vous en envoyer depuis la messagerie.</p>
        )}
        <Link href="/messages" className="btn btn--lime">
          Aller aux messages
        </Link>
      </div>
    );
  }

  return (
    <div className="card" style={{ padding: 0, overflowX: 'auto' }}>
      <table className="table">
        <thead>
          <tr>
            <th scope="col">Devis</th>
            <th scope="col">{isProvider ? 'Client' : 'Prestataire'}</th>
            <th scope="col">Montant TTC</th>
            <th scope="col">Statut</th>
            <th scope="col">
              <span className="visually-hidden">Ouvrir</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {quotes.map((quote) => (
            <tr key={quote.uuid}>
              <td>
                <strong>{quote.title}</strong>
                <small style={{ display: 'block', color: 'var(--muted)' }}>
                  {quote.number ?? 'Brouillon'}
                  {quote.sent_at ?? quote.updated_at ? ` · ${formatDate((quote.sent_at ?? quote.updated_at) as string)}` : ''}
                </small>
              </td>
              <td>{isProvider ? (quote.client?.name ?? '—') : quote.provider?.deleted ? 'Compte supprimé' : (quote.provider?.display_name ?? '—')}</td>
              <td>{formatCents(quote.totals?.ttc_cents, quote.currency)}</td>
              <td>
                <span className={`status ${STATUS_CLASS[quote.status]}`}>{STATUS_LABELS[quote.status]}</span>
              </td>
              <td style={{ textAlign: 'right' }}>
                <Link href={`/devis/${quote.uuid}`} className="btn btn--ghost btn--sm">
                  {quote.status === 'draft' ? 'Modifier' : 'Voir'} <Icon name="arrowRight" size={14} />
                </Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
