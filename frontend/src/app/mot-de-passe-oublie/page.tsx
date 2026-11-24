'use client';

import Link from 'next/link';
import { useState, type FormEvent } from 'react';
import { AuthLayout } from '@/components/AuthLayout';
import { FormError, TextField } from '@/components/Field';
import { ApiError, auth } from '@/lib/api';

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const response = await auth<{ message?: string }>('forgot-password', { email: email.trim() });
      setMessage(response?.message ?? 'Si un compte correspond à cette adresse, un lien de réinitialisation vient de partir.');
    } catch (exception) {
      setError(exception instanceof ApiError ? exception.field('email') ?? exception.message : "L'envoi a échoué.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthLayout>
      <h1 className="auth-card__title">Mot de passe oublié</h1>
      <p className="auth-card__subtitle">Indiquez votre adresse email : nous vous enverrons un lien pour choisir un nouveau mot de passe.</p>
      {message ? (
        <p className="alert alert--success">{message}</p>
      ) : (
        <form className="form-stack" onSubmit={submit} noValidate>
          <TextField label="Adresse email" type="email" autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} required />
          <FormError message={error} />
          <button type="submit" className="btn btn--lime btn--block btn--lg" disabled={busy || email.trim() === ''}>
            {busy ? 'Envoi…' : 'Recevoir le lien'}
          </button>
        </form>
      )}
      <p className="auth-card__footer">
        <Link href="/connexion">Retour à la connexion</Link>
      </p>
    </AuthLayout>
  );
}
