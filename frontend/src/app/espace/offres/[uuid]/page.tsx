import type { Metadata } from 'next';
import { MemberGate } from '@/components/MemberGate';
import { PageShell } from '@/components/PageShell';
import { OfferForm } from '../OfferForm';

export const metadata: Metadata = { title: 'Modifier une offre' };

export default async function EditOfferPage({ params }: { params: Promise<{ uuid: string }> }) {
  const { uuid } = await params;
  return (
    <PageShell title="Modifier l'offre">
      <MemberGate roles={['prestataire']}>
        <OfferForm uuid={uuid} />
      </MemberGate>
    </PageShell>
  );
}
