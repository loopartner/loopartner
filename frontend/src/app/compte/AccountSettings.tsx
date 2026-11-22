'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { CountrySelect } from '@/components/CountrySelect';
import { Dialog } from '@/components/Dialog';
import { FormError, PasswordField, TextField } from '@/components/Field';
import { Icon } from '@/components/Icon';
import { api, ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { useSession } from '@/lib/session';
import type { Me } from '@/lib/types';

/** Erreurs de champ d'une ApiError, première erreur de chaque champ. */
function fieldErrors(error: unknown): Record<string, string> {
  return error instanceof ApiError ? Object.fromEntries(Object.entries(error.errors).map(([key, messages]) => [key, messages[0]])) : {};
}

function errorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError && Object.keys(error.errors).length === 0) return error.message;
  return error instanceof ApiError ? '' : fallback;
}

/**
 * Mon compte : informations et préférences (PATCH /me), mot de passe et double authentification
 * (Fortify, /auth/user/…), notifications (/me/notification-preferences), documents acceptés
 * (/me/legal), abonnement, suppression du compte (DELETE /me, mot de passe exigé).
 */
export function AccountSettings() {
  const { user, loading } = useSession();

  if (loading) return <div className="skeleton" style={{ height: 320 }} />;
  if (!user) {
    return (
      <div className="card empty">
        <p>Connectez-vous pour gérer votre compte.</p>
        <Link href="/connexion?next=/compte" className="btn btn--lime">
          Se connecter
        </Link>
      </div>
    );
  }

  return (
    <div className="account">
      <nav className="account__nav card" aria-label="Rubriques du compte">
        <a href="#informations">Informations</a>
        <a href="#securite">Sécurité</a>
        <a href="#notifications">Notifications</a>
        <a href="#abonnement">Abonnement</a>
        <a href="#documents">Documents acceptés</a>
        <a href="#suppression">Supprimer le compte</a>
      </nav>
      <div className="form-stack" style={{ gap: 28 }}>
        <ProfileSection user={user} />
        <PasswordSection />
        <TwoFactorSection user={user} />
        <NotificationsSection />
        <SubscriptionSection user={user} />
        <LegalSection />
        <DeleteSection />
      </div>
    </div>
  );
}

function Section({ id, title, intro, children, danger = false }: { id: string; title: string; intro?: ReactNode; children: ReactNode; danger?: boolean }) {
  return (
    <section id={id} className={`card account__section${danger ? ' account__section--danger' : ''}`} aria-labelledby={`${id}-title`}>
      <h2 id={`${id}-title`}>{title}</h2>
      {intro ? <p className="account__intro">{intro}</p> : null}
      {children}
    </section>
  );
}

