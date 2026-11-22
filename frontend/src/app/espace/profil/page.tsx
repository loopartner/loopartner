import type { Metadata } from 'next';
import { MemberGate } from '@/components/MemberGate';
import { PageShell } from '@/components/PageShell';
import { ProviderProfileForm } from './ProviderProfileForm';

export const metadata: Metadata = { title: 'Mon profil prestataire' };

export default function ProviderProfilePage() {
  return (
    <PageShell title="Mon profil prestataire" subtitle="Votre page publique : présentation, catégories et logo.">
      <MemberGate roles={['prestataire']}>
        <ProviderProfileForm />
      </MemberGate>
    </PageShell>
  );
}
