import type { Metadata } from 'next';
import { Suspense } from 'react';
import { PageShell } from '@/components/PageShell';
import { EventForm } from './EventForm';

export const metadata: Metadata = { title: 'Proposer un webinar' };

export default function ProposeEventPage() {
  return (
    <PageShell title="Proposer un webinar" subtitle="Partagez votre expertise en direct. Chaque proposition est relue par l'équipe avant publication.">
      <Suspense>
        <EventForm />
      </Suspense>
    </PageShell>
  );
}
