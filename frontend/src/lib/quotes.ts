/**
 * Devis (module Marketplace, `/me/quotes`) : types, libellés et aperçu des totaux.
 *
 * L'aperçu reprend la méthode du serveur (Support/QuoteCalculator) : entiers seulement, HT de
 * chaque ligne arrondi au centime, TVA par taux sur la somme HT de ce taux, demi-centime vers le
 * haut. Il ne sert qu'à l'affichage pendant la saisie : les totaux qui font foi sont ceux que
 * renvoie l'API. Aucun taux de TVA n'est écrit ici : ils viennent de `GET /me/quotes/vat-rates`.
 */

export type QuoteStatus = 'draft' | 'sent' | 'accepted' | 'declined' | 'withdrawn' | 'expired' | 'superseded';
export type QuoteUnit = 'unit' | 'hour' | 'day' | 'half_day' | 'lump_sum' | 'm2' | 'm' | 'm3' | 'kg' | 'month';
export type QuoteLineType = 'labour' | 'materials' | 'travel' | 'equipment' | 'disposal' | 'service' | 'other';
export type DeclineReason = 'price' | 'timing' | 'scope' | 'other_provider' | 'project_cancelled' | 'incorrect_details' | 'other';

export interface VatRateOption {
  code: string;
  basis_points: number;
  label: string;
  scope: string;
  exclusive: boolean;
  requires_certification: boolean;
}

export interface QuoteLineInput {
  line_type: QuoteLineType;
  designation: string;
  details: string | null;
  quantity_milli: number;
  unit: QuoteUnit;
  unit_price_cents: number;
  vat_rate_code: string;
}

export interface QuoteClientBlock {
  name: string;
  company_name: string | null;
  registration_number: string | null;
  address: string | null;
  execution_place: string;
}

export interface QuoteDraftInput {
  match_request?: string;
  title: string;
  scope: string;
  client: QuoteClientBlock;
  validity_days: number;
  start_date: string | null;
  duration: string | null;
  payment_terms: string | null;
  deposit_cents: number | null;
  deposit_kind: 'acompte' | 'arrhes' | null;
  quote_is_free: boolean;
  quote_fee_cents: number | null;
  keep_replaced_parts: boolean | null;
  notes: string | null;
  lines: QuoteLineInput[];
}

export interface Translated {
  fr: string | null;
  en: string | null;
}

export interface Quote {
  uuid: string;
  number: string | null;
  version: number;
  status: QuoteStatus;
  viewer_role: 'provider' | 'client';
  client_kind: 'consumer' | 'business';
  title: string;
  scope: string | null;
  offer: { title: string } | null;
  provider: { deleted?: boolean; display_name?: string; slug?: string | null; logo_url?: string | null } | null;
  issuer: Record<string, unknown> | null;
  client: QuoteClientBlock | null;
  currency: string;
  lines:
    | Array<{
        position: number;
        line_type: QuoteLineType;
        designation: string;
        details: string | null;
        quantity_milli: number;
        unit: QuoteUnit;
        unit_price_cents: number;
        vat: { code: string; basis_points: number | null; label: Translated | string | null };
        total_ht_cents: number;
      }>
    | null;
  totals: { ht_cents: number; vat_cents: number; ttc_cents: number; vat_breakdown: Array<{ code: string; basis_points: number; base_ht_cents: number; vat_cents: number }> } | null;
  validity: { days: number; valid_until: string | null; expires_at: string | null };
  start_date: string | null;
  duration: string | null;
  payment_terms: string | null;
  deposit: { cents: number; kind: 'acompte' | 'arrhes' | null } | null;
  quote_fee: { is_free: boolean; cents: number | null };
  keep_replaced_parts: boolean | null;
  notes: string | null;
  mentions: {
    vat: Array<{ code: string; text: Translated }>;
    certifications: Array<{ code: string; text: Translated }>;
    consumer: { withdrawal_days: number; withdrawal_ends_at: string | null } | null;
    not_a_party: string;
  };
  content_hash: string | null;
  decline: { reason: DeclineReason | null; comment: string | null } | null;
  acceptance: { accepted_at: string; express_execution: boolean; certifications: string[]; withdrawal_exercised_at: string | null } | null;
  moderation?: { restricted: boolean } | null;
  sent_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  /** Demande de mise en relation (mode démonstration seulement). */
  match_request?: string;
}

export const STATUS_LABELS: Record<QuoteStatus, string> = {
  draft: 'Brouillon',
  sent: 'Envoyé',
  accepted: 'Accepté',
  declined: 'Refusé',
  withdrawn: 'Retiré',
  expired: 'Expiré',
  superseded: 'Remplacé',
};

/** Classe de pastille de statut (styles .status--* existants). */
export const STATUS_CLASS: Record<QuoteStatus, string> = {
  draft: 'status--withdrawn',
  sent: 'status--pending_review',
  accepted: 'status--accepted',
  declined: 'status--declined',
  withdrawn: 'status--withdrawn',
  expired: 'status--expired',
  superseded: 'status--withdrawn',
};

export const UNIT_LABELS: Record<QuoteUnit, string> = {
  unit: 'Unité',
  hour: 'Heure',
  day: 'Jour',
  half_day: 'Demi-journée',
  lump_sum: 'Forfait',
  m2: 'm²',
  m: 'm',
  m3: 'm³',
  kg: 'kg',
  month: 'Mois',
};

