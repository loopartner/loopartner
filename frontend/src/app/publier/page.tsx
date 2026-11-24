import type { Metadata } from 'next';
import { Suspense } from 'react';
import { PublishLoader } from './PublishLoader';

export const metadata: Metadata = { title: 'Publier un projet' };

export default function PublishPage() {
  return (
    <Suspense>
      <PublishLoader />
    </Suspense>
  );
}
