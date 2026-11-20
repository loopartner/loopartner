'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import type { ReactNode } from 'react';
import { useSession } from '@/lib/session';
import type { Role } from '@/lib/types';

/**
 * Garde des pages connectées : invite à se connecter, signale un email à vérifier quand la
 * fonction l'exige, ou un rôle qui n'y a pas accès. L'API reste seule juge de l'autorisation.
 */
export function MemberGate({ children, verified = false, roles }: { children: ReactNode; verified?: boolean; roles?: Role[] }) {
  const { user, loading, hasRole } = useSession();
  const pathname = usePathname();

  if (loading) return <div className="skeleton" style={{ height: 240 }} />;

  if (!user) {
    return (
      <div className="card empty">
        <p>Connectez-vous pour accéder à cette page.</p>
        <Link href={`/connexion?next=${encodeURIComponent(pathname)}`} className="btn btn--lime">
          Se connecter
        </Link>
      </div>
    );
  }

  if (roles && !hasRole(...roles)) {
    return <div className="card empty">Cette page n&apos;est pas disponible pour votre type de compte.</div>;
  }

  if (verified && !user.email_verified) {
    return (
      <div className="card empty">
        <p>Vérifiez d&apos;abord votre adresse email : le lien vous a été envoyé à l&apos;inscription.</p>
        <Link href="/espace" className="btn btn--lime">
          Renvoyer le lien depuis mon espace
        </Link>
      </div>
    );
  }

  return <>{children}</>;
}
