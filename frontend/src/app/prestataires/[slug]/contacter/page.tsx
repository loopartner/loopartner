import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { MemberGate } from '@/components/MemberGate';
import { PageShell } from '@/components/PageShell';
import { apiOrNull } from '@/lib/api';
import type { ProviderProfile } from '@/lib/types';
import { ContactForm } from './ContactForm';

export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: 'Contacter un prestataire', robots: { index: false } };

export default async function ContactProviderPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const response = await apiOrNull<{ data: ProviderProfile }>(`providers/${encodeURIComponent(slug)}`);
  if (!response) notFound();
  const provider = response.data;

  return (
    <PageShell title={`Contacter ${provider.display_name}`} subtitle={provider.headline ?? undefined}>
      <MemberGate verified roles={['particulier', 'entrepreneur']}>
        <ContactForm provider={provider} />
      </MemberGate>
    </PageShell>
  );
}
