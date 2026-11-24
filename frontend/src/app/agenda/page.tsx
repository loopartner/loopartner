import type { Metadata } from 'next';
import { Suspense } from 'react';
import { PageShell } from '@/components/PageShell';
import { AgendaView } from './AgendaView';

export const metadata: Metadata = { title: 'Mon agenda' };

export default function AgendaPage() {
  return (
    <PageShell title="Mon agenda" subtitle="Vos rendez-vous, événements, échéances et étapes de projet, au même endroit.">
      <Suspense>
        <AgendaView />
      </Suspense>
    </PageShell>
  );
}
