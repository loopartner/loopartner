'use client';

import Link from 'next/link';
import { useCallback, useState, type ReactNode } from 'react';
import { useSession } from '@/lib/session';
import { Dialog } from './Dialog';

/**
 * Lien vers une page réservée aux membres. Pour un visiteur sans compte, il ouvre une fenêtre
 * « connectez-vous ou créez un compte » au lieu d'emmener sur une page qui ne ferait que le dire.
 * Après la connexion, la personne arrive sur la page visée (`next`).
 */
export function AuthLink({
  href,
  className,
  children,
  reason = 'Connectez-vous pour continuer.',
  signupProfile = 'client',
}: {
  href: string;
  className?: string;
  children: ReactNode;
  reason?: string;
  signupProfile?: 'client' | 'entrepreneur' | 'prestataire';
}) {
  const { user, loading } = useSession();
  const [open, setOpen] = useState(false);
  const close = useCallback(() => setOpen(false), []);

  if (user || loading) {
    return (
      <Link href={href} className={className}>
        {children}
      </Link>
    );
  }

  return (
    <>
      <button type="button" className={className} onClick={() => setOpen(true)} aria-haspopup="dialog">
        {children}
      </button>
      {open ? (
        <Dialog title="Connectez-vous pour continuer" onClose={close}>
          <p style={{ fontFamily: 'var(--font-text)', color: 'var(--muted)', margin: '0 0 20px' }}>{reason}</p>
          <div className="form-stack" style={{ gap: 12 }}>
            <Link href={`/connexion?next=${encodeURIComponent(href)}`} className="btn btn--blue btn--block">
              Me connecter
            </Link>
            <Link href={`/inscription?profil=${signupProfile}`} className="btn btn--outline btn--block">
              Créer un compte gratuit
            </Link>
          </div>
        </Dialog>
      ) : null}
    </>
  );
}
