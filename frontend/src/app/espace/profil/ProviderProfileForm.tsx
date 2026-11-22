'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { CategoryPicker } from '@/components/CategoryPicker';
import { CountrySelect } from '@/components/CountrySelect';
import { FormError, TextAreaField, TextField } from '@/components/Field';
import { MediaUpload } from '@/components/MediaUpload';
import { api, ApiError } from '@/lib/api';
import type { OwnProviderProfile } from '@/lib/types';

interface Draft {
  display_name: string;
  headline: string;
  bio: string;
  city: string;
  country: string;
  website: string;
  categories: string[];
}

const EMPTY: Draft = { display_name: '', headline: '', bio: '', city: '', country: 'FR', website: '', categories: [] };

/** Création et modification du profil prestataire (GET et PUT /me/provider-profile). */
export function ProviderProfileForm() {
  const [profile, setProfile] = useState<OwnProviderProfile | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [logo, setLogo] = useState<string | null | undefined>(undefined);
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

  async function save(status: 'draft' | 'published') {
    if (!draft) return;
    setSaving(true);
    setError(null);
    setNotice(null);
    try {
      const body: Record<string, unknown> = {
        display_name: draft.display_name.trim(),
        headline: draft.headline.trim() || null,
        bio: draft.bio.trim() || null,
        city: draft.city.trim() || null,
        country: draft.country || null,
        website: draft.website.trim() || null,
        status,
      };
      if (draft.categories.length > 0) body.categories = draft.categories;
      if (logo !== undefined) body.logo = logo;
      const response = await api<{ data: OwnProviderProfile }>('me/provider-profile', { method: 'PUT', body });
      setProfile(response.data);
      setLogo(undefined);
      setNotice(status === 'published' ? 'Votre profil est publié.' : 'Brouillon enregistré.');
    } catch (exception) {
      setError(exception instanceof ApiError ? exception : new ApiError(0, {}));
    } finally {
      setSaving(false);
    }
  }

  const published = profile?.status === 'published';
  const known = ['display_name', 'headline', 'bio', 'city', 'country', 'website', 'categories', 'logo', 'status'];

  return (
    <div className="with-aside with-aside--right">
      <form
        className="card form-stack"
        onSubmit={(event) => {
          event.preventDefault();
          void save('published');
        }}
      >
        {profile?.moderation?.restricted ? (
          <p className="alert alert--error">
            Votre profil a été masqué par la modération. Consultez l&apos;exposé des motifs dans votre espace pour le corriger ou le contester.
          </p>
        ) : null}
        <TextField label="Nom affiché" value={draft.display_name} onChange={set('display_name')} maxLength={100} required error={error?.field('display_name')} hint="Votre nom commercial ou celui de votre cabinet." />
        <TextField label="Accroche (facultatif)" value={draft.headline} onChange={set('headline')} maxLength={160} error={error?.field('headline')} hint="Par exemple : Avocate en droit des sociétés." />
        <TextAreaField
          label="Présentation (facultatif)"
          value={draft.bio}
          onChange={set('bio')}
          rows={8}
          maxLength={5000}
          counter
          error={error?.field('bio')}
          hint="Votre parcours, vos spécialités, votre façon de travailler. Texte simple, sans coordonnées : les clients vous écrivent par la messagerie."
        />
        <div className="card-grid card-grid--2" style={{ gap: 16 }}>
          <TextField label="Ville (facultatif)" value={draft.city} onChange={set('city')} maxLength={100} error={error?.field('city')} />
          <CountrySelect id="profile-country" label="Pays" value={draft.country} onChange={(country) => setDraft({ ...draft, country })} error={error?.field('country')} />
        </div>
        <TextField label="Site web (facultatif)" type="url" value={draft.website} onChange={set('website')} maxLength={255} error={error?.field('website')} placeholder="https://" />
        <CategoryPicker value={draft.categories} onChange={(categories) => setDraft({ ...draft, categories })} max={5} error={error?.field('categories') ?? error?.field('categories.0')} label="Catégories (1 à 5, obligatoire pour publier)" />
        <MediaUpload purpose="provider_logo" label="Logo (facultatif)" initialUrl={profile?.logo_url ?? null} onUploaded={(uuid) => setLogo(uuid)} />
        {error?.field('logo') ? <span className="field__error">{error.field('logo')}</span> : null}

        {notice ? <p className="alert alert--success">{notice}</p> : null}
        <FormError message={error && !known.some((field) => error.field(field) || error.field(`${field}.0`)) ? error.message : null} />
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
          <button type="submit" className="btn btn--lime" disabled={saving || draft.display_name.trim().length < 2}>
            {saving ? 'Enregistrement…' : published ? 'Enregistrer' : 'Publier mon profil'}
          </button>
          <button type="button" className="btn btn--outline" disabled={saving || draft.display_name.trim().length < 2} onClick={() => void save('draft')}>
            {published ? 'Repasser en brouillon' : 'Enregistrer en brouillon'}
          </button>
        </div>
      </form>

      <aside className="aside-stack">
        <div className="card aside-card">
          <h3>{published ? 'Profil publié' : 'Profil non publié'}</h3>
          <p>
            {published
              ? 'Votre page est visible de tous et vous apparaissez dans l’annuaire. Vous pouvez consulter les projets et y répondre.'
              : 'Publiez votre profil avec au moins une catégorie pour apparaître dans l’annuaire et répondre aux projets.'}
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
  );
}
