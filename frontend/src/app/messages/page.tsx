import type { Metadata } from 'next';
import { Suspense } from 'react';
import { MemberGate } from '@/components/MemberGate';
import { Highlight, PageShell } from '@/components/PageShell';
import { Inbox } from './Inbox';

export const metadata: Metadata = { title: 'Messages' };

export default function MessagesPage() {
  return (
    <PageShell
      title={
        <>
          Mes <Highlight>messages</Highlight>
        </>
      }
    >
      <MemberGate verified>
        <Suspense>
          <Inbox />
        </Suspense>
      </MemberGate>
    </PageShell>
  );
}
