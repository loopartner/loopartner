import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { PageShell } from '@/components/PageShell';
import { apiOrNull } from '@/lib/api';
import { formatDate } from '@/lib/format';

export const dynamic = 'force-dynamic';

const TITLES: Record<string, string> = {
  terms: "Conditions générales d'utilisation",
  privacy: 'Politique de confidentialité',
};

interface LegalDocument {
  type: string;
  version: string;
  title: string;
  effective_at: string;
  body: string;
}

export async function generateMetadata({ params }: { params: Promise<{ type: string }> }): Promise<Metadata> {
  const { type } = await params;
  return { title: TITLES[type] ?? 'Document' };
}

/** Texte en vigueur des CGU ou de la politique de confidentialité (texte brut, versionné). */
export default async function LegalPage({ params, searchParams }: { params: Promise<{ type: string }>; searchParams: Promise<{ version?: string }> }) {
  const { type } = await params;
  const { version } = await searchParams;
  if (!(type in TITLES)) notFound();

  const response = await apiOrNull<{ data: LegalDocument }>(`legal/documents/${type}`, { query: { version }, revalidate: 300 });
  const document = response?.data ?? null;

  return (
    <PageShell title={document?.title ?? TITLES[type]} subtitle={document ? `Version ${document.version}, en vigueur depuis le ${formatDate(document.effective_at)}` : undefined}>
      <article className="card prose" style={{ maxWidth: 860 }}>
        {document ? <p>{document.body}</p> : <p>Ce document n&apos;est pas encore publié.</p>}
      </article>
    </PageShell>
  );
}
