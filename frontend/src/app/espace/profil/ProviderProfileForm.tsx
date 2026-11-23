'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { CategoryPicker } from '@/components/CategoryPicker';
import { CountrySelect } from '@/components/CountrySelect';
import { FormError, TextAreaField, TextField } from '@/components/Field';
import { Icon } from '@/components/Icon';
import { MediaUpload } from '@/components/MediaUpload';
import { ProviderCover } from '@/components/ProviderCover';
import { api, ApiError } from '@/lib/api';
import { countryName } from '@/lib/format';
import type { OwnProviderProfile } from '@/lib/types';

/** Longueur minimale de la présentation pour publier (config/accounts.php, min_bio_length). */
const MIN_BIO = 80;

interface Draft {
  display_name: string;
  headline: string;
  bio: string;
  city: string;
  country: string;
  website: string;
  categories: string[];
}

/** Médias : uuid à envoyer (undefined = inchangé, null = retiré) et URL pour l'aperçu. */
interface MediaState {
  uuid: string | null | undefined;
  url: string | null;
}

const EMPTY: Draft = { display_name: '', headline: '', bio: '', city: '', country: 'FR', website: '', categories: [] };

const STEPS = [
  { key: 'visuals', title: 'Photo et bannière', hint: 'Ce que les clients voient en premier' },
  { key: 'about', title: 'Présentation', hint: 'Qui vous êtes et ce que vous faites' },
  { key: 'fields', title: 'Domaines', hint: 'Vos spécialités et votre zone' },
  { key: 'publish', title: 'Publication', hint: 'Vérifiez puis publiez' },
] as const;

/**
 * Profil prestataire guidé (décision 82) : photo et bannière, présentation, domaines, puis
 * publication. Chaque étape enregistre un brouillon ; la publication exige la photo, la
 * bannière, une présentation de 80 caractères et au moins un domaine (l'API le vérifie aussi).
 */
