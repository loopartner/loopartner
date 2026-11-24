'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { QuoteEditor } from '../QuoteEditor';

/** Nouveau devis dans une demande de mise en relation acceptée (`?demande=<uuid>`, `nom` facultatif). */
export function NewQuote() {
  const params = useSearchParams();
  const matchRequest = params.get('demande');

  if (!matchRequest || !/^[0-9a-f-]{36}$/i.test(matchRequest)) {
    return (
      <div className="card empty">
        <p>Un devis se rédige depuis une conversation avec un client : ouvrez-la dans vos messages, puis cliquez sur « Faire un devis ».</p>
        <Link href="/messages" className="btn btn--lime">
          Aller aux messages
        </Link>
      </div>
    );
  }

  return <QuoteEditor matchRequest={matchRequest} clientName={params.get('nom')?.slice(0, 160) ?? undefined} />;
}
