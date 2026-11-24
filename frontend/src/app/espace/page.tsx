import type { Metadata } from 'next';
import { Suspense } from 'react';
import { PageShell } from '@/components/PageShell';
import { Dashboard } from './Dashboard';

export const metadata: Metadata = { title: 'Mon espace' };

export default function SpacePage() {
  return (
    <PageShell title="Mon espace" subtitle="Votre profil, votre agenda et vos messages.">
      <Suspense>
        <Dashboard />
      </Suspense>
    </PageShell>
  );
}
