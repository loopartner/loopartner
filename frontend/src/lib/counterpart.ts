import type { Counterpart } from './types';

/** Nom affiché d'un interlocuteur, sans jamais inventer d'information. */
export function counterpartName(counterpart: Counterpart): string {
  if (counterpart.deleted) return 'Compte supprimé';
  if (counterpart.provider) return counterpart.provider.display_name;
  if (counterpart.name) return counterpart.name;
  return counterpart.account_type === 'entrepreneur' ? 'Un entrepreneur' : 'Un particulier';
}

export function counterpartDetail(counterpart: Counterpart): string | null {
  if (counterpart.deleted) return null;
  if (counterpart.provider) return counterpart.provider.headline;
  return counterpart.account_type === 'entrepreneur' ? 'Entrepreneur' : 'Particulier';
}

export const REQUEST_STATUS_LABELS = {
  pending: 'En attente',
  accepted: 'Acceptée',
  declined: 'Refusée',
  withdrawn: 'Retirée',
  expired: 'Expirée',
} as const;

/** Motifs d'un signalement privé (message ou demande), liste fermée de l'API (ReportReason). */
export const PRIVATE_REPORT_REASONS = [
  { key: 'spam', label: 'Spam ou démarchage abusif' },
  { key: 'harassment', label: 'Harcèlement ou propos insultants' },
  { key: 'scam', label: 'Arnaque ou tentative de fraude' },
  { key: 'inappropriate', label: 'Contenu inapproprié' },
  { key: 'off_platform', label: 'Demande de paiement ou de contact hors de Loopartner' },
  { key: 'other', label: 'Autre motif' },
] as const;
