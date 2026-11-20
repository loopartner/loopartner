'use client';

import Image from 'next/image';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { useSession } from '@/lib/session';
import { useUnreadCount } from '@/lib/unread';
import { Icon } from './Icon';

const NAV = [
  { href: '/projets', label: 'Voir les projets' },
  { href: '/prestataires', label: 'Prestataires' },
  { href: '/articles', label: 'Articles' },
  { href: '/evenements', label: 'Événements' },
];

/**
 * En-tête des pages publiques, posé sur le bandeau bleu : liens à gauche, logo au centre,
 * compte et « Publier un projet » à droite. Sur mobile, tout passe dans un menu déroulant.
 */
export function SiteHeader() {
  const { user, loading, logout, hasRole } = useSession();
  const router = useRouter();
  const pathname = usePathname();
  const unread = useUnreadCount();
  const [menuOpen, setMenuOpen] = useState(false);
  const [openedAt, setOpenedAt] = useState(pathname);

  // Le menu se referme quand on change de page.
  if (menuOpen && openedAt !== pathname) {
    setMenuOpen(false);
    setOpenedAt(pathname);
  }

  useEffect(() => {
    if (!menuOpen) return;
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && setMenuOpen(false);
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [menuOpen]);

  const isClient = hasRole('particulier', 'entrepreneur');
  const badge =
    unread > 0 ? (
      <span className="badge" aria-label={`${unread} non lu${unread > 1 ? 's' : ''}`}>
        {unread > 99 ? '99+' : unread}
      </span>
    ) : null;

  async function signOut() {
    await logout();
    router.push('/');
    router.refresh();
  }

  return (
    <header className="container site-header">
      <nav className="site-header__nav" aria-label="Navigation principale">
        {NAV.map((item) => (
          <Link key={item.href} className="site-header__link" href={item.href}>
            {item.label}
          </Link>
        ))}
      </nav>

      <Link href="/" className="site-header__logo" aria-label="Loopartner, accueil">
        <Image src="/images/logo-white.png" alt="" width={640} height={214} priority />
      </Link>

      <div className="site-header__actions">
        {loading ? null : user ? (
          <>
            <Link className="site-header__link" href="/messages">
              Messages
              {badge}
            </Link>
            <Link className="site-header__link" href="/espace">
              Mon espace
            </Link>
            <button type="button" className="site-header__link site-header__button" onClick={() => void signOut()}>
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

      <button
        type="button"
        className="site-header__menu"
        aria-expanded={menuOpen}
        aria-controls="mobile-menu"
        onClick={() => {
          setOpenedAt(pathname);
          setMenuOpen((open) => !open);
        }}
      >
        <Icon name={menuOpen ? 'close' : 'list'} />
        <span className="visually-hidden">Menu</span>
        {!menuOpen ? badge : null}
      </button>

      {menuOpen ? (
        <nav id="mobile-menu" className="mobile-menu" aria-label="Menu">
          {NAV.map((item) => (
            <Link key={item.href} href={item.href}>
              {item.label}
            </Link>
          ))}
          <hr />
          {user ? (
            <>
              <Link href="/messages">Messages {badge}</Link>
              <Link href="/demandes">Mes demandes</Link>
              <Link href="/espace">Mon espace</Link>
              <button type="button" onClick={() => void signOut()}>
                Déconnexion
              </button>
            </>
          ) : (
            <>
              <Link href="/connexion">Connexion</Link>
              <Link href="/inscription">Inscription gratuite</Link>
            </>
          )}
          {!user || isClient ? (
            <Link className="btn btn--lime btn--block" href="/publier">
              Publier un projet
            </Link>
          ) : null}
        </nav>
      ) : null}
    </header>
  );
}
