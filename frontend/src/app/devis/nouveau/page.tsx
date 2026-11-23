import type { Metadata } from 'next';
import { Suspense } from 'react';
import { MemberGate } from '@/components/MemberGate';
import { PageShell } from '@/components/PageShell';
import { NewQuote } from './NewQuote';

export const metadata: Metadata = { title: 'Nouveau devis' };

export default function NewQuotePage() {
  return (
    <PageShell title="Nouveau devis" subtitle="Rédigez votre devis : le client le reçoit dans Loopartner et peut l'accepter en ligne.">
      <MemberGate verified roles={['prestataire']}>
        <Suspense><NewQuote /></Suspense>
      </MemberGate>
    </PageShell>
  );
}
