import Link from 'next/link';
import { PageShell } from '@/components/PageShell';

export default function NotFound() {
  return (
    <PageShell title="Page introuvable">
      <div className="card empty">
        <p>Cette page n&apos;existe pas ou n&apos;est plus disponible.</p>
        <Link href="/" className="btn btn--lime">
          Retour à l&apos;accueil
        </Link>
      </div>
    </PageShell>
  );
}
