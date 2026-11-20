'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { FormError, PasswordField } from '@/components/Field';
import { ApiError, auth } from '@/lib/api';

/** Lien reçu par email : FRONTEND_URL/reset-password?token=…&email=… (redirigé ici). */
export function ResetPasswordForm() {
  const params = useSearchParams();
  const token = params.get('token') ?? '';
  const email = params.get('email') ?? '';
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [fieldError, setFieldError] = useState<string | undefined>();
  const [done, setDone] = useState(false);
  const [busy, setBusy] = useState(false);

  if (!token || !email) {
    return (
      <>
        <h1 className="auth-card__title">Lien incomplet</h1>
        <p className="auth-card__subtitle">Ouvrez le lien reçu par email, ou demandez-en un nouveau.</p>
        <Link href="/mot-de-passe-oublie" className="btn btn--lime btn--block">
          Demander un nouveau lien
        </Link>
      </>
    );
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setFieldError(undefined);
    try {
      await auth('reset-password', { token, email, password, password_confirmation: password });
      setDone(true);
    } catch (exception) {
      if (exception instanceof ApiError) {
        setFieldError(exception.field('password'));
        setError(exception.field('email') ?? (exception.field('password') ? null : exception.message));
      } else {
        setError('La modification a échoué.');
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <h1 className="auth-card__title">Nouveau mot de passe</h1>
      {done ? (
        <>
          <p className="alert alert--success" style={{ marginBottom: 20 }}>
            Votre mot de passe est modifié. Vos autres sessions ont été fermées.
          </p>
          <Link href="/connexion" className="btn btn--lime btn--block btn--lg">
            Se connecter
          </Link>
        </>
      ) : (
        <form className="form-stack" onSubmit={submit} noValidate>
          <p className="auth-card__subtitle" style={{ margin: 0 }}>
            Pour le compte {email}
          </p>
          <PasswordField
            label="Mot de passe"
            autoComplete="new-password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            minLength={12}
            required
            error={fieldError}
            hint="12 caractères au moins."
          />
          <FormError message={error} />
          <button type="submit" className="btn btn--lime btn--block btn--lg" disabled={busy || password.length < 12}>
            {busy ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </form>
      )}
    </>
  );
}
