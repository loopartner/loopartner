import type { Metadata } from 'next';
import { MemberGate } from '@/components/MemberGate';
import { PageShell } from '@/components/PageShell';
import { QuotesList } from './QuotesList';

export const metadata: Metadata = { title: 'Mes devis' };

export default function QuotesPage() {
  return (
    <PageShell title="Mes devis" subtitle="Les devis échangés dans vos mises en relation acceptées.">
      <MemberGate verified>
        <QuotesList />
      </MemberGate>
    </PageShell>
  );
}
