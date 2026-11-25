import '@fontsource-variable/outfit';
import '@fontsource-variable/inter';
import './globals.css';

import type { Metadata, Viewport } from 'next';
import type { ReactNode } from 'react';
import { CardTilt } from '@/components/CardTilt';
import { ConsentGate } from '@/components/ConsentGate';
import { DemoBanner } from '@/components/DemoBanner';
import { SessionProvider } from '@/lib/session';
import { SITE_URL } from '@/lib/site';

export const metadata: Metadata = {
  metadataBase: new URL(SITE_URL),
  openGraph: { siteName: 'Loopartner', locale: 'fr_FR', type: 'website' },
  title: {
    default: 'Loopartner — Lancez votre entreprise avec les bons experts',
    template: '%s · Loopartner',
  },
  description:
    "Publiez votre projet gratuitement et échangez avec des prestataires indépendants : développeurs, designers, artistes, rédacteurs, juristes, comptables et tous les métiers qui font avancer une entreprise.",
};

export const viewport: Viewport = {
  themeColor: '#6aa6da',
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="fr">
      <body>
        <DemoBanner />
        <SessionProvider>
          {children}
          <ConsentGate />
          <CardTilt />
        </SessionProvider>
      </body>
    </html>
  );
}
