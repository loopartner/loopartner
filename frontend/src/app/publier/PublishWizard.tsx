'use client';

import Image from 'next/image';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import { FormError, TextAreaField } from '@/components/Field';
import { Icon } from '@/components/Icon';
import { api, ApiError } from '@/lib/api';
import { length } from '@/lib/format';
import { useSession } from '@/lib/session';
import { clearDraft, EMPTY_DRAFT as EMPTY, readDraft, saveDraft, type BudgetValue, type Draft } from '@/lib/projectDraft';
import type { Category, OwnProject } from '@/lib/types';

/**
 * Publication d'un projet en 4 étapes (maquettes « Publier un projet »).
 *
 * Adaptations au backend : résumé de 5 à 150 caractères, catégorie obligatoire, description de
 * 20 à 5 000 caractères, budget en fourchette, pays obligatoire. Pas de pièce jointe (non prévue
 * par l'API) ; contact par la messagerie seulement (décision 72, l'email n'est jamais montré).
 * Le brouillon est gardé dans le navigateur (src/lib/projectDraft.ts) le temps de s'inscrire ou de
 * se connecter ; il est repris à la dernière étape, prêt à publier.
 */

/** Repère un téléphone, un email ou un lien dans un texte public (avertissement, sans blocage). */
function looksLikeContact(text: string): boolean {
  return (
    /[\w.+-]+@[\w-]+\.[\w.]+/.test(text) ||
    /(?:\+?\d[\s.\-()]*){9,}/.test(text) ||
    /\b(?:https?:\/\/|www\.)\S+/i.test(text)
  );
}

const BUDGETS = [
  { value: 'lt300', label: 'Moins de 300 €', min: null, max: 300 },
  { value: '300-800', label: '300 à 800 €', min: 300, max: 800 },
  { value: '800-2000', label: '800 à 2 000 €', min: 800, max: 2000 },
  { value: 'gt2000', label: '2 000 € et plus', min: 2000, max: null },
  { value: 'unknown', label: 'Je ne sais pas encore', min: null, max: null },
] as const satisfies readonly { value: BudgetValue; label: string; min: number | null; max: number | null }[];

const COUNTRIES = [
  ['FR', 'France'],
  ['BE', 'Belgique'],
  ['CH', 'Suisse'],
  ['LU', 'Luxembourg'],
  ['DE', 'Allemagne'],
  ['ES', 'Espagne'],
  ['IT', 'Italie'],
] as const;

