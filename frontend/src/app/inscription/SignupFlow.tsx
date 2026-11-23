'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useEffect, useState, type FormEvent } from 'react';
import { FormError, PasswordField, TextField } from '@/components/Field';
import { Icon, type IconName } from '@/components/Icon';
import { api, ApiError } from '@/lib/api';
import type { LegalDocumentSummary } from '@/lib/types';

type AccountType = 'particulier' | 'entrepreneur' | 'prestataire';

const PROFILES: Array<{ value: 'client' | 'prestataire'; icon: IconName; title: string; text: string }> = [
  { value: 'client', icon: 'briefcase', title: 'Client', text: "J'ai un projet à réaliser" },
  { value: 'prestataire', icon: 'user', title: 'Prestataire', text: 'Je cherche des projets' },
];

const CLIENT_TYPES: Array<{ value: AccountType; icon: IconName; title: string; text: string }> = [
  { value: 'particulier', icon: 'user', title: 'Particulier', text: 'Je porte un projet à titre personnel' },
  { value: 'entrepreneur', icon: 'briefcase', title: 'Entrepreneur', text: "Je crée ou je dirige une entreprise" },
];

const COUNTRIES = [
  ['FR', 'France'],
  ['BE', 'Belgique'],
  ['CH', 'Suisse'],
  ['LU', 'Luxembourg'],
  ['DE', 'Allemagne'],
  ['ES', 'Espagne'],
  ['IT', 'Italie'],
] as const;

interface LegalIndexEntry {
  type: 'terms' | 'privacy';
  current: LegalDocumentSummary | null;
}

function ChoiceButton({ icon, title, text, onClick }: { icon: IconName; title: string; text: string; onClick: () => void }) {
  return (
    <button type="button" className="choice" onClick={onClick}>
      <span className="choice__icon">
        <Icon name={icon} size={28} />
      </span>
      <span>
        <span className="choice__title">{title}</span>
        <span className="choice__text">{text}</span>
      </span>
      <span className="choice__arrow">
        <Icon name="arrowRight" />
      </span>
    </button>
  );
}

export function SignupFlow() {
  const params = useSearchParams();
  const initial = params.get('profil');
  const [accountType, setAccountType] = useState<AccountType | null>(
    initial === 'prestataire' || initial === 'particulier' || initial === 'entrepreneur' ? initial : null,
  );
  const [clientStep, setClientStep] = useState(initial === 'client');

  if (accountType === null) {
    return (
      <>
        <h1 className="auth-card__title">Inscrivez-vous gratuitement</h1>
        <p className="auth-card__subtitle">{clientStep ? 'Vous êtes :' : 'Choisissez votre profil :'}</p>
        <div className="form-stack">
          {clientStep
            ? CLIENT_TYPES.map((type) => <ChoiceButton key={type.value} {...type} onClick={() => setAccountType(type.value)} />)
            : PROFILES.map((profile) => (
                <ChoiceButton
                  key={profile.value}
                  {...profile}
                  onClick={() => (profile.value === 'client' ? setClientStep(true) : setAccountType('prestataire'))}
                />
              ))}
          {clientStep ? (
            <button type="button" className="btn btn--ghost" onClick={() => setClientStep(false)}>
              <Icon name="arrowLeft" size={16} /> Retour
            </button>
          ) : null}
        </div>
        <p className="auth-card__footer">
          Déjà un compte ? <Link href="/connexion">Connexion</Link>
        </p>
      </>
    );
  }

  return <SignupForm accountType={accountType} onBack={() => setAccountType(null)} />;
}

