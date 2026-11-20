import Image from 'next/image';
import Link from 'next/link';
import type { ReactNode } from 'react';

/** Gabarit des écrans de connexion et d'inscription : fond bleu, logo, carte centrée. */
export function AuthLayout({ children }: { children: ReactNode }) {
  return (
    <main className="auth-page">
      <Link href="/" className="auth-page__logo" aria-label="Loopartner, accueil">
        <Image src="/images/logo-white.png" alt="" width={640} height={214} priority />
      </Link>
      <div className="auth-card">{children}</div>
    </main>
  );
}
