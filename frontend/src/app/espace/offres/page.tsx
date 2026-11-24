import type { Metadata } from 'next';
import { MemberGate } from '@/components/MemberGate';
import { PageShell } from '@/components/PageShell';
import { OwnOffers } from './OwnOffers';

export const metadata: Metadata = { title: 'Mes offres' };

export default function OwnOffersPage() {
  return (
    <PageShell title="Mes offres" subtitle="Vos prestations publiées dans le catalogue des offres.">
      <MemberGate roles={['prestataire']}>
        <OwnOffers />
      </MemberGate>
    </PageShell>
  );
}
