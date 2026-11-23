'use client';

import Image from 'next/image';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { initials } from '@/lib/format';
import { useSession } from '@/lib/session';
import { useUnreadCount } from '@/lib/unread';
import { Icon } from './Icon';

/** Liens de l'en-tête, pour tous (articles et événements sont dans le pied de page). */
const NAV_LINKS = [
  { href: '/projets', label: 'Projets' },
  { href: '/prestataires', label: 'Prestataires' },
  { href: '/offres', label: 'Offres' },
];

/**
 * En-tête posé sur le bandeau bleu : Projets, Prestataires et Offres à gauche, logo au centre ; à droite « Publier un projet », l'icône des
 * messages (avec le nombre de non-lus) et la photo du compte, qui ouvre le menu du profil
 * (espace, profil, déconnexion). La navigation du site est dans le pied de page.
 */
export function SiteHeader() {
  const { user, loading, logout, hasRole } = useSession();
  const router = useRouter();
  const pathname = usePathname();
  const unread = useUnreadCount();
  const [menuOpen, setMenuOpen] = useState(false);
  const [openedAt, setOpenedAt] = useState(pathname);
  const menu = useRef<HTMLDivElement>(null);

  // Le menu se referme quand on change de page.
  if (menuOpen && openedAt !== pathname) {
    setMenuOpen(false);
    setOpenedAt(pathname);
  }

  useEffect(() => {
    if (!menuOpen) return;
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && setMenuOpen(false);
    const onClick = (event: MouseEvent) => {
      if (menu.current && !menu.current.contains(event.target as Node)) setMenuOpen(false);
    };
    window.addEventListener('keydown', onKey);
    window.addEventListener('mousedown', onClick);
    return () => {
      window.removeEventListener('keydown', onKey);
      window.removeEventListener('mousedown', onClick);
    };
  }, [menuOpen]);

  const isProvider = hasRole('prestataire');

  async function signOut() {
    setMenuOpen(false);
    await logout();
    router.push('/');
    router.refresh();
  }

  return (
    <header className="container site-header">
      <nav className="site-header__links" aria-label="Navigation principale">
        {NAV_LINKS.map((link) => (
          <Link key={link.href} href={link.href} aria-current={pathname.startsWith(link.href) ? 'page' : undefined}>
            {link.label}
          </Link>
        ))}
      </nav>

      <Link href="/" className="site-header__logo" aria-label="Loopartner, accueil">
        <Image src="/images/logo-white.png" alt="" width={640} height={214} priority />
      </Link>

      <div className="site-header__actions">
        {isProvider ? (
          <Link className="btn btn--lime site-header__cta" href="/projets">
            Voir<span className="site-header__cta-more">{'\u00a0'}les projets</span>
          </Link>
        ) : (
          <Link className="btn btn--lime site-header__cta" href="/publier">
            Publier<span className="site-header__cta-more">{'\u00a0'}un projet</span>
          </Link>
        )}

        {loading ? null : user ? (
          <>
            <Link className="site-header__icon" href="/messages" aria-label={unread > 0 ? `Messages, ${unread} non lu${unread > 1 ? 's' : ''}` : 'Messages'}>
              <Icon name="message" size={22} />
              {unread > 0 ? <span className="site-header__count">{unread > 99 ? '99+' : unread}</span> : null}
            </Link>
            <div className="site-header__profile" ref={menu}>
              <button
                type="button"
                className="site-header__avatar"
                aria-haspopup="menu"
                aria-expanded={menuOpen}
                aria-label="Mon compte"
                onClick={() => {
                  setOpenedAt(pathname);
                  setMenuOpen((open) => !open);
                }}
              >
                {user.avatar_url ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={user.avatar_url} alt="" />
                ) : (
                  <span>{initials(user.name)}</span>
                )}
              </button>
              {menuOpen ? (
                <div className="profile-menu" role="menu">
                  <div className="profile-menu__who">
                    <strong>{user.name}</strong>
                    <small>{user.email}</small>
                  </div>
                  {NAV_LINKS.map((link) => (
                    <Link key={link.href} role="menuitem" href={link.href} className="profile-menu__nav">
                      {link.label}
                    </Link>
                  ))}
                  <hr className="profile-menu__nav" />
                  <Link role="menuitem" href="/espace">
                    Mon espace
                  </Link>
                  <Link role="menuitem" href="/agenda">
                    Mon agenda
                  </Link>
                  <Link role="menuitem" href="/compte">
                    Mon compte
                  </Link>
                  <Link role="menuitem" href="/messages">
                    Messages
                  </Link>
                  <Link role="menuitem" href="/devis">
                    Mes devis
                  </Link>
                  {isProvider ? (
                    <>
                      <Link role="menuitem" href="/espace/profil">
                        Mon profil prestataire
                      </Link>
                      <Link role="menuitem" href="/espace/offres">
                        Mes offres
                      </Link>
                    </>
                  ) : null}
                  {!user.premium?.active && hasRole('particulier', 'prestataire', 'entrepreneur') ? (
                    <Link role="menuitem" href="/premium" className="profile-menu__premium">
                      <Icon name="gift" size={16} /> Passer Premium
                    </Link>
                  ) : null}
                  <hr />
                  <button type="button" role="menuitem" onClick={() => void signOut()}>
                    <Icon name="logout" size={16} /> Déconnexion
                  </button>
                </div>
              ) : null}
            </div>
          </>
        ) : (
          <Link className="site-header__icon site-header__login" href="/connexion" aria-label="Se connecter">
            <Icon name="user" size={22} />
            <span>Connexion</span>
          </Link>
        )}
      </div>
    </header>
  );
}
