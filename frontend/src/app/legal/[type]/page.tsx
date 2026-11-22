import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { LegalText } from '@/components/LegalText';
import { PageShell } from '@/components/PageShell';
import { LEGAL_TEXTS, LEGAL_TEXTS_ARE_DRAFTS, type LegalTextType } from '@/content/legal';
import { apiOrNull } from '@/lib/api';
import { DEMO_MODE } from '@/lib/demo';
import { formatDate } from '@/lib/format';

export const dynamic = 'force-dynamic';

/** CGU et politique : versionnées dans l'API (acceptation obligatoire). Les autres : pages du site. */
const VERSIONED = new Set<LegalTextType>(['terms', 'privacy']);

const RELATED: { type: LegalTextType; label: string }[] = [
  { type: 'terms', label: "Conditions générales d'utilisation" },
  { type: 'sales', label: 'Conditions générales de vente Premium' },
  { type: 'privacy', label: 'Politique de confidentialité' },
  { type: 'cookies', label: 'Cookies et traceurs' },
  { type: 'notice', label: 'Mentions légales' },
];

interface LegalDocument {
  type: string;
  version: string;
  title: string;
  effective_at: string;
  body: string;
}

function isLegalType(type: string): type is LegalTextType {
  return Object.prototype.hasOwnProperty.call(LEGAL_TEXTS, type);
}

export async function generateMetadata({ params }: { params: Promise<{ type: string }> }): Promise<Metadata> {
  const { type } = await params;
  return { title: isLegalType(type) ? LEGAL_TEXTS[type].title : 'Document' };
}

export default async function LegalPage({ params, searchParams }: { params: Promise<{ type: string }>; searchParams: Promise<{ version?: string }> }) {
  const { type } = await params;
  const { version } = await searchParams;
  if (!isLegalType(type)) notFound();

  const draft = LEGAL_TEXTS[type];
  let published: LegalDocument | null = null;
  if (VERSIONED.has(type) && !DEMO_MODE) {
    const response = await apiOrNull<{ data: LegalDocument }>(`legal/documents/${type}`, { query: { version }, revalidate: 300 });
    published = response?.data ?? null;
  }
  const showDraftNotice = !published && LEGAL_TEXTS_ARE_DRAFTS;

  return (
    <PageShell
      title={published?.title ?? draft.title}
      subtitle={published ? `Version ${published.version}, en vigueur depuis le ${formatDate(published.effective_at)}` : `Projet de texte, version ${draft.version}`}
    >
      <div className="legal-layout">
        <article className="card legal-card">
          {showDraftNotice ? (
            <p className="alert alert--warning" role="note">
              Projet de texte en cours de validation par un juriste : il n&apos;a pas encore de valeur contractuelle et sera
              complété avant l&apos;ouverture du service. Les passages surlignés restent à compléter.
            </p>
          ) : null}
          <LegalText body={published?.body ?? draft.body} />
        </article>
        <aside className="legal-aside">
          <p className="legal-toc__title">Documents</p>
          <ul>
            {RELATED.map((item) => (
              <li key={item.type}>
                {item.type === type ? <strong>{item.label}</strong> : <Link href={`/legal/${item.type}`}>{item.label}</Link>}
              </li>
            ))}
          </ul>
        </aside>
      </div>
    </PageShell>
  );
}
