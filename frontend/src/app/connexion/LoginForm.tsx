'use client';

import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { hasPendingDraft } from '@/lib/projectDraft';
import { useState, type FormEvent } from 'react';
import { FormError, PasswordField, TextField } from '@/components/Field';
import { ApiError, auth } from '@/lib/api';
import { useSession } from '@/lib/session';

/** Messages affichés au retour du lien de vérification d'email (redirection de l'API). */
const VERIFICATION_MESSAGES: Record<string, { kind: 'success' | 'error'; text: string }> = {
  verified: { kind: 'success', text: 'Votre adresse email est vérifiée. Vous pouvez vous connecter.' },
  expired: { kind: 'error', text: 'Ce lien de vérification a expiré. Connectez-vous pour en recevoir un nouveau.' },
  invalid: { kind: 'error', text: "Ce lien de vérification n'est pas valide." },
};

/** Chemin interne seulement, pour ne jamais rediriger vers un autre site après la connexion. */
function safeNext(value: string | null): string {
  return value && value.startsWith('/') && !value.startsWith('//') ? value : '/';
}

export function LoginForm() {
  const params = useSearchParams();
  const router = useRouter();
  const { refresh } = useSession();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(false);
  const [code, setCode] = useState('');
  const [useRecovery, setUseRecovery] = useState(false);
  const [step, setStep] = useState<'credentials' | 'two-factor'>('credentials');
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  const notice = params.get('verified') === '1' ? VERIFICATION_MESSAGES.verified : VERIFICATION_MESSAGES[params.get('verification') ?? ''];
  const next = safeNext(params.get('next'));

  async function finish() {
    await refresh();
    // Projet rempli avant l'inscription : retour au parcours de publication.
    router.push(params.get('next') === null && hasPendingDraft() ? '/publier' : next);
    router.refresh();
  }

  function handleError(exception: unknown) {
    if (exception instanceof ApiError) {
      setFieldErrors(Object.fromEntries(Object.entries(exception.errors).map(([key, messages]) => [key, messages[0]])));
      setError(Object.keys(exception.errors).length > 0 ? null : exception.message);
    } else {
      setError('Connexion impossible pour le moment.');
    }
  }

  async function submitCredentials(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setFieldErrors({});
    try {
      const response = await auth<{ two_factor?: boolean } | undefined>('login', { email, password, remember });
      if (response && response.two_factor) {
        setStep('two-factor');
      } else {
        await finish();
      }
    } catch (exception) {
      handleError(exception);
    } finally {
      setBusy(false);
    }
  }

  async function submitCode(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setFieldErrors({});
    try {
      await auth('two-factor-challenge', useRecovery ? { recovery_code: code.trim() } : { code: code.replace(/\s+/g, '') });
      await finish();
    } catch (exception) {
      handleError(exception);
    } finally {
      setBusy(false);
    }
  }

  if (step === 'two-factor') {
    return (
      <>
        <h1 className="auth-card__title">Double authentification</h1>
        <p className="auth-card__subtitle">
          {useRecovery ? 'Saisissez un de vos codes de secours.' : "Saisissez le code à 6 chiffres affiché par votre application d'authentification."}
        </p>
        <form className="form-stack" onSubmit={submitCode} noValidate>
          <TextField
            label={useRecovery ? 'Code de secours' : 'Code de vérification'}
            value={code}
            onChange={(event) => setCode(event.target.value)}
            inputMode={useRecovery ? 'text' : 'numeric'}
            autoComplete="one-time-code"
            autoFocus
            required
            error={fieldErrors.code ?? fieldErrors.recovery_code}
          />
          <FormError message={error} />
          <button type="submit" className="btn btn--lime btn--block btn--lg" disabled={busy || code.trim() === ''}>
            {busy ? 'Vérification…' : 'Valider'}
          </button>
          <button type="button" className="btn btn--ghost btn--block" onClick={() => setUseRecovery((value) => !value)}>
            {useRecovery ? "Utiliser l'application d'authentification" : 'Utiliser un code de secours'}
          </button>
        </form>
      </>
    );
  }

  return (
    <>
      <h1 className="auth-card__title">Se connecter</h1>
      <p className="auth-card__subtitle">Heureux de vous revoir sur Loopartner.</p>
      {notice ? <p className={`alert alert--${notice.kind}`} style={{ marginBottom: 20 }}>{notice.text}</p> : null}
      <form className="form-stack" onSubmit={submitCredentials} noValidate>
        <TextField
          label="Adresse email"
          type="email"
          placeholder="nom@exemple.com"
          autoComplete="email"
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          required
          error={fieldErrors.email}
        />
        <PasswordField
          label="Mot de passe"
          placeholder="••••••••"
          autoComplete="current-password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          required
          error={fieldErrors.password}
          aside={
            <Link href="/mot-de-passe-oublie" style={{ color: 'var(--blue)', fontWeight: 700, fontSize: 13 }}>
              Mot de passe oublié ?
            </Link>
          }
        />
        <label className="checkbox">
          <input type="checkbox" checked={remember} onChange={(event) => setRemember(event.target.checked)} />
          <span>Se souvenir de moi</span>
        </label>
        <FormError message={error} />
        <button type="submit" className="btn btn--lime btn--block btn--lg" disabled={busy}>
          {busy ? 'Connexion…' : 'Se connecter'}
        </button>
      </form>
      <p className="auth-card__footer">
        Pas encore de compte ? <Link href="/inscription">Inscription gratuite</Link>
      </p>
    </>
  );
}
