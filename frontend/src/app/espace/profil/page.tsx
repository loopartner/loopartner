import type { Metadata } from 'next';
import { MemberGate } from '@/components/MemberGate';
import { PageShell } from '@/components/PageShell';
import { ProviderProfileForm } from './ProviderProfileForm';

export const metadata: Metadata = { title: 'Mon profil prestataire' };

export default function ProviderProfilePage() {
  return (
    <PageShell title="Mon profil prestataire" subtitle="Quatre étapes pour une page qui donne envie : photo, bannière, présentation et domaines.">
      <MemberGate roles={['prestataire']}>
        <ProviderProfileForm />
      </MemberGate>
    </PageShell>
  );
}
