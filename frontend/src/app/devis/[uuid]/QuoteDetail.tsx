'use client';

import { useSearchParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import { api, ApiError } from '@/lib/api';
import type { Quote } from '@/lib/quotes';
import { QuoteEditor } from '../QuoteEditor';
import { QuoteView } from '../QuoteView';

/** Un devis : formulaire pour un brouillon du prestataire, lecture et réponses sinon. */
export function QuoteDetail({ uuid }: { uuid: string }) {
  const params = useSearchParams();
  const sentFlag = params.get('envoye');
  const [quote, setQuote] = useState<Quote | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    api<{ data: Quote }>(`me/quotes/${uuid}`)
      .then((response) => active && setQuote(response.data))
      .catch((exception) => active && setError(exception instanceof ApiError ? exception.message : 'Chargement impossible.'));
    return () => {
      active = false;
    };
  // Relu aussi après l'envoi (même devis, `?envoye=1`) : il n'est plus un brouillon.
  }, [uuid, sentFlag]);

  if (error) return <p className="alert alert--error">{error}</p>;
  if (!quote) return <div className="skeleton" style={{ height: 480 }} />;

  if (quote.status === 'draft' && quote.viewer_role === 'provider') return <QuoteEditor key={quote.uuid} quote={quote} />;

  return (
    <>
      {sentFlag === '1' && quote.status === 'sent' ? (
        <p className="alert alert--success" style={{ marginBottom: 16 }}>
          Devis envoyé : le client le trouve dans Loopartner et reçoit un email.
        </p>
      ) : null}
      <QuoteView quote={quote} onChanged={setQuote} />
    </>
  );
}
