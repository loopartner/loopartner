'use client';

import { useEffect, useState } from 'react';
import { CountrySelect } from '@/components/CountrySelect';
import { FormError, TextAreaField, TextField } from '@/components/Field';
import { api, ApiError } from '@/lib/api';
import { centsToInput, LEGAL_FORMS, parseEuros, SELLER_FIELD_LABELS } from '@/lib/offers';
import type { SellerProfile } from '@/lib/types';

/**
 * Texte et version de la déclaration sur l'honneur, identiques à `marketplace.self_certification`
 * (config/marketplace.php) : à changer ensemble. Une fois l'identité créée, la version en vigueur
 * vient de GET /me/seller-profile ; si elle diffère, la page affiche un avertissement.
 */
const SELF_CERTIFICATION_VERSION = '2026-11-17';
const SELF_CERTIFICATION_TEXT =
  "Je déclare agir en tant que professionnel, que les informations de mon identité professionnelle sont exactes, et je m'engage à ne proposer que des services conformes au droit applicable.";

type Fields = Record<
  | 'legal_name'
  | 'trade_name'
  | 'legal_form'
  | 'registration_number'
  | 'registry'
  | 'registry_city'
  | 'share_capital'
  | 'vat_number'
  | 'address_line1'
  | 'address_line2'
  | 'postal_code'
  | 'city'
  | 'country'
  | 'phone'
  | 'email'
  | 'insurer_name'
  | 'insurer_contact'
  | 'insurance_coverage'
  | 'mediator_name'
  | 'mediator_url'
  | 'regulated_profession_title'
  | 'regulated_profession_state'
  | 'regulated_profession_body'
  | 'terms_url'
  | 'quote_footer',
  string
>;

const EMPTY: Fields = {
  legal_name: '',
  trade_name: '',
  legal_form: 'ei_micro',
  registration_number: '',
  registry: 'rne',
  registry_city: '',
  share_capital: '',
  vat_number: '',
  address_line1: '',
  address_line2: '',
  postal_code: '',
  city: '',
  country: 'FR',
  phone: '',
  email: '',
  insurer_name: '',
  insurer_contact: '',
  insurance_coverage: '',
  mediator_name: '',
  mediator_url: '',
  regulated_profession_title: '',
  regulated_profession_state: '',
  regulated_profession_body: '',
  terms_url: '',
  quote_footer: '',
};

function fromProfile(profile: SellerProfile): Fields {
  const fields = { ...EMPTY };
  for (const key of Object.keys(EMPTY) as Array<keyof Fields>) {
    if (key === 'share_capital') continue;
    const value = profile[key as keyof SellerProfile];
    fields[key] = typeof value === 'string' ? value : '';
  }
  fields.share_capital = centsToInput(profile.share_capital_cents);
  return fields;
}

