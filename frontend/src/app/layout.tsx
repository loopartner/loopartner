import '@fontsource-variable/outfit';
import '@fontsource-variable/inter';
import './globals.css';

import type { Metadata, Viewport } from 'next';
import type { ReactNode } from 'react';
import { ConsentGate } from '@/components/ConsentGate';
import { DemoBanner } from '@/components/DemoBanner';
import { SessionProvider } from '@/lib/session';

export const metadata: Metadata = {
  title: {
    default: 'Loopartner — Lancez votre entreprise avec les bons experts',
    template: '%s · Loopartner',
  },
  description:
    "Publiez votre projet gratuitement et échangez avec des prestataires indépendants : création de société, juridique, comptabilité, fiscalité, propriété intellectuelle.",
  icons: { icon: '/images/logo-blue.png' },
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
        </SessionProvider>
      </body>
    </html>
  );
}
