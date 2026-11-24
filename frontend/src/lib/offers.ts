import type { DeliveryMode, PricingMode } from './types';

const euro = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 2, minimumFractionDigits: 0 });

export const PRICING_LABELS: Record<PricingMode, string> = {
  fixed: 'Prix forfaitaire',
  from: 'À partir de',
  hourly: 'Tarif horaire',
  daily: 'Tarif journalier',
  on_quote: 'Sur devis',
};

export const DELIVERY_LABELS: Record<DeliveryMode, string> = {
  on_site: 'Sur place',
  remote: 'À distance',
  both: 'Sur place ou à distance',
};

/** Prix indicatif TTC saisi par le prestataire (jamais recalculé ici). */
export function offerPrice(mode: PricingMode, cents: number | null, currency = 'EUR'): string {
  if (mode === 'on_quote' || cents === null) return 'Sur devis';
  const amount = currency === 'EUR' ? euro.format(cents / 100) : `${(cents / 100).toLocaleString('fr-FR')} ${currency}`;
  if (mode === 'from') return `À partir de ${amount} TTC`;
  if (mode === 'hourly') return `${amount} TTC / heure`;
  if (mode === 'daily') return `${amount} TTC / jour`;
  return `${amount} TTC`;
}

/** Saisie « 1 234,50 » → centimes ; null si invalide. */
export function parseEuros(value: string): number | null {
  const normalized = value.replace(/\s/g, '').replace(',', '.');
  if (!/^\d+(\.\d{1,2})?$/.test(normalized)) return null;
  return Math.round(Number(normalized) * 100);
}

export function centsToInput(cents: number | null): string {
  if (cents === null) return '';
  return (cents / 100).toLocaleString('fr-FR', { useGrouping: false, maximumFractionDigits: 2 });
}

/** Formes juridiques de l'identité professionnelle (SellerLegalForm), libellés de l'API. */
export const LEGAL_FORMS: Array<{ value: string; label: string; company: boolean }> = [
  { value: 'ei', label: 'Entrepreneur individuel (EI)', company: false },
  { value: 'ei_micro', label: 'Entrepreneur individuel (EI), micro-entreprise', company: false },
  { value: 'eurl', label: 'EURL', company: true },
  { value: 'sarl', label: 'SARL', company: true },
  { value: 'sasu', label: 'SASU', company: true },
  { value: 'sas', label: 'SAS', company: true },
  { value: 'sa', label: 'SA', company: true },
  { value: 'snc', label: 'SNC', company: true },
  { value: 'association', label: 'Association', company: false },
  { value: 'other', label: 'Autre forme', company: false },
];

/** Libellés des champs manquants renvoyés par l'API (missing_for_*). */
export const SELLER_FIELD_LABELS: Record<string, string> = {
  legal_name: 'dénomination',
  legal_form: 'forme juridique',
  registration_number: 'SIREN',
  registry: 'registre',
  registry_city: 'ville du greffe',
  share_capital_cents: 'capital social',
  address_line1: 'adresse',
  postal_code: 'code postal',
  city: 'ville',
  country: 'pays',
  phone: 'téléphone',
  email: 'email professionnel',
  self_certification: 'déclaration sur l’honneur à jour',
  mediator_name: 'médiateur de la consommation',
  mediator_url: 'site du médiateur',
};
