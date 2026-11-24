import type { Metadata } from 'next';
import { MemberGate } from '@/components/MemberGate';
import { PageShell } from '@/components/PageShell';
import { OfferForm } from '../OfferForm';

export const metadata: Metadata = { title: 'Nouvelle offre' };

export default function NewOfferPage() {
  return (
    <PageShell title="Nouvelle offre">
      <MemberGate roles={['prestataire']}>
        <OfferForm />
      </MemberGate>
    </PageShell>
  );
}