export function PublishWizard() {
  const params = useSearchParams();
  const router = useRouter();
  const { user, loading, hasRole } = useSession();
  // Projet rempli avant l'inscription ou la connexion : on le reprend à la dernière étape.
  const [rawStep, setStep] = useState(() => (readDraft()?.ready ? 4 : 1));
  const [draft, setDraft] = useState<Draft>(() => {
    const title = params.get('titre');
    const category = params.get('categorie');
    return { ...(readDraft() ?? EMPTY), ...(title ? { title } : {}), ...(category ? { category } : {}) };
  });
  // Connecté entre-temps : l'étape d'inscription n'a plus lieu d'être.
  const step = user && rawStep === 5 ? 4 : rawStep;
  const [categories, setCategories] = useState<Category[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api<{ data: Category[] }>('categories')
      .then((response) => setCategories(response.data))
      .catch(() => setCategories([]));
  }, []);

  function update(patch: Partial<Draft>) {
    setDraft((current) => {
      const next = { ...current, ...patch };
      saveDraft(next);
      return next;
    });
  }

  const canContinue =
    (step === 1 && length(draft.title.trim()) >= 5 && draft.category !== null) ||
    (step === 2 && length(draft.description.trim()) >= 20) ||
    (step === 3 && draft.budget !== null) ||
    step === 4;

  async function publish() {
    if (!user) {
      saveDraft({ ...draft, ready: true });
      setStep(5);
      return;
    }
    if (!hasRole('particulier', 'entrepreneur')) {
      setError('Seuls les comptes particulier et entrepreneur peuvent publier un projet.');
      return;
    }

    setBusy(true);
    setError(null);
    setFieldErrors({});
    const budget = BUDGETS.find((item) => item.value === draft.budget);
    let created: OwnProject | null = null;
    try {
      const response = await api<{ data: OwnProject }>('me/projects', {
        method: 'POST',
        body: {
          title: draft.title.trim(),
          description: draft.description.trim(),
          category: draft.category,
          budget_min: budget?.min ?? null,
          budget_max: budget?.max ?? null,
          currency: 'EUR',
          country: draft.country,
          remote_ok: draft.remote,
        },
      });
      created = response.data;
      await api(`me/projects/${created.uuid}/publish`, { method: 'POST' });
      clearDraft();
      router.push(`/publier/confirmation?projet=${created.uuid}`);
    } catch (exception) {
      if (exception instanceof ApiError) {
        const fields = Object.fromEntries(Object.entries(exception.errors).map(([key, messages]) => [key, messages[0]]));
        setFieldErrors(fields);
        if (created !== null) {
          clearDraft();
          setError(
            exception.status === 403
              ? "Votre projet est enregistré en brouillon. Vérifiez votre adresse email (lien reçu à l'inscription), puis publiez-le depuis votre espace."
              : `Votre projet est enregistré en brouillon, mais il n'a pas pu être publié : ${exception.message}`,
          );
        } else {
          setError(Object.keys(fields).length > 0 ? 'Certains champs sont à corriger.' : exception.message);
          if (fields.title || fields.category) setStep(1);
          else if (fields.description) setStep(2);
        }
      } else {
        setError('La publication a échoué. Réessayez.');
      }
    } finally {
      setBusy(false);
    }
  }

  // Sans compte, une 5e étape invite à s'inscrire (plutôt qu'un encart à chercher en bas de page).
  const totalSteps = user || loading ? 4 : 5;
  const progress = Math.round((Math.min(step, totalSteps) / totalSteps) * 100);

  // Un prestataire (ou l'équipe) ne publie pas de projet : on le lui dit tout de suite, au lieu
  // de le laisser remplir quatre étapes pour un refus à la fin.
  if (user && !hasRole('particulier', 'entrepreneur')) {
    return (
      <div className="wizard">
        <header className="wizard__header">
          <Link href="/" aria-label="Loopartner, accueil">
            <Image src="/images/logo-blue.png" alt="" width={640} height={204} priority />
          </Link>
          <Link href="/" className="wizard__close" aria-label="Quitter la publication">
            <Icon name="close" size={18} />
          </Link>
        </header>
        <main className="wizard__main">
          <h1 className="wizard__title">Les projets sont publiés par les porteurs de projet</h1>
          <p style={{ fontFamily: 'var(--font-text)', color: 'var(--muted)', textAlign: 'center', maxWidth: 520, margin: '0 auto 24px' }}>
            {hasRole('prestataire')
              ? 'Votre compte est un compte prestataire : vous répondez aux projets publiés par les particuliers et les entrepreneurs.'
              : 'Votre type de compte ne permet pas de publier un projet.'}
          </p>
          <div style={{ display: 'flex', gap: 12, justifyContent: 'center', flexWrap: 'wrap' }}>
            {hasRole('prestataire') ? (
              <Link href="/projets" className="btn btn--lime btn--lg">
                Voir les projets <Icon name="arrowRight" size={16} />
              </Link>
            ) : null}
            <Link href="/espace" className="btn btn--outline btn--lg">
              Mon espace
            </Link>
          </div>
        </main>
      </div>
    );
  }

  return (
    <div className="wizard">
      <header className="wizard__header">
        <Link href="/" aria-label="Loopartner, accueil">
          <Image src="/images/logo-blue.png" alt="" width={640} height={204} priority />
        </Link>
        <Link href="/" className="wizard__close" aria-label="Quitter la publication">
          <Icon name="close" size={18} />
        </Link>
      </header>

      <div className="wizard__progress">
        <div className="wizard__progress-row">
          <span>Étape {Math.min(step, totalSteps)} sur {totalSteps}</span>
          <span>{progress}%</span>
        </div>
        <div className="wizard__bar" role="progressbar" aria-valuenow={progress} aria-valuemin={0} aria-valuemax={100} aria-label="Avancement">
          <span style={{ width: `${progress}%` }} />
        </div>
      </div>

      <main className="wizard__main">
        {step === 1 ? (
          <>
            <h1 className="wizard__title">Quel projet souhaitez-vous lancer ?</h1>
            <div className="form-stack" style={{ gap: 40 }}>
              <div className="field">
                <div className="field__label-row">
                  <label className="field__label" htmlFor="project-title">
                    Résumez votre besoin en une phrase
                  </label>
                  <span className="field__counter">{length(draft.title)}/150</span>
                </div>
                <input
                  id="project-title"
                  className="input"
                  style={{ borderRadius: 9999, padding: '24px 32px', background: '#fff' }}
                  placeholder="Ex : création d'une SAS, dépôt de marque, audit RGPD…"
                  value={draft.title}
                  maxLength={150}
                  onChange={(event) => update({ title: event.target.value })}
                  aria-invalid={fieldErrors.title ? true : undefined}
                />
                {fieldErrors.title ? <span className="field__error">{fieldErrors.title}</span> : null}
              </div>
              <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
                <legend className="field__label" style={{ marginBottom: 16 }}>
                  Choisissez une catégorie
                </legend>
                <div className="chips">
                  {categories.length === 0 ? <span className="field__hint">Chargement des catégories…</span> : null}
                  {categories.map((category) => (
                    <button
                      key={category.slug}
                      type="button"
                      className="chip"
                      aria-pressed={draft.category === category.slug}
                      onClick={() => update({ category: category.slug })}
                    >
                      {category.name}
                    </button>
                  ))}
                </div>
                {fieldErrors.category ? <span className="field__error">{fieldErrors.category}</span> : null}
              </fieldset>
              <p className="note">
                <span className="note__icon">
                  <Icon name="info" size={16} />
                </span>
                Indiquez en quelques mots ce dont vous avez besoin. Vous pourrez détailler à l&apos;étape suivante. Votre projet
                (titre, catégorie, description et budget) sera visible de tous ; votre nom et vos coordonnées, jamais.
              </p>
            </div>
          </>
        ) : null}

        {step === 2 ? (
          <>
            <h1 className="wizard__title">Expliquez-nous votre situation</h1>
            <div className="form-stack" style={{ gap: 32 }}>
              <div className="alert alert--warning" role="note">
                <strong>Votre description sera visible de tous.</strong> N&apos;y écrivez ni votre numéro de téléphone, ni votre
                email, ni votre adresse, ni votre nom complet. Les prestataires vous répondent par la messagerie de Loopartner, et vos
                coordonnées restent privées.
              </div>
              <TextAreaField
                label="Description de votre projet"
                value={draft.description}
                onChange={(event) => update({ description: event.target.value })}
                maxLength={5000}
                counter
                rows={9}
                placeholder={"Je souhaite créer...\nMon activité consiste à...\nJ'ai besoin d'aide pour...\nJe voudrais que ce soit terminé avant le..."}
                error={fieldErrors.description}
                hint="N'indiquez ni vos coordonnées ni d'information sensible (santé, numéro de compte…)."
              />
              {looksLikeContact(draft.description) ? (
                <p className="alert alert--error" role="alert">
                  Votre description semble contenir un numéro de téléphone, un email ou un lien. Retirez-le : il serait visible de tous.
                </p>
              ) : null}
              <p className="note">
                <span className="note__icon">
                  <Icon name="info" size={16} />
                </span>
                Plus votre description est précise, plus les propositions seront adaptées à votre besoin.
              </p>
            </div>
          </>
        ) : null}

        {step === 3 ? (
          <>
            <h1 className="wizard__title">Quel budget avez-vous prévu ?</h1>
            <fieldset className="form-stack" style={{ border: 0, padding: 0, margin: 0, gap: 12 }}>
              <legend className="field__label" style={{ marginBottom: 12 }}>
                Budget approximatif
              </legend>
              {BUDGETS.map((budget) => (
                <label key={budget.value} className="radio-card">
                  {budget.label}
                  <input type="radio" name="budget" value={budget.value} checked={draft.budget === budget.value} onChange={() => update({ budget: budget.value })} />
                </label>
              ))}
            </fieldset>
            <p className="note note--lime" style={{ marginTop: 32 }}>
              <span className="note__icon">
                <Icon name="bulb" size={16} />
              </span>
              Le budget est indicatif. Les prestataires vous proposeront leur propre tarif.
            </p>
          </>
        ) : null}

        {step === 4 ? (
          <>
            <h1 className="wizard__title">Dernières précisions</h1>
            <div className="form-stack" style={{ gap: 24 }}>
              {user && draft.ready ? (
                <p className="note">
                  <span className="note__icon">
                    <Icon name="check" size={16} />
                  </span>
                  Votre projet a été gardé. Vérifiez ces derniers points puis cliquez sur « Publier mon projet ».
                </p>
              ) : null}
              <div className="card card--flat" style={{ borderColor: 'var(--blue)', borderWidth: 2, padding: 28 }}>
                <strong style={{ fontSize: 20 }}>Messagerie privée Loopartner</strong>
                <p style={{ fontFamily: 'var(--font-text)', color: 'var(--muted)', margin: '6px 0 0' }}>
                  Les prestataires vous répondent dans la messagerie. Vos échanges restent sur la plateforme et votre nom n&apos;est
                  révélé qu&apos;à ceux dont vous acceptez la proposition.
                </p>
              </div>
              <div className="field">
                <label className="field__label" htmlFor="project-country">
                  Pays du projet
                </label>
                <select id="project-country" className="select" value={draft.country} onChange={(event) => update({ country: event.target.value })}>
                  {COUNTRIES.map(([code, label]) => (
                    <option key={code} value={code}>
                      {label}
                    </option>
                  ))}
                </select>
              </div>
              <label className="checkbox">
                <input type="checkbox" checked={draft.remote} onChange={(event) => update({ remote: event.target.checked })} />
                <span>La mission peut se faire à distance</span>
              </label>
              <p className="note">
                <span className="note__icon">
                  <Icon name="lock" size={16} />
                </span>
                Vos coordonnées ne sont jamais affichées publiquement.
              </p>
              <FormError message={error} />
            </div>
          </>
        ) : null}

        {step === 5 && !user ? (
          <>
            <h1 className="wizard__title">Dernière étape : inscrivez-vous</h1>
            <div className="form-stack" style={{ gap: 24 }}>
              <p style={{ fontFamily: 'var(--font-text)', color: 'var(--muted)', margin: 0 }}>
                Votre projet est prêt. Créez votre compte gratuit pour le publier et recevoir les
                propositions des prestataires.
              </p>
              <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
                <Link href="/inscription?profil=client" className="btn btn--blue btn--lg">
                  Créer mon compte gratuit <Icon name="arrowRight" size={16} />
                </Link>
              </div>
              <p style={{ fontFamily: 'var(--font-text)', margin: 0 }}>
                Déjà inscrit ?{' '}
                <Link href="/connexion?next=/publier" className="link-arrow">
                  Me connecter pour publier
                </Link>
              </p>
              <p className="note">
                <span className="note__icon">
                  <Icon name="lock" size={16} />
                </span>
                Votre projet est gardé sur cet appareil. Après la confirmation de votre email et votre connexion, vous serez ramené ici pour le publier en un clic.
              </p>
            </div>
          </>
        ) : null}

      </main>

      <footer className="wizard__footer">
        <span className="wizard__free">
          <Icon name="check" size={18} className="" /> Gratuit et sans engagement
        </span>
        <div className="wizard__nav">
          {step > 1 ? (
            <button type="button" className="btn btn--ghost" onClick={() => setStep(step - 1)}>
              Retour
            </button>
          ) : null}
          {step < 4 ? (
            <button type="button" className="btn btn--blue btn--lg" disabled={!canContinue} onClick={() => setStep(step + 1)}>
              Continuer <Icon name="arrowRight" size={16} />
            </button>
          ) : step === 4 && !user && !loading ? (
            <button type="button" className="btn btn--blue btn--lg" onClick={() => {
                saveDraft({ ...draft, ready: true });
                setStep(5);
              }}>
              Suivant <Icon name="arrowRight" size={16} />
            </button>
          ) : step === 4 ? (
            <button type="button" className="btn btn--blue btn--lg" disabled={busy || loading} onClick={publish}>
              {busy ? 'Publication…' : 'Publier mon projet'} <Icon name="arrowRight" size={16} />
            </button>
          ) : null}
        </div>
      </footer>
    </div>
  );
}
