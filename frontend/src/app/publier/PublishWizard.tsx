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
import type { Category, OwnProject } from '@/lib/types';

/**
 * Publication d'un projet en 4 étapes (maquettes « Publier un projet »).
 *
 * Adaptations au backend : résumé de 5 à 150 caractères, catégorie obligatoire, description de
 * 20 à 5 000 caractères, budget en fourchette, pays obligatoire. Pas de pièce jointe (non prévue
 * par l'API) ; contact par la messagerie seulement (décision 72, l'email n'est jamais montré).
 * Le brouillon est gardé dans l'onglet (sessionStorage) le temps de se connecter.
 */

const DRAFT_KEY = 'loopartner:project-draft';

const BUDGETS = [
  { value: 'lt300', label: 'Moins de 300 €', min: null, max: 300 },
  { value: '300-800', label: '300 à 800 €', min: 300, max: 800 },
  { value: '800-2000', label: '800 à 2 000 €', min: 800, max: 2000 },
  { value: 'gt2000', label: '2 000 € et plus', min: 2000, max: null },
  { value: 'unknown', label: 'Je ne sais pas encore', min: null, max: null },
] as const;

type BudgetValue = (typeof BUDGETS)[number]['value'];

const COUNTRIES = [
  ['FR', 'France'],
  ['BE', 'Belgique'],
  ['CH', 'Suisse'],
  ['LU', 'Luxembourg'],
  ['DE', 'Allemagne'],
  ['ES', 'Espagne'],
  ['IT', 'Italie'],
] as const;

interface Draft {
  title: string;
  category: string | null;
  description: string;
  budget: BudgetValue | null;
  country: string;
  remote: boolean;
}

const EMPTY: Draft = { title: '', category: null, description: '', budget: null, country: 'FR', remote: true };

function readDraft(): Draft | null {
  if (typeof window === 'undefined') return null;
  try {
    const raw = window.sessionStorage.getItem(DRAFT_KEY);
    return raw ? ({ ...EMPTY, ...(JSON.parse(raw) as Partial<Draft>) } as Draft) : null;
  } catch {
    return null;
  }
}

function saveDraft(draft: Draft) {
  try {
    window.sessionStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
  } catch {
    // Stockage indisponible (navigation privée stricte) : le brouillon reste en mémoire.
  }
}

function clearDraft() {
  try {
    window.sessionStorage.removeItem(DRAFT_KEY);
  } catch {
    // Rien à faire.
  }
}

export function PublishWizard() {
  const params = useSearchParams();
  const router = useRouter();
  const { user, loading, hasRole } = useSession();
  const [step, setStep] = useState(1);
  const [draft, setDraft] = useState<Draft>(() => {
    const title = params.get('titre');
    const category = params.get('categorie');
    return { ...(readDraft() ?? EMPTY), ...(title ? { title } : {}), ...(category ? { category } : {}) };
  });
  const [categories, setCategories] = useState<Category[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [needsAccount, setNeedsAccount] = useState(false);

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
      saveDraft(draft);
      setNeedsAccount(true);
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

  const progress = step * 25;

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
          <span>Étape {step} sur 4</span>
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
                Indiquez en quelques mots ce dont vous avez besoin. Vous pourrez détailler à l&apos;étape suivante. Le titre, la
                catégorie et le budget sont visibles de tous ; la description, seulement des prestataires inscrits.
              </p>
            </div>
          </>
        ) : null}

        {step === 2 ? (
          <>
            <h1 className="wizard__title">Expliquez-nous votre situation</h1>
            <div className="form-stack" style={{ gap: 32 }}>
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
              {needsAccount ? (
                <div className="card card--flat" style={{ padding: 28 }}>
                  <strong>Dernière étape : votre compte</strong>
                  <p style={{ fontFamily: 'var(--font-text)', color: 'var(--muted)', margin: '6px 0 16px' }}>
                    Votre projet est gardé dans cet onglet. Créez un compte gratuit ou connectez-vous, puis revenez ici pour le publier.
                  </p>
                  <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
                    <Link href="/inscription?profil=client" className="btn btn--lime">
                      Créer mon compte
                    </Link>
                    <Link href="/connexion?next=/publier" className="btn btn--outline">
                      Me connecter
                    </Link>
                  </div>
                </div>
              ) : null}
              <FormError message={error} />
            </div>
          </>
        ) : null}

        <p className="disclaimer" style={{ marginTop: 32 }}>
          Loopartner met en relation avec des professionnels et ne fournit pas de conseil juridique.
        </p>
      </main>

      <footer className="wizard__footer">
        <span className="wizard__free">
          <Icon name="check" size={18} className="" /> Gratuit et sans engagement
        </span>
        <div className="wizard__nav">
          {step > 1 ? (
            <button type="button" className="btn btn--ghost" onClick={() => setStep((value) => value - 1)}>
              Retour
            </button>
          ) : null}
          {step < 4 ? (
            <button type="button" className="btn btn--blue btn--lg" disabled={!canContinue} onClick={() => setStep((value) => value + 1)}>
              Continuer <Icon name="arrowRight" size={16} />
            </button>
          ) : (
            <button type="button" className="btn btn--blue btn--lg" disabled={busy || loading} onClick={publish}>
              {busy ? 'Publication…' : 'Publier mon projet'} <Icon name="arrowRight" size={16} />
            </button>
          )}
        </div>
      </footer>
    </div>
  );
}
