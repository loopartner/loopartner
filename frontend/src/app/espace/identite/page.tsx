import type { Metadata } from 'next';
import { MemberGate } from '@/components/MemberGate';
import { PageShell } from '@/components/PageShell';
import { SellerProfileForm } from './SellerProfileForm';

export const metadata: Metadata = { title: 'Mon identité professionnelle' };

export default function SellerIdentityPage() {
  return (
    <PageShell title="Mon identité professionnelle" subtitle="Obligatoire pour publier des offres et envoyer des devis.">
      <MemberGate roles={['prestataire']}>
        <SellerProfileForm />
      </MemberGate>
    </PageShell>
  );
}
