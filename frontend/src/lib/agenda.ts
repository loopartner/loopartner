import type { IconName } from '@/components/Icon';
import { DISPLAY_TIMEZONE } from './format';

/** Élément de l'agenda unifié (`GET /me/agenda`), dates en UTC. */
export interface AgendaItem {
  source: string;
  kind: string;
  ref: string;
  title: string;
  all_day: boolean;
  date: string | null;
  starts_at: string;
  ends_at: string;
  status: string;
  role: string | null;
}

export interface AgendaResponse {
  data: AgendaItem[];
  disclaimers?: Record<string, { text: string; version?: string }>;
}

/** Sources de l'agenda, dans l'ordre d'affichage des filtres. */
export const AGENDA_SOURCES = ['appointments', 'events', 'compliance', 'workspaces'] as const;

export const SOURCE_LABELS: Record<string, string> = {
  appointments: 'Rendez-vous',
  events: 'Événements',
  compliance: 'Échéances',
  workspaces: 'Étapes de projet',
};

export const SOURCE_ICONS: Record<string, IconName> = {
  appointments: 'calendar',
  events: 'video',
  compliance: 'file',
  workspaces: 'check',
};

/** Libellé court d'un élément (singulier), selon son genre. */
export function kindLabel(item: AgendaItem): string {
  switch (item.kind) {
    case 'event_registration':
    case 'registration':
      return 'Inscrit';
    case 'event_organized':
      return 'Organisé par vous';
    case 'deadline':
      return 'Échéance';
    case 'appointment':
      return 'Rendez-vous';
    default:
      return SOURCE_LABELS[item.source] ?? 'Agenda';
  }
}

/** Lien vers la page de l'élément, quand il en a une. */
export function itemHref(item: AgendaItem): string | null {
  if (item.source === 'events') return `/evenements/${encodeURIComponent(item.ref)}`;
  return null;
}

/** Journée (AAAA-MM-JJ) d'une date dans le fuseau d'affichage. */
export function dayKey(date: Date, timeZone = DISPLAY_TIMEZONE): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
}

/** Journée d'un élément : sa date pour une journée entière, sinon son début dans le fuseau d'affichage. */
export function itemDay(item: AgendaItem): string {
  return item.all_day && item.date ? item.date : dayKey(new Date(item.starts_at));
}

/** Journée UTC (AAAA-MM-JJ) attendue par l'API. */
export function utcDay(date: Date): string {
  return date.toISOString().slice(0, 10);
}