export function ProviderProfileForm() {
  const [profile, setProfile] = useState<OwnProviderProfile | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [logo, setLogo] = useState<MediaState>({ uuid: undefined, url: null });
  const [banner, setBanner] = useState<MediaState>({ uuid: undefined, url: null });
  const [step, setStep] = useState(0);
  const [error, setError] = useState<ApiError | null>(null);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    api<{ data: OwnProviderProfile }>('me/provider-profile')
      .then((response) => {
        if (!active) return;
        const data = response.data;
        setProfile(data);
        setLogo({ uuid: undefined, url: data.logo_url });
        setBanner({ uuid: undefined, url: data.banner_url });
        setDraft({
          display_name: data.display_name,
          headline: data.headline ?? '',
          bio: data.bio ?? '',
          city: data.city ?? '',
          country: data.country ?? 'FR',
          website: data.website ?? '',
          categories: data.categories.map((category) => category.slug),
        });
      })
      .catch((exception) => {
        if (!active) return;
        if (exception instanceof ApiError && exception.status === 404) setDraft(EMPTY);
        else setError(exception instanceof ApiError ? exception : new ApiError(0, {}));
      });
    return () => {
      active = false;
    };
  }, []);

  if (!draft) return error ? <p className="alert alert--error">{error.message}</p> : <div className="skeleton" style={{ height: 400 }} />;

  const set = (key: keyof Draft) => (event: { target: { value: string } }) => setDraft({ ...draft, [key]: event.target.value });
  const published = profile?.status === 'published';
  const bioLength = draft.bio.trim().length;
  const checks = {
    visuals: Boolean(logo.url) && Boolean(banner.url),
    about: draft.display_name.trim().length >= 2 && bioLength >= MIN_BIO,
    fields: draft.categories.length > 0,
  };
  const complete = checks.visuals && checks.about && checks.fields;
  const stepDone = [checks.visuals, checks.about, checks.fields, published];

  async function save(status: 'draft' | 'published', nextStep?: number) {
    if (!draft) return;
    setSaving(true);
    setError(null);
    setNotice(null);
    try {
      const body: Record<string, unknown> = {
        display_name: draft.display_name.trim() || 'Mon profil',
        headline: draft.headline.trim() || null,
        bio: draft.bio.trim() || null,
        city: draft.city.trim() || null,
        country: draft.country || null,
        website: draft.website.trim() || null,
        status,
      };
      if (draft.categories.length > 0) body.categories = draft.categories;
      if (logo.uuid !== undefined) body.logo = logo.uuid;
      if (banner.uuid !== undefined) body.banner = banner.uuid;
      const response = await api<{ data: OwnProviderProfile }>('me/provider-profile', { method: 'PUT', body });
      setProfile(response.data);
      setLogo({ uuid: undefined, url: response.data.logo_url });
      setBanner({ uuid: undefined, url: response.data.banner_url });
      if (status === 'published') setNotice(published ? 'Modifications enregistrées.' : 'Votre profil est publié : vous apparaissez dans l’annuaire.');
      if (nextStep !== undefined) setStep(nextStep);
    } catch (exception) {
      const apiError = exception instanceof ApiError ? exception : new ApiError(0, {});
      setError(apiError);
      // Ramène à l'étape du premier champ refusé.
      const fieldStep = (['logo', 'banner'].some((field) => apiError.field(field)) && 0) || (['display_name', 'headline', 'bio'].some((field) => apiError.field(field)) && 1) || (['categories', 'city', 'country', 'website'].some((field) => apiError.field(field) || apiError.field(`${field}.0`)) && 2);
      if (typeof fieldStep === 'number') setStep(fieldStep);
    } finally {
      setSaving(false);
    }
  }

  // Un profil déjà publié reste publié : chaque étape enregistre alors directement.
  const keepStatus = published ? 'published' : 'draft';
  const canContinue = [checks.visuals, checks.about, checks.fields, complete][step];
  const known = ['display_name', 'headline', 'bio', 'city', 'country', 'website', 'categories', 'logo', 'banner', 'status'];
  const place = [draft.city.trim(), countryName(draft.country)].filter(Boolean).join(', ');

  return (
    <div className="provider-setup">
      <ol className="setup-steps" aria-label="Étapes du profil">
        {STEPS.map((item, index) => (
          <li key={item.key}>
            <button type="button" className={`setup-steps__item${index === step ? ' is-current' : ''}${stepDone[index] ? ' is-done' : ''}`} aria-current={index === step ? 'step' : undefined} onClick={() => setStep(index)}>
              <span className="setup-steps__number">{stepDone[index] ? <Icon name="check" size={14} /> : index + 1}</span>
              <span>
                <strong>{item.title}</strong>
                <small>{item.hint}</small>
              </span>
            </button>
          </li>
        ))}
      </ol>

      <div className="with-aside with-aside--right">
        <form
          className="card form-stack"
          onSubmit={(event) => {
            event.preventDefault();
            if (step < 3) void save(keepStatus, step + 1);
            else void save('published');
          }}
        >
          {profile?.moderation?.restricted ? (
            <p className="alert alert--error">
              Votre profil a été masqué par la modération. Consultez l&apos;exposé des motifs dans votre espace pour le corriger ou le contester.
            </p>
          ) : null}

          {step === 0 ? (
            <>
              <h2 className="setup-title">Votre photo et votre bannière</h2>
              <p className="setup-lead">Obligatoires pour publier : un profil avec un visage ou un logo inspire confiance aux porteurs de projet.</p>
              <MediaUpload
                purpose="provider_logo"
                label="Photo de profil ou logo"
                shape="round"
                initialUrl={logo.url}
                hint="Carrée de préférence, 400 × 400 px conseillés."
                error={error?.field('logo')}
                onUploaded={(uuid, url) => setLogo({ uuid, url })}
              />
              <MediaUpload
                purpose="provider_banner"
                label="Bannière"
                shape="banner"
                initialUrl={banner.url}
                hint="Format large, 1600 × 400 px conseillés : votre lieu de travail, une réalisation, une ambiance."
                error={error?.field('banner')}
                onUploaded={(uuid, url) => setBanner({ uuid, url })}
              />
            </>
          ) : null}

          {step === 1 ? (
            <>
              <h2 className="setup-title">Présentez-vous</h2>
              <TextField label="Nom affiché" value={draft.display_name} onChange={set('display_name')} maxLength={100} required error={error?.field('display_name')} hint="Votre nom commercial ou celui de votre cabinet." />
              <TextField label="Accroche (facultatif)" value={draft.headline} onChange={set('headline')} maxLength={160} error={error?.field('headline')} hint="Par exemple : Avocate en droit des sociétés." />
              <TextAreaField
                label="Description de votre activité"
                value={draft.bio}
                onChange={set('bio')}
                rows={9}
                maxLength={5000}
                counter
                required
                error={error?.field('bio')}
                hint={`${MIN_BIO} caractères au moins${bioLength < MIN_BIO ? ` (encore ${MIN_BIO - bioLength})` : ''}. Votre parcours, vos spécialités, votre façon de travailler. Texte simple, sans coordonnées : les clients vous écrivent par la messagerie.`}
              />
            </>
          ) : null}

          {step === 2 ? (
            <>
              <h2 className="setup-title">Vos domaines</h2>
              <CategoryPicker value={draft.categories} onChange={(categories) => setDraft({ ...draft, categories })} max={5} error={error?.field('categories') ?? error?.field('categories.0')} label="Domaines d'activité (1 à 5)" />
              <div className="card-grid card-grid--2" style={{ gap: 16 }}>
                <TextField label="Ville (facultatif)" value={draft.city} onChange={set('city')} maxLength={100} error={error?.field('city')} />
                <CountrySelect id="profile-country" label="Pays" value={draft.country} onChange={(country) => setDraft({ ...draft, country })} error={error?.field('country')} />
              </div>
              <TextField label="Site web (facultatif)" type="url" value={draft.website} onChange={set('website')} maxLength={255} error={error?.field('website')} placeholder="https://" />
            </>
          ) : null}

          {step === 3 ? (
            <>
              <h2 className="setup-title">{published ? 'Votre profil est en ligne' : 'Prêt à publier ?'}</h2>
              <ul className="setup-checklist">
                <CheckItem done={Boolean(logo.url)} label="Photo de profil" onFix={() => setStep(0)} />
                <CheckItem done={Boolean(banner.url)} label="Bannière" onFix={() => setStep(0)} />
                <CheckItem done={checks.about} label={`Description de ${MIN_BIO} caractères au moins`} onFix={() => setStep(1)} />
                <CheckItem done={checks.fields} label="Au moins un domaine" onFix={() => setStep(2)} />
              </ul>
              <p className="setup-lead">Une fois publié, votre profil apparaît dans l&apos;annuaire et vous pouvez publier des offres.</p>
            </>
          ) : null}

          {notice ? <p className="alert alert--success">{notice}</p> : null}
          <FormError message={error && !known.some((field) => error.field(field) || error.field(`${field}.0`)) ? error.message : null} />

          <div className="setup-actions">
            {step > 0 ? (
              <button type="button" className="btn btn--outline" onClick={() => setStep(step - 1)} disabled={saving}>
                <Icon name="arrowLeft" size={16} /> Retour
              </button>
            ) : (
              <span />
            )}
            <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
              {step === 3 && published ? (
                <button type="button" className="btn btn--outline" disabled={saving} onClick={() => void save('draft')}>
                  Repasser en brouillon
                </button>
              ) : null}
              <button type="submit" className="btn btn--lime" disabled={saving || !canContinue}>
                {saving ? 'Enregistrement…' : step < 3 ? 'Enregistrer et continuer' : published ? 'Enregistrer' : 'Publier mon profil'}
                {step < 3 && !saving ? <Icon name="arrowRight" size={16} /> : null}
              </button>
            </div>
          </div>
        </form>

        <aside className="aside-stack">
          <div className="setup-preview">
            <p className="setup-preview__label">Aperçu de votre page</p>
            <ProviderCover name={draft.display_name.trim()} bannerUrl={banner.url} logoUrl={logo.url} subtitle={[draft.headline.trim(), place].filter(Boolean).join(' · ') || undefined} compact />
          </div>
          <div className="card aside-card">
            <h3>{published ? 'Profil publié' : 'Profil non publié'}</h3>
            <p>
              {published
                ? 'Votre page est visible de tous et vous apparaissez dans l’annuaire.'
                : 'Photo, bannière, description et domaine sont nécessaires pour apparaître dans l’annuaire.'}
            </p>
            {published && profile?.slug ? (
              <Link href={`/prestataires/${profile.slug}`} className="btn btn--dark btn--block">
                Voir ma page publique
              </Link>
            ) : null}
          </div>
          <div className="card aside-card">
            <h3>Et ensuite ?</h3>
            <p>Pour publier des offres et envoyer des devis, complétez aussi votre identité professionnelle.</p>
            <Link href="/espace/identite" className="link-arrow">
              Mon identité professionnelle
            </Link>
          </div>
        </aside>
      </div>
    </div>
  );
}

function CheckItem({ done, label, onFix }: { done: boolean; label: string; onFix: () => void }) {
  return (
    <li className={done ? 'is-done' : undefined}>
      <span className="setup-checklist__mark">{done ? <Icon name="check" size={14} /> : null}</span>
      <span>{label}</span>
      {!done ? (
        <button type="button" className="link-arrow" onClick={onFix}>
          Compléter
        </button>
      ) : null}
    </li>
  );
}