function SignupForm({ accountType, onBack }: { accountType: AccountType; onBack: () => void }) {
  const [legal, setLegal] = useState<{ terms: LegalDocumentSummary; privacy: LegalDocumentSummary } | null | 'unavailable'>(null);
  const [email, setEmail] = useState('');
  const [name, setName] = useState('');
  const [password, setPassword] = useState('');
  const [country, setCountry] = useState('FR');
  const [accepted, setAccepted] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: LegalIndexEntry[] }>('legal/documents')
      .then((response) => {
        const terms = response.data.find((entry) => entry.type === 'terms')?.current;
        const privacy = response.data.find((entry) => entry.type === 'privacy')?.current;
        setLegal(terms && privacy ? { terms, privacy } : 'unavailable');
      })
      .catch(() => setLegal('unavailable'));
  }, []);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (legal === null || legal === 'unavailable') return;
    setBusy(true);
    setErrors({});
    setError(null);
    try {
      const response = await api<{ message: string }>('auth/register', {
        method: 'POST',
        body: {
          name: name.trim(),
          email: email.trim(),
          password,
          password_confirmation: password,
          account_type: accountType,
          legal_documents: { terms: legal.terms.version, privacy: legal.privacy.version },
          locale: 'fr',
          timezone: 'Europe/Paris',
          country,
        },
      });
      setDone(response.message);
    } catch (exception) {
      if (exception instanceof ApiError) {
        const fields = Object.fromEntries(Object.entries(exception.errors).map(([key, messages]) => [key, messages[0]]));
        setErrors(fields);
        setError(Object.keys(fields).length > 0 ? null : exception.code === 'legal_documents_unavailable' ? "L'inscription n'est pas encore ouverte." : exception.message);
      } else {
        setError("L'inscription a échoué. Réessayez.");
      }
    } finally {
      setBusy(false);
    }
  }

  if (done) {
    return (
      <div style={{ textAlign: 'center' }}>
        <h1 className="auth-card__title">Vérifiez votre boîte mail</h1>
        <p className="auth-card__subtitle" style={{ marginBottom: 24 }}>
          {done} Le lien est valable 60 minutes. Pensez à regarder dans vos courriers indésirables.
        </p>
        {accountType === 'prestataire' ? (
          <div className="signup-next">
            <p className="signup-next__title">Ensuite, en quatre étapes guidées :</p>
            <ol>
              <li>Ajoutez votre photo et une bannière.</li>
              <li>Décrivez votre activité.</li>
              <li>Choisissez vos domaines.</li>
              <li>Publiez votre profil pour apparaître dans l’annuaire.</li>
            </ol>
          </div>
        ) : null}
        <Link href="/connexion" className="btn btn--lime btn--block btn--lg">
          Aller à la connexion
        </Link>
      </div>
    );
  }

  const profileLabel = accountType === 'prestataire' ? 'prestataire' : accountType === 'entrepreneur' ? 'entrepreneur' : 'particulier';

  return (
    <>
      <h1 className="auth-card__title">Créez votre compte</h1>
      <p className="auth-card__subtitle">
        Profil {profileLabel} ·{' '}
        <button type="button" onClick={onBack} style={{ background: 'none', border: 0, color: 'var(--blue)', fontWeight: 700, cursor: 'pointer', padding: 0 }}>
          changer
        </button>
      </p>
      {legal === 'unavailable' ? (
        <p className="alert alert--info" style={{ marginBottom: 20 }}>
          Les inscriptions ouvriront dès la publication de nos conditions générales. Revenez très bientôt !
        </p>
      ) : null}
      <form className="form-stack" onSubmit={submit} noValidate>
        <TextField
          label="Adresse email"
          type="email"
          placeholder="nom@exemple.com"
          autoComplete="email"
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          required
          error={errors.email}
        />
        <TextField
          label="Nom affiché"
          placeholder={accountType === 'prestataire' ? 'Votre nom ou celui de votre activité' : 'Votre prénom et votre nom'}
          autoComplete="name"
          value={name}
          onChange={(event) => setName(event.target.value)}
          maxLength={255}
          required
          error={errors.name}
          hint="Il n'est montré à un prestataire qu'après votre accord pour échanger."
        />
        <PasswordField
          label="Mot de passe"
          placeholder="12 caractères minimum"
          autoComplete="new-password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          minLength={12}
          required
          error={errors.password}
          hint="12 caractères au moins. Les mots de passe trouvés dans des fuites de données sont refusés."
        />
        <div className="field">
          <label className="field__label" htmlFor="signup-country">
            Pays
          </label>
          <select id="signup-country" className="select" value={country} onChange={(event) => setCountry(event.target.value)}>
            {COUNTRIES.map(([code, label]) => (
              <option key={code} value={code}>
                {label}
              </option>
            ))}
          </select>
        </div>
        <label className="checkbox">
          <input type="checkbox" checked={accepted} onChange={(event) => setAccepted(event.target.checked)} required />
          <span>
            J&apos;accepte les{' '}
            <Link href="/legal/terms" target="_blank">
              CGU
            </Link>{' '}
            et la{' '}
            <Link href="/legal/privacy" target="_blank">
              politique de confidentialité
            </Link>
          </span>
        </label>
        {errors.legal_documents ? <p className="field__error">{errors.legal_documents}</p> : null}
        <FormError message={error} />
        <button
          type="submit"
          className="btn btn--lime btn--block btn--lg"
          disabled={busy || !accepted || legal === null || legal === 'unavailable'}
        >
          {busy ? 'Création…' : 'Créer mon compte'}
        </button>
      </form>
      <p className="legal-notice">
        La société Loopartner collecte ces informations afin de créer votre compte et de vous permettre d&apos;utiliser la
        plateforme. Pour en savoir plus sur la gestion de vos données personnelles et pour exercer vos droits, consultez notre{' '}
        <Link href="/legal/privacy">politique de confidentialité</Link>.
      </p>
      <p className="auth-card__footer">
        Déjà un compte ? <Link href="/connexion">Connexion</Link>
      </p>
    </>
  );
}
