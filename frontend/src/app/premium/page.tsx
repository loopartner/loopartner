import type { Metadata } from 'next';
import { PageShell } from '@/components/PageShell';
import { PremiumPlans } from './PremiumPlans';

export const metadata: Metadata = { title: 'Premium' };

export default function PremiumPage() {
  return (
    <PageShell title="Loopartner Premium" subtitle="L'abonnement des prestataires pour trouver plus de clients.">
      <PremiumPlans />
    </PageShell>
  );
}
