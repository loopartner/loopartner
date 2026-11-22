import type { Metadata } from 'next';
import { PageShell } from '@/components/PageShell';
import { PremiumPlans } from './PremiumPlans';

export const metadata: Metadata = { title: 'Premium' };

export default function PremiumPage() {
  return (
    <PageShell title="Loopartner Premium" subtitle="Un seul abonnement, au même prix pour tous ; ses avantages s'adaptent à votre profil.">
      <PremiumPlans />
    </PageShell>
  );
}
