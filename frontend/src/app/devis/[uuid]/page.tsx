import type { Metadata } from 'next';
import { Suspense } from 'react';
import { MemberGate } from '@/components/MemberGate';
import { PageShell } from '@/components/PageShell';
import { QuoteDetail } from './QuoteDetail';

export const metadata: Metadata = { title: 'Devis' };

export default async function QuotePage({ params }: { params: Promise<{ uuid: string }> }) {
  const { uuid } = await params;
  return (
    <PageShell title="Devis">
      <MemberGate verified>
        <Suspense><QuoteDetail uuid={uuid} /></Suspense>
      </MemberGate>
    </PageShell>
  );
}
