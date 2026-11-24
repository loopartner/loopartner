import type { Metadata } from 'next';
import { Suspense } from 'react';
import { PageShell } from '@/components/PageShell';
import { AccountSettings } from './AccountSettings';

export const metadata: Metadata = { title: 'Mon compte' };

export default function AccountPage() {
  return (
    <PageShell title="Mon compte" subtitle="Informations, sécurité, notifications et données personnelles.">
      <Suspense>
        <AccountSettings />
      </Suspense>
    </PageShell>
  );
}
