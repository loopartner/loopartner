'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { CountrySelect } from '@/components/CountrySelect';
import { FormError, TextAreaField, TextField } from '@/components/Field';
import { MediaUpload } from '@/components/MediaUpload';
import { api, ApiError } from '@/lib/api';
import { centsToInput, DELIVERY_LABELS, parseEuros, PRICING_LABELS } from '@/lib/offers';
import type { Category, DeliveryMode, OwnOffer, PricingMode } from '@/lib/types';

interface Draft {
  title: string;
  summary: string;
  description: string;
  category: string;
  pricing_mode: PricingMode;
  price: string;
  delivery_mode: DeliveryMode;
  country: string;
  city: string;
}

const EMPTY: Draft = { title: '', summary: '', description: '', category: '', pricing_mode: 'fixed', price: '', delivery_mode: 'remote', country: 'FR', city: '' };

/** Création (POST /me/offers, en brouillon) ou modification (PATCH /me/offers/{uuid}) d'une offre. */
export function OfferForm({ uuid }: { uuid?: string }) {
  const router = useRouter();
  const [draft, setDraft] = useState<Draft | null>(uuid ? null : EMPTY);
  const [categories, setCategories] = useState<Category[]>([]);
  const [error, setError] = useState<ApiError | null>(null);
  const [saving, setSaving] = useState(false);
  // Image de couverture (décision 83) : undefined = inchangée, null = retirée.
  const [image, setImage] = useState<string | null | undefined>(undefined);
  const [imageUrl, setImageUrl] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    api<{ data: Category[] }>('categories')
      .then((response) => active && setCategories(response.data))
      .catch(() => undefined);
    if (uuid) {
      api<{ data: OwnOffer }>(`me/offers/${uuid}`)
        .then((response) => {
          if (!active) return;
          const offer = response.data;
          setImageUrl(offer.image_url);
          setDraft({
            title: offer.title,
            summary: offer.summary,
            description: offer.description,
            category: offer.category?.slug ?? '',
            pricing_mode: offer.pricing_mode,
            price: centsToInput(offer.price_cents),
            delivery_mode: offer.delivery_mode,
            country: offer.country,
            city: offer.city ?? '',
          });
        })
        .catch((exception) => active && setError(exception instanceof ApiError ? exception : new ApiError(0, {})));
    }
    return () => {
      active = false;
    };
  }, [uuid]);

  if (!draft) return error ? <p className="alert alert--error">{error.message}</p> : <div className="skeleton" style={{ height: 400 }} />;

  const set = (key: keyof Draft) => (event: { target: { value: string } }) => setDraft({ ...draft, [key]: event.target.value });

  async function save() {
    if (!draft) return;
    setSaving(true);
    setError(null);
    const onQuote = draft.pricing_mode === 'on_quote';
    const cents = onQuote ? null : parseEuros(draft.price);
    if (!onQuote && cents === null) {
      setError(new ApiError(422, { message: 'Prix invalide.', errors: { price_cents: ['Indiquez un prix en euros, par exemple 450 ou 450,50.'] } }));
      setSaving(false);
      return;
    }
    const body: Record<string, unknown> = {
      title: draft.title.trim(),
      summary: draft.summary.trim(),
      description: draft.description.trim(),
      category: draft.category,
      pricing_mode: draft.pricing_mode,
      delivery_mode: draft.delivery_mode,
      country: draft.country,
      city: draft.city.trim() || null,
    };
    if (!onQuote) body.price_cents = cents;
    if (image !== undefined) body.image = image;
    try {
      if (uuid) await api(`me/offers/${uuid}`, { method: 'PATCH', body });
      else await api('me/offers', { method: 'POST', body });
      router.push('/espace/offres');
    } catch (exception) {
      setError(exception instanceof ApiError ? exception : new ApiError(0, {}));
      setSaving(false);
    }
  }

  const known = ['title', 'summary', 'description', 'category', 'pricing_mode', 'price_cents', 'delivery_mode', 'country', 'city', 'image'];

  return (
    <form
      className="card form-stack"
      style={{ maxWidth: 820 }}
      onSubmit={(event) => {
        event.preventDefault();
        void save();
      }}
    >
      <TextField label="Titre" value={draft.title} onChange={set('title')} maxLength={160} counter error={error?.field('title')} hint="10 caractères au moins. Par exemple : Création de SAS clés en main." />
      <TextAreaField label="Résumé" value={draft.summary} onChange={set('summary')} maxLength={300} rows={3} counter error={error?.field('summary')} hint="30 à 300 caractères, affiché dans le catalogue." />
      <TextAreaField
        label="Description"
        value={draft.description}
        onChange={set('description')}
        maxLength={5000}
        rows={10}
        counter
        error={error?.field('description')}
        hint="Ce qui est inclus, les délais, ce que vous demandez au client. Texte simple ; les liens éventuels ne seront pas cliquables."
      />
      <MediaUpload
        purpose="offer_image"
        label="Image de l'offre (facultatif)"
        initialUrl={imageUrl}
        removable
        hint="Elle illustre l'offre dans le catalogue et sur votre page. 1200 × 800 px conseillés, sans coordonnées ni texte trompeur."
        error={error?.field('image')}
        onUploaded={(uuid) => setImage(uuid)}
      />
      <div className="field">
        <label className="field__label" htmlFor="offer-category-select">
          Catégorie
        </label>
        <select id="offer-category-select" className="select" value={draft.category} onChange={set('category')} required>
          <option value="">Choisissez une catégorie</option>
          {categories.map((parent) => (
            <optgroup key={parent.slug} label={parent.name}>
              <option value={parent.slug}>{parent.name}</option>
              {(parent.children ?? []).map((child) => (
                <option key={child.slug} value={child.slug}>
                  {child.name}
                </option>
              ))}
            </optgroup>
          ))}
        </select>
        {error?.field('category') ? <span className="field__error">{error.field('category')}</span> : null}
      </div>
      <div className="card-grid card-grid--2" style={{ gap: 16 }}>
        <div className="field">
          <label className="field__label" htmlFor="offer-pricing">
            Type de tarif
          </label>
          <select id="offer-pricing" className="select" value={draft.pricing_mode} onChange={set('pricing_mode')}>
            {(Object.keys(PRICING_LABELS) as PricingMode[]).map((mode) => (
              <option key={mode} value={mode}>
                {PRICING_LABELS[mode]}
              </option>
            ))}
          </select>
        </div>
        {draft.pricing_mode !== 'on_quote' ? (
          <TextField label="Prix indicatif TTC (€)" inputMode="decimal" value={draft.price} onChange={set('price')} maxLength={15} error={error?.field('price_cents')} />
        ) : (
          <span />
        )}
      </div>
      <div className="card-grid card-grid--2" style={{ gap: 16 }}>
        <div className="field">
          <label className="field__label" htmlFor="offer-delivery">
            Réalisation
          </label>
          <select id="offer-delivery" className="select" value={draft.delivery_mode} onChange={set('delivery_mode')}>
            {(Object.keys(DELIVERY_LABELS) as DeliveryMode[]).map((mode) => (
              <option key={mode} value={mode}>
                {DELIVERY_LABELS[mode]}
              </option>
            ))}
          </select>
        </div>
        <TextField label="Ville (facultatif)" value={draft.city} onChange={set('city')} maxLength={100} error={error?.field('city')} />
      </div>
      <CountrySelect id="offer-country" label="Pays" value={draft.country} onChange={(country) => setDraft({ ...draft, country })} error={error?.field('country')} />
      <FormError message={error && !known.some((field) => error.field(field)) ? error.message : null} />
      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
        <button type="submit" className="btn btn--lime" disabled={saving}>
          {saving ? 'Enregistrement…' : uuid ? 'Enregistrer' : 'Créer le brouillon'}
        </button>
        <button type="button" className="btn btn--outline" onClick={() => router.push('/espace/offres')}>
          Annuler
        </button>
      </div>
      <p className="disclaimer" style={{ textAlign: 'left' }}>
        Une nouvelle offre est enregistrée en brouillon : publiez-la ensuite depuis la liste. Une offre publiée et modifiée reste en ligne.
      </p>
    </form>
  );
}