/** Fuseaux proposés : ceux du navigateur, sinon une courte liste. */
function timezones(current: string | null): string[] {
  let list: string[] = [];
  try {
    list = (Intl as unknown as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf?.('timeZone') ?? [];
  } catch {
    list = [];
  }
  if (list.length === 0) list = ['Europe/Paris', 'Europe/Brussels', 'Europe/Zurich', 'Europe/Luxembourg', 'America/Montreal', 'Indian/Reunion', 'America/Martinique'];
  return current && !list.includes(current) ? [current, ...list] : list;
}

function ProfileSection({ user }: { user: Me }) {
  const { refresh } = useSession();
  const [name, setName] = useState(user.name);
  const [country, setCountry] = useState(user.country ?? '');
  const [locale, setLocale] = useState(user.locale ?? 'fr');
  const [timezone, setTimezone] = useState(user.timezone ?? 'Europe/Paris');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);
  const zones = useMemo(() => timezones(user.timezone), [user.timezone]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setError(null);
    setSaved(false);
    try {
      await api('me', { method: 'PATCH', body: { name: name.trim(), country: country || null, locale, timezone } });
      await refresh();
      setSaved(true);
    } catch (exception) {
      setErrors(fieldErrors(exception));
      setError(errorMessage(exception, 'L’enregistrement a échoué.') || null);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Section id="informations" title="Informations" intro="Votre adresse email ne peut pas être modifiée ici. Votre photo se change depuis Mon espace.">
      <form className="form-stack" onSubmit={submit} noValidate>
        <TextField label="Nom" value={name} onChange={(event) => setName(event.target.value)} required maxLength={255} error={errors.name} autoComplete="name" />
        <TextField label="Adresse email" value={user.email} disabled readOnly />
        <div className="account__grid">
          <CountrySelect id="account-country" label="Pays" value={country} onChange={setCountry} error={errors.country} allowEmpty />
          <div className="field">
            <label className="field__label" htmlFor="account-locale">
              Langue des emails
            </label>
            <select id="account-locale" className="select" value={locale} onChange={(event) => setLocale(event.target.value)}>
              <option value="fr">Français</option>
              <option value="en">English</option>
            </select>
            {errors.locale ? <p className="field__error">{errors.locale}</p> : null}
          </div>
          <div className="field">
            <label className="field__label" htmlFor="account-timezone">
              Fuseau horaire
            </label>
            <select id="account-timezone" className="select" value={timezone} onChange={(event) => setTimezone(event.target.value)}>
              {zones.map((zone) => (
                <option key={zone} value={zone}>
                  {zone.replace(/_/g, ' ')}
                </option>
              ))}
            </select>
            {errors.timezone ? <p className="field__error">{errors.timezone}</p> : null}
          </div>
        </div>
        <FormError message={error} />
        {saved ? <p className="alert alert--success">Vos informations sont enregistrées.</p> : null}
        <div>
          <button type="submit" className="btn btn--dark" disabled={busy || name.trim().length < 2}>
            {busy ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      </form>
    </Section>
  );
}

function PasswordSection() {
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setError(null);
    setSaved(false);
    try {
      await api('auth/user/password', {
        method: 'PUT',
        body: { current_password: current, password, password_confirmation: confirmation },
      });
      setCurrent('');
      setPassword('');
      setConfirmation('');
      setSaved(true);
    } catch (exception) {
      setErrors(fieldErrors(exception));
      setError(errorMessage(exception, 'Le changement de mot de passe a échoué.') || null);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Section
      id="securite"
      title="Mot de passe"
      intro="12 caractères au moins. Les mots de passe trouvés dans des fuites connues sont refusés. Vos autres appareils sont déconnectés après le changement."
    >
      <form className="form-stack" onSubmit={submit} noValidate>
        <PasswordField label="Mot de passe actuel" value={current} onChange={(event) => setCurrent(event.target.value)} autoComplete="current-password" required error={errors.current_password} />
        <div className="account__grid account__grid--2">
          <PasswordField label="Nouveau mot de passe" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="new-password" minLength={12} required error={errors.password} />
          <PasswordField
            label="Confirmation"
            value={confirmation}
            onChange={(event) => setConfirmation(event.target.value)}
            autoComplete="new-password"
            minLength={12}
            required
            error={errors.password_confirmation}
          />
        </div>
        <FormError message={error} />
        {saved ? <p className="alert alert--success">Votre mot de passe est modifié.</p> : null}
        <div>
          <button type="submit" className="btn btn--dark" disabled={busy || !current || password.length < 12 || password !== confirmation}>
            {busy ? 'Modification…' : 'Changer le mot de passe'}
          </button>
        </div>
      </form>
    </Section>
  );
}

type TwoFactorStep = { kind: 'idle' } | { kind: 'password'; then: 'enable' | 'codes' | 'disable' } | { kind: 'setup'; svg: string | null; secret: string | null } | { kind: 'codes'; codes: string[] };

/**
 * Double authentification (Fortify, confirmation exigée) : mot de passe confirmé, activation,
 * QR code et clé, code de vérification, puis codes de secours montrés une fois.
 */
function TwoFactorSection({ user }: { user: Me }) {
  const { refresh } = useSession();
  const [step, setStep] = useState<TwoFactorStep>({ kind: 'idle' });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [code, setCode] = useState('');

  async function run(action: 'enable' | 'codes' | 'disable') {
    setError(null);
    setBusy(true);
    try {
      if (action === 'enable') {
        await api('auth/user/two-factor-authentication', { method: 'POST' });
        const [qr, key] = await Promise.all([
          api<{ svg?: string }>('auth/user/two-factor-qr-code').catch(() => ({ svg: undefined })),
          api<{ secretKey?: string }>('auth/user/two-factor-secret-key').catch(() => ({ secretKey: undefined })),
        ]);
        setStep({ kind: 'setup', svg: qr.svg ?? null, secret: key.secretKey ?? null });
      } else if (action === 'codes') {
        await api('auth/user/two-factor-recovery-codes', { method: 'POST' });
        const codes = await api<string[]>('auth/user/two-factor-recovery-codes');
        setStep({ kind: 'codes', codes });
      } else {
        await api('auth/user/two-factor-authentication', { method: 'DELETE' });
        await refresh();
        setStep({ kind: 'idle' });
      }
    } catch (exception) {
      if (exception instanceof ApiError && exception.status === 423) {
        setStep({ kind: 'password', then: action });
      } else if (exception instanceof ApiError && exception.code === 'two_factor_required') {
        setError('La double authentification est obligatoire pour l’équipe Loopartner : elle ne peut pas être désactivée.');
        setStep({ kind: 'idle' });
      } else {
        setError(exception instanceof ApiError ? exception.message : 'L’opération a échoué.');
      }
    } finally {
      setBusy(false);
    }
  }

  async function confirmCode(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setBusy(true);
    try {
      await api('auth/user/confirmed-two-factor-authentication', { method: 'POST', body: { code: code.trim() } });
      const codes = await api<string[]>('auth/user/two-factor-recovery-codes');
      await refresh();
      setCode('');
      setStep({ kind: 'codes', codes });
    } catch (exception) {
      setError(exception instanceof ApiError ? (fieldErrors(exception).code ?? exception.message) : 'Le code n’a pas pu être vérifié.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Section
      id="double-authentification"
      title="Double authentification"
      intro="Un code à 6 chiffres, donné par une application comme Google Authenticator, 2FAS ou Aegis, vous est demandé à chaque connexion."
    >
      <div className="account__status">
        {user.two_factor_enabled ? (
          <span className="pill pill--status">
            <Icon name="shield" size={14} /> Activée
          </span>
        ) : (
          <span className="pill pill--tag">Désactivée</span>
        )}
        {user.two_factor_required ? <span className="account__hint">Obligatoire pour votre compte.</span> : null}
      </div>

      {step.kind === 'setup' ? (
        <form className="form-stack" onSubmit={confirmCode}>
          <p className="account__intro">Ajoutez Loopartner à votre application en scannant ce QR code ou en saisissant la clé, puis entrez le code affiché.</p>
          <div className="two-factor-setup">
            {step.svg ? (
              // L'image est un SVG produit par l'API, affiché comme image (aucun script ne s'y exécute).
              // eslint-disable-next-line @next/next/no-img-element
              <img src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(step.svg)}`} alt="QR code à scanner avec votre application" width={180} height={180} />
            ) : null}
            {step.secret ? (
              <p>
                Clé : <code className="two-factor-setup__key">{step.secret}</code>
              </p>
            ) : null}
          </div>
          <TextField label="Code à 6 chiffres" value={code} onChange={(event) => setCode(event.target.value)} inputMode="numeric" autoComplete="one-time-code" maxLength={6} required />
          <FormError message={error} />
          <div style={{ display: 'flex', gap: 12 }}>
            <button type="submit" className="btn btn--dark" disabled={busy || code.trim().length !== 6}>
              Vérifier et activer
            </button>
          </div>
        </form>
      ) : step.kind === 'codes' ? (
        <div className="form-stack">
          <p className="alert alert--warning">
            Gardez ces codes de secours en lieu sûr : chacun permet une connexion si vous perdez votre téléphone. Ils ne seront plus affichés.
          </p>
          <ul className="recovery-codes">
            {step.codes.map((recovery) => (
              <li key={recovery}>
                <code>{recovery}</code>
              </li>
            ))}
          </ul>
          <div>
            <button type="button" className="btn btn--outline" onClick={() => setStep({ kind: 'idle' })}>
              J&apos;ai noté mes codes
            </button>
          </div>
        </div>
      ) : (
        <>
          <FormError message={error} />
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
            {user.two_factor_enabled ? (
              <>
                <button type="button" className="btn btn--outline" disabled={busy} onClick={() => void run('codes')}>
                  Nouveaux codes de secours
                </button>
                {!user.two_factor_required ? (
                  <button type="button" className="btn btn--ghost" disabled={busy} onClick={() => void run('disable')}>
                    Désactiver
                  </button>
                ) : null}
              </>
            ) : (
              <button type="button" className="btn btn--dark" disabled={busy} onClick={() => void run('enable')}>
                <Icon name="shield" size={16} /> Activer la double authentification
              </button>
            )}
          </div>
        </>
      )}

      {step.kind === 'password' ? (
        <ConfirmPasswordDialog
          onClose={() => setStep({ kind: 'idle' })}
          onConfirmed={() => {
            const next = step.then;
            setStep({ kind: 'idle' });
            void run(next);
          }}
        />
      ) : null}
    </Section>
  );
}

function ConfirmPasswordDialog({ onClose, onConfirmed }: { onClose: () => void; onConfirmed: () => void }) {
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api('auth/user/confirm-password', { method: 'POST', body: { password } });
      onConfirmed();
    } catch (exception) {
      setError(exception instanceof ApiError ? (fieldErrors(exception).password ?? exception.message) : 'Le mot de passe n’a pas pu être vérifié.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog title="Confirmez votre mot de passe" onClose={onClose}>
      <form className="form-stack" onSubmit={submit}>
        <p className="account__intro">Pour votre sécurité, saisissez votre mot de passe avant de modifier la double authentification.</p>
        <PasswordField label="Mot de passe" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="current-password" required />
        <FormError message={error} />
        <div style={{ display: 'flex', gap: 12, justifyContent: 'flex-end' }}>
          <button type="button" className="btn btn--outline" onClick={onClose}>
            Annuler
          </button>
          <button type="submit" className="btn btn--dark" disabled={busy || !password}>
            Confirmer
          </button>
        </div>
      </form>
    </Dialog>
  );
}

interface NotificationCategory {
  category: string;
  label: string;
  mandatory: boolean;
  channels: Record<string, boolean>;
}

function NotificationsSection() {
  const [categories, setCategories] = useState<NotificationCategory[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: NotificationCategory[] }>('me/notification-preferences')
      .then((response) => setCategories(response.data))
      .catch(() => setError('Vos préférences ne peuvent pas être chargées pour le moment.'));
  }, []);

  async function toggle(item: NotificationCategory, enabled: boolean) {
    setBusy(item.category);
    setError(null);
    try {
      const response = await api<{ data: NotificationCategory[] }>('me/notification-preferences', {
        method: 'PUT',
        body: { preferences: [{ category: item.category, channel: 'mail', enabled }] },
      });
      setCategories(response.data);
    } catch (exception) {
      setError(exception instanceof ApiError ? exception.message : 'L’enregistrement a échoué.');
    } finally {
      setBusy(null);
    }
  }

  return (
    <Section id="notifications" title="Notifications par email" intro="Les emails liés à la sécurité de votre compte et aux décisions qui vous concernent sont toujours envoyés.">
      <FormError message={error} />
      {categories === null && !error ? (
        <div className="skeleton" style={{ height: 160 }} />
      ) : (
        <ul className="toggle-list">
          {(categories ?? []).map((item) => (
            <li key={item.category}>
              <span>
                {item.label}
                {item.mandatory ? <small>Toujours envoyé</small> : null}
              </span>
              <label className="switch">
                <input
                  type="checkbox"
                  checked={item.channels.mail ?? true}
                  disabled={item.mandatory || busy === item.category}
                  onChange={(event) => void toggle(item, event.target.checked)}
                  aria-label={`Emails : ${item.label}`}
                />
                <span aria-hidden="true" />
              </label>
            </li>
          ))}
        </ul>
      )}
    </Section>
  );
}

function SubscriptionSection({ user }: { user: Me }) {
  const active = user.premium?.active ?? false;
  return (
    <Section id="abonnement" title="Abonnement">
      <div style={{ display: 'flex', gap: 16, alignItems: 'center', flexWrap: 'wrap', justifyContent: 'space-between' }}>
        <p className="account__intro" style={{ margin: 0 }}>
          {active ? (
            <>
              <strong>Premium</strong> actif
              {user.premium?.ends_at ? ` jusqu’au ${formatDate(user.premium.ends_at)}` : ''}.
            </>
          ) : (
            <>
              Vous utilisez l&apos;offre <strong>Gratuite</strong>.
            </>
          )}
        </p>
        <Link href="/premium" className={active ? 'btn btn--outline' : 'btn btn--lime'}>
          {active ? 'Voir les avantages' : 'Découvrir Premium'}
        </Link>
      </div>
    </Section>
  );
}

interface LegalStatus {
  consent_required: boolean;
  documents: { type: string; version: string; title: string; accepted: boolean; accepted_at: string | null }[];
}

function LegalSection() {
  const [status, setStatus] = useState<LegalStatus | null>(null);

  useEffect(() => {
    api<{ data: LegalStatus }>('me/legal')
      .then((response) => setStatus(response.data))
      .catch(() => setStatus({ consent_required: false, documents: [] }));
  }, []);

  return (
    <Section id="documents" title="Documents acceptés">
      {status === null ? (
        <div className="skeleton" style={{ height: 60 }} />
      ) : status.documents.length === 0 ? (
        <p className="account__intro">Aucun document en vigueur pour le moment.</p>
      ) : (
        <ul className="toggle-list">
          {status.documents.map((document) => (
            <li key={document.type}>
              <span>
                <Link href={`/legal/${document.type}`}>{document.title}</Link>
                <small>
                  Version {document.version}
                  {document.accepted_at ? ` · acceptée le ${formatDate(document.accepted_at)}` : ' · à accepter'}
                </small>
              </span>
            </li>
          ))}
        </ul>
      )}
    </Section>
  );
}

function DeleteSection() {
  const router = useRouter();
  const { logout } = useSession();
  const [open, setOpen] = useState(false);
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api('me', { method: 'DELETE', body: { password } });
      await logout().catch(() => undefined);
      router.push('/?compte=supprime');
      router.refresh();
    } catch (exception) {
      setError(exception instanceof ApiError ? (fieldErrors(exception).password ?? exception.message) : 'La suppression a échoué.');
      setBusy(false);
    }
  }

  return (
    <Section
      id="suppression"
      title="Supprimer mon compte"
      danger
      intro="La suppression est définitive. Vos projets, offres, articles, messages non partagés, suivi administratif et espaces de projet sont supprimés ; les conversations restent lisibles par vos interlocuteurs, sans votre nom."
    >
      <div>
        <button type="button" className="btn btn--outline account__danger-button" onClick={() => setOpen(true)}>
          Supprimer mon compte
        </button>
      </div>
      {open ? (
        <Dialog title="Supprimer définitivement mon compte" onClose={() => setOpen(false)}>
          <form className="form-stack" onSubmit={submit}>
            <p className="account__intro">Cette action ne peut pas être annulée. Pour confirmer, saisissez votre mot de passe et écrivez SUPPRIMER.</p>
            <PasswordField label="Mot de passe" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="current-password" required />
            <TextField label="Écrivez SUPPRIMER" value={confirm} onChange={(event) => setConfirm(event.target.value)} autoComplete="off" required />
            <FormError message={error} />
            <div style={{ display: 'flex', gap: 12, justifyContent: 'flex-end' }}>
              <button type="button" className="btn btn--outline" onClick={() => setOpen(false)}>
                Annuler
              </button>
              <button type="submit" className="btn btn--dark account__danger-button--solid" disabled={busy || !password || confirm.trim() !== 'SUPPRIMER'}>
                {busy ? 'Suppression…' : 'Supprimer définitivement'}
              </button>
            </div>
          </form>
        </Dialog>
      ) : null}
    </Section>
  );
}
