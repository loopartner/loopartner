import type { Metadata } from 'next';
import { Suspense } from 'react';
import { AuthLayout } from '@/components/AuthLayout';
import { SignupFlow } from './SignupFlow';

export const metadata: Metadata = { title: 'Inscription gratuite' };

export default function SignupPage() {
  return (
    <AuthLayout>
      <Suspense>
        <SignupFlow />
      </Suspense>
    </AuthLayout>
  );
}