/** Unité accordée à la quantité (« 2 heures », « 1 forfait », « 3 m² »). */
export function unitLabel(unit: QuoteUnit, quantityMilli: number): string {
  const label = UNIT_LABELS[unit].toLowerCase();
  const plural = quantityMilli >= 2000 && ['unit', 'hour', 'day', 'half_day', 'lump_sum'].includes(unit);
  return plural ? label.replace(/^(\S+)/, '$1s') : label;
}

export const LINE_TYPE_LABELS: Record<QuoteLineType, string> = {
  service: 'Prestation',
  labour: "Main-d'œuvre",
  materials: 'Fournitures',
  equipment: 'Matériel',
  travel: 'Déplacement',
  disposal: 'Évacuation des déchets',
  other: 'Autre',
};

export const DECLINE_LABELS: Record<DeclineReason, string> = {
  price: 'Le prix',
  timing: 'Les délais',
  scope: 'Le contenu ne correspond pas',
  other_provider: "J'ai choisi un autre prestataire",
  project_cancelled: 'Le projet est abandonné',
  incorrect_details: 'Des informations sont erronées',
  other: 'Autre raison',
};

/** Division entière arrondie, demi vers le haut (valeurs positives). */
export function roundedDivision(numerator: number, denominator: number): number {
  return Math.floor((2 * numerator + denominator) / (2 * denominator));
}

export function lineTotalHt(line: Pick<QuoteLineInput, 'quantity_milli' | 'unit_price_cents'>): number {
  return roundedDivision(line.quantity_milli * line.unit_price_cents, 1000);
}

export function previewTotals(lines: QuoteLineInput[], rates: VatRateOption[]) {
  const byCode = new Map<string, { basis_points: number; base: number }>();
  let ht = 0;
  for (const line of lines) {
    const total = lineTotalHt(line);
    ht += total;
    const rate = rates.find((item) => item.code === line.vat_rate_code);
    if (!rate) continue;
    const entry = byCode.get(rate.code) ?? { basis_points: rate.basis_points, base: 0 };
    entry.base += total;
    byCode.set(rate.code, entry);
  }
  let vat = 0;
  const breakdown = [...byCode.entries()].map(([code, entry]) => {
    const amount = roundedDivision(entry.base * entry.basis_points, 10000);
    vat += amount;
    return { code, basis_points: entry.basis_points, base_ht_cents: entry.base, vat_cents: amount };
  });
  return { ht_cents: ht, vat_cents: vat, ttc_cents: ht + vat, vat_breakdown: breakdown };
}

export function formatCents(cents: number | null | undefined, currency = 'EUR'): string {
  if (cents === null || cents === undefined) return '—';
  return (cents / 100).toLocaleString('fr-FR', { style: 'currency', currency });
}

export function formatQuantity(milli: number): string {
  return (milli / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 3 });
}

export function formatRate(basisPoints: number | null | undefined): string {
  if (basisPoints === null || basisPoints === undefined) return '—';
  return `${(basisPoints / 100).toLocaleString('fr-FR', { maximumFractionDigits: 2 })} %`;
}

/** Saisie « 1 250,50 » ou « 1250.5 » → centimes (null si invalide). */
export function parseEuros(value: string): number | null {
  const clean = value.replace(/\s/g, '').replace(',', '.');
  if (!/^\d+(\.\d{0,2})?$/.test(clean)) return null;
  const [units, decimals = ''] = clean.split('.');
  return Number(units) * 100 + Number(decimals.padEnd(2, '0'));
}

/** Saisie « 2,5 » → millièmes (null si invalide). */
export function parseQuantity(value: string): number | null {
  const clean = value.replace(/\s/g, '').replace(',', '.');
  if (!/^\d+(\.\d{0,3})?$/.test(clean)) return null;
  const [units, decimals = ''] = clean.split('.');
  return Number(units) * 1000 + Number(decimals.padEnd(3, '0'));
}

export function centsToInput(cents: number | null | undefined): string {
  if (cents === null || cents === undefined) return '';
  return (cents / 100).toLocaleString('fr-FR', { minimumFractionDigits: cents % 100 === 0 ? 0 : 2, useGrouping: false });
}

export function quantityToInput(milli: number): string {
  return (milli / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 3, useGrouping: false });
}

export function translated(value: Translated | string | null | undefined): string {
  if (!value) return '';
  return typeof value === 'string' ? value : (value.fr ?? value.en ?? '');
}

/** Contenu d'un brouillon à partir d'un devis renvoyé par l'API (pour le modifier). */
export function draftFromQuote(quote: Quote): QuoteDraftInput {
  return {
    title: quote.title,
    scope: quote.scope ?? '',
    client: quote.client ?? { name: '', company_name: null, registration_number: null, address: null, execution_place: '' },
    validity_days: quote.validity.days,
    start_date: quote.start_date,
    duration: quote.duration,
    payment_terms: quote.payment_terms,
    deposit_cents: quote.deposit?.cents ?? null,
    deposit_kind: quote.deposit?.kind ?? null,
    quote_is_free: quote.quote_fee.is_free,
    quote_fee_cents: quote.quote_fee.cents,
    keep_replaced_parts: quote.keep_replaced_parts,
    notes: quote.notes,
    lines: (quote.lines ?? []).map((line) => ({
      line_type: line.line_type,
      designation: line.designation,
      details: line.details,
      quantity_milli: line.quantity_milli,
      unit: line.unit,
      unit_price_cents: line.unit_price_cents,
      vat_rate_code: line.vat.code,
    })),
  };
}
