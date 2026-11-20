import type { Metadata } from 'next';
import { Suspense } from 'react';
import { MemberGate } from '@/components/MemberGate';
import { Highlight, PageShell } from '@/components/PageShell';
import { RequestsBoard } from './RequestsBoard';

export const metadata: Metadata = { title: 'Mes demandes' };

export default function RequestsPage() {
  return (
    <PageShell
      title={
        <>
          Mes <Highlight>demandes</Highlight>
        </>
      }
      subtitle="Propositions et demandes de contact : acceptez-les pour ouvrir une conversation."
    >
      <MemberGate verified>
        <Suspense>
          <RequestsBoard />
        </Suspense>
      </MemberGate>
    </PageShell>
  );
}
