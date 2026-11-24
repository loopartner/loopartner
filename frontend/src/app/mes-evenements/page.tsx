import type { Metadata } from 'next';
import { Suspense } from 'react';
import { MemberGate } from '@/components/MemberGate';
import { Highlight, PageShell } from '@/components/PageShell';
import { MyEvents } from './MyEvents';

export const metadata: Metadata = { title: 'Mes webinars' };

export default function MyEventsPage() {
  return (
    <PageShell
      title={
        <>
          Mes <Highlight>webinars</Highlight>
        </>
      }
    >
      {/* Organisateurs seulement (EventPolicy) : prestataires, entrepreneurs et équipe. */}
      <MemberGate roles={['prestataire', 'entrepreneur', 'redacteur', 'admin']}>
        <Suspense>
          <MyEvents />
        </Suspense>
      </MemberGate>
    </PageShell>
  );
}
