import type { Metadata } from 'next';
import { Suspense } from 'react';
import { AuthLayout } from '@/components/AuthLayout';
import { ResetPasswordForm } from './ResetPasswordForm';

export const metadata: Metadata = { title: 'Nouveau mot de passe' };

export default function ResetPasswordPage() {
  return (
    <AuthLayout>
      <Suspense>
        <ResetPasswordForm />
      </Suspense>
    </AuthLayout>
  );
}
