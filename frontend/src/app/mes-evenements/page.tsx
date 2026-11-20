import type { Metadata } from 'next';
import { Suspense } from 'react';
import { Highlight, PageShell } from '@/components/PageShell';
import { MyEvents } from './MyEvents';

export const metadata: Metadata = { title: 'Mes webinars' };

export default function MyEventsPage() {
  return (
    <PageShell
      title={
        <>
          Mes <Highlight>webinars</Highlight>
        </>
      }
    >
      <Suspense>
        <MyEvents />
      </Suspense>
    </PageShell>
  );
}
