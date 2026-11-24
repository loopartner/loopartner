import type { Metadata } from 'next';
import Image from 'next/image';
import Link from 'next/link';
import { Icon } from '@/components/Icon';

export const metadata: Metadata = { title: 'Projet publié' };

export default async function PublishedPage({ searchParams }: { searchParams: Promise<{ projet?: string }> }) {
  const { projet } = await searchParams;
  const uuid = projet && /^[0-9a-f-]{36}$/i.test(projet) ? projet : null;

  return (
    <div className="wizard">
      <header className="wizard__header">
        <Link href="/" aria-label="Loopartner, accueil">
          <Image src="/images/logo-blue.png" alt="" width={640} height={204} />
        </Link>
        <Link href="/" className="wizard__close" aria-label="Fermer">
          <Icon name="close" size={18} />
        </Link>
      </header>
      <main className="wizard__main" style={{ textAlign: 'center', paddingTop: 120 }}>
        <span
          style={{ width: 96, height: 96, borderRadius: '50%', background: 'var(--lime)', display: 'grid', placeItems: 'center', margin: '0 auto 32px', boxShadow: 'var(--shadow-card)' }}
        >
          <Icon name="check" size={36} />
        </span>
        <h1 className="wizard__title" style={{ fontSize: 48, lineHeight: '56px', marginBottom: 16 }}>
          Votre projet est publié !
        </h1>
        <p style={{ fontFamily: 'var(--font-text)', fontSize: 18, color: 'var(--muted)', margin: '0 0 40px' }}>
          Les prestataires vont pouvoir le consulter et vous envoyer leurs propositions. Nous vous prévenons par email dès la première.
        </p>
        <div className="form-stack" style={{ maxWidth: 464, margin: '0 auto' }}>
          <Link href={uuid ? `/espace?projet=${uuid}` : '/espace'} className="btn btn--blue btn--lg btn--block">
            Voir mon projet <Icon name="arrowRight" size={16} />
          </Link>
          <Link href="/publier" className="btn btn--outline btn--lg btn--block">
            Publier un autre projet
          </Link>
        </div>
      </main>
    </div>
  );
}
