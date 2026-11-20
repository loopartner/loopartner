'use client';

import Image from 'next/image';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useSession } from '@/lib/session';

/**
 * En-tête des pages publiques, posé sur le bandeau bleu : liens à gauche, logo au centre,
 * connexion et « Publier un projet » à droite. Connecté : espace personnel et déconnexion.
 */
export function SiteHeader() {
  const { user, loading, logout, hasRole } = useSession();
  const router = useRouter();

  const isClient = hasRole('particulier', 'entrepreneur');

  return (
    <header className="container site-header">
      <nav className="site-header__nav" aria-label="Navigation principale">
        <Link className="site-header__link" href="/projets">
          Voir les projets
        </Link>
        <Link className="site-header__link" href="/prestataires">
          Prestataires
        </Link>
        <Link className="site-header__link" href="/articles">
          Articles
        </Link>
        <Link className="site-header__link" href="/evenements">
          Événements
        </Link>
      </nav>

      <Link href="/" className="site-header__logo" aria-label="Loopartner, accueil">
        <Image src="/images/logo-white.png" alt="" width={640} height={214} priority />
      </Link>

      <div className="site-header__actions">
        {loading ? null : user ? (
          <>
            <Link className="site-header__link" href="/espace">
              Mon espace
            </Link>
            <button
              type="button"
              className="site-header__link btn btn--ghost"
              style={{ padding: 0, color: '#fff', fontWeight: 500 }}
              onClick={async () => {
                await logout();
                router.push('/');
                router.refresh();
              }}
            >
              Déconnexion
            </button>
          </>
        ) : (
          <Link className="site-header__link" href="/connexion">
            Connexion
          </Link>
        )}
        {!user || isClient ? (
          <Link className="btn btn--lime" href="/publier">
            Publier un projet
          </Link>
        ) : null}
      </div>
    </header>
  );
}