/** Identité professionnelle du prestataire (GET et PUT /me/seller-profile), chiffrée côté API. */
export function SellerProfileForm() {
  const [profile, setProfile] = useState<SellerProfile | null>(null);
  const [fields, setFields] = useState<Fields | null>(null);
  const [tenYear, setTenYear] = useState(false);
  const [certified, setCertified] = useState(false);
  const [version, setVersion] = useState<string | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    api<{ data: SellerProfile }>('me/seller-profile')
      .then((response) => {
        if (!active) return;
        setProfile(response.data);
        setFields(fromProfile(response.data));
        setTenYear(response.data.insurance_ten_year);
        setVersion(response.data.self_certification.current_version);
      })
      .catch((exception) => {
        if (!active) return;
        if (exception instanceof ApiError && exception.status === 404) setFields(EMPTY);
        else setError(exception instanceof ApiError ? exception : new ApiError(0, {}));
      });
    return () => {
      active = false;
    };
  }, []);

  if (!fields) return error ? <p className="alert alert--error">{error.message}</p> : <div className="skeleton" style={{ height: 400 }} />;

  const form = LEGAL_FORMS.find((item) => item.value === fields.legal_form);
  const france = fields.country === 'FR';
  const field = (key: keyof Fields, label: string, options: { hint?: string; max?: number; type?: string; required?: boolean; errorKey?: string } = {}) => (
    <TextField
      label={options.required ? label : `${label} (facultatif)`}
      type={options.type ?? 'text'}
      value={fields[key]}
      maxLength={options.max ?? 200}
      onChange={(event) => setFields({ ...fields, [key]: event.target.value })}
      error={error?.field(options.errorKey ?? key)}
      hint={options.hint}
    />
  );

  async function save() {
    if (!fields) return;
    setSaving(true);
    setError(null);
    setNotice(null);
    const capital = fields.share_capital.trim() ? parseEuros(fields.share_capital) : null;
    if (fields.share_capital.trim() && capital === null) {
      setError(new ApiError(422, { message: 'Montant invalide.', errors: { share_capital_cents: ['Indiquez un montant en euros, par exemple 1000 ou 1000,50.'] } }));
      setSaving(false);
      return;
    }
    const body: Record<string, unknown> = { insurance_ten_year: tenYear, share_capital_cents: capital, self_certification: certified, self_certification_version: version ?? SELF_CERTIFICATION_VERSION };
    for (const [key, value] of Object.entries(fields)) {
      if (key === 'share_capital') continue;
      body[key] = value.trim() || null;
    }
    try {
      const response = await api<{ data: SellerProfile }>('me/seller-profile', { method: 'PUT', body });
      setProfile(response.data);
      setVersion(response.data.self_certification.current_version);
      setCertified(false);
      setNotice('Identité professionnelle enregistrée.');
    } catch (exception) {
      setError(exception instanceof ApiError ? exception : new ApiError(0, {}));
    } finally {
      setSaving(false);
    }
  }

  const missing = profile ? [...new Set([...profile.missing_for_offers, ...profile.missing_for_quotes])] : [];
  const verified = profile?.registration_verification.status === 'verified';

  return (
    <div className="with-aside with-aside--right">
      <form
        className="card form-stack"
        onSubmit={(event) => {
          event.preventDefault();
          void save();
        }}
      >
        <h2 style={{ margin: 0 }}>Entreprise</h2>
        {field('legal_name', 'Dénomination ou nom', { required: true, max: 160, hint: 'Tel qu’inscrit au registre (nom et prénom pour un entrepreneur individuel).' })}
        {field('trade_name', 'Nom commercial', { max: 160 })}
        <div className="field">
          <label className="field__label" htmlFor="legal-form">
            Forme juridique
          </label>
          <select id="legal-form" className="select" value={fields.legal_form} onChange={(event) => setFields({ ...fields, legal_form: event.target.value })}>
            {LEGAL_FORMS.map((item) => (
              <option key={item.value} value={item.value}>
                {item.label}
              </option>
            ))}
          </select>
          {error?.field('legal_form') ? <span className="field__error">{error.field('legal_form')}</span> : null}
        </div>
        {field('registration_number', france ? 'SIREN' : 'Numéro d’immatriculation', { required: true, max: 40, hint: france ? '9 chiffres.' : undefined })}
        <div className="card-grid card-grid--2" style={{ gap: 16 }}>
          <div className="field">
            <label className="field__label" htmlFor="registry">
              Registre
            </label>
            <select id="registry" className="select" value={fields.registry} onChange={(event) => setFields({ ...fields, registry: event.target.value })}>
              <option value="rne">Registre national des entreprises (RNE)</option>
              <option value="rcs">Registre du commerce et des sociétés (RCS)</option>
            </select>
          </div>
          {fields.registry === 'rcs' ? field('registry_city', 'Ville du greffe', { required: true, max: 100 }) : <span />}
        </div>
        {form?.company ? field('share_capital', 'Capital social (€)', { required: true, max: 20, errorKey: 'share_capital_cents' }) : null}
        {field('vat_number', 'Numéro de TVA intracommunautaire', { max: 40 })}

        <h2 style={{ margin: '16px 0 0' }}>Coordonnées professionnelles</h2>
        <p className="field__hint" style={{ marginTop: -8 }}>
          Elles figurent sur vos devis. Elles ne sont jamais affichées sur votre page publique.
        </p>
        {field('address_line1', 'Adresse', { required: true })}
        {field('address_line2', 'Complément d’adresse')}
        <div className="card-grid card-grid--2" style={{ gap: 16 }}>
          {field('postal_code', 'Code postal', { required: true, max: 20 })}
          {field('city', 'Ville', { required: true, max: 100 })}
        </div>
        <CountrySelect id="seller-country" label="Pays" value={fields.country} onChange={(country) => setFields({ ...fields, country })} error={error?.field('country')} />
        <div className="card-grid card-grid--2" style={{ gap: 16 }}>
          {field('phone', 'Téléphone', { required: true, max: 30, type: 'tel' })}
          {field('email', 'Email professionnel', { required: true, max: 191, type: 'email' })}
        </div>

        <h2 style={{ margin: '16px 0 0' }}>Assurance professionnelle</h2>
        {field('insurer_name', 'Assureur', { max: 160 })}
        {fields.insurer_name.trim() ? (
          <>
            {field('insurer_contact', 'Coordonnées de l’assureur', { required: true, max: 300 })}
            {field('insurance_coverage', 'Couverture géographique', { required: true, max: 200 })}
            <label className="checkbox">
              <input type="checkbox" checked={tenYear} onChange={(event) => setTenYear(event.target.checked)} />
              <span>Assurance décennale</span>
            </label>
          </>
        ) : null}

        <h2 style={{ margin: '16px 0 0' }}>Médiateur de la consommation</h2>
        <p className="field__hint" style={{ marginTop: -8 }}>
          Obligatoire pour envoyer un devis à un particulier.
        </p>
        {field('mediator_name', 'Nom du médiateur', { max: 160 })}
        {field('mediator_url', 'Site du médiateur', { max: 500, type: 'url', hint: 'Adresse en https://' })}

        <h2 style={{ margin: '16px 0 0' }}>Profession réglementée</h2>
        {field('regulated_profession_title', 'Titre professionnel', { max: 160 })}
        {fields.regulated_profession_title.trim() ? (
          <>
            {field('regulated_profession_state', 'État où le titre a été obtenu', { required: true, max: 100 })}
            {field('regulated_profession_body', 'Ordre ou organisme d’inscription', { required: true, max: 200 })}
          </>
        ) : null}

        <h2 style={{ margin: '16px 0 0' }}>Documents</h2>
        {field('terms_url', 'Lien vers vos conditions générales de vente', { max: 500, type: 'url', hint: 'Adresse en https://' })}
        <TextAreaField
          label="Mentions personnalisées des devis (facultatif, Premium)"
          value={fields.quote_footer}
          rows={3}
          maxLength={1000}
          counter
          onChange={(event) => setFields({ ...fields, quote_footer: event.target.value })}
          error={error?.field('quote_footer')}
        />

        {version && version !== SELF_CERTIFICATION_VERSION ? (
          <p className="alert alert--info">La déclaration sur l&apos;honneur a changé : relisez-la avant de la renouveler.</p>
        ) : null}
        <label className="checkbox" style={{ marginTop: 8 }}>
          <input type="checkbox" checked={certified} onChange={(event) => setCertified(event.target.checked)} />
          <span>{SELF_CERTIFICATION_TEXT}</span>
        </label>
        {error?.field('self_certification') || error?.field('self_certification_version') ? (
          <span className="field__error">{error.field('self_certification') ?? error.field('self_certification_version')}</span>
        ) : null}

        {notice ? <p className="alert alert--success">{notice}</p> : null}
        <FormError message={error && Object.keys(error.errors).length === 0 ? error.message : error ? 'Certains champs sont à corriger.' : null} />
        <button type="submit" className="btn btn--lime btn--lg" disabled={saving || !certified}>
          {saving ? 'Enregistrement…' : 'Enregistrer'}
        </button>
        <p className="disclaimer" style={{ textAlign: 'left' }}>
          Le SIREN et le numéro de TVA sont contrôlés dans leur forme. La déclaration sur l&apos;honneur est datée et doit être renouvelée à chaque
          modification.
        </p>
      </form>

      <aside className="aside-stack">
        <div className="card aside-card">
          <h3>État</h3>
          {!profile ? (
            <p>Aucune identité enregistrée pour le moment.</p>
          ) : missing.length === 0 ? (
            <p>Votre identité est complète : vous pouvez publier des offres et envoyer des devis.</p>
          ) : (
            <>
              <p>Il manque encore :</p>
              <ul className="missing-list">
                {missing.map((key) => (
                  <li key={key}>{SELLER_FIELD_LABELS[key] ?? key}</li>
                ))}
              </ul>
            </>
          )}
          {profile && profile.missing_for_consumer_quotes.length > 0 ? (
            <p className="field__hint" style={{ marginTop: 12 }}>
              Pour envoyer un devis à un particulier, indiquez aussi un médiateur de la consommation.
            </p>
          ) : null}
        </div>
        {verified ? (
          <div className="card aside-card">
            <span className="badge-verified">Entreprise immatriculée vérifiée</span>
            <p style={{ marginTop: 12 }}>
              L&apos;immatriculation a été contrôlée auprès du registre public de l&apos;État. Ce badge ne porte pas sur la qualité du travail.
            </p>
          </div>
        ) : null}
      </aside>
    </div>
  );
}
