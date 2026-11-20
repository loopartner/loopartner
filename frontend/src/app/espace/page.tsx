import type { Metadata } from 'next';
import { Suspense } from 'react';
import { PageShell } from '@/components/PageShell';
import { Dashboard } from './Dashboard';

export const metadata: Metadata = { title: 'Mon espace' };

export default function SpacePage() {
  return (
    <PageShell title="Mon espace" subtitle="Vos projets, vos articles et vos événements.">
      <Suspense>
        <Dashboard />
      </Suspense>
    </PageShell>
  );
}
