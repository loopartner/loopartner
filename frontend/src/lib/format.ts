/** Mise en forme des données de l'API pour l'affichage (dates en UTC converties à Paris). */

export const DISPLAY_TIMEZONE = 'Europe/Paris';

const euro = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0, useGrouping: 'always' });

/** Budget d'un projet, en unités entières : « 500 – 1 000 € », « Moins de 300 € »… */
export function formatBudget(min: number | null, max: number | null, currency = 'EUR'): string | null {
  const symbol = currency === 'EUR' ? '€' : currency;
  if (min === null && max === null) return null;
  if (min !== null && max !== null) {
    return min === max ? `${euro.format(min)} ${symbol}` : `${euro.format(min)} – ${euro.format(max)} ${symbol}`;
  }
  if (max !== null) return `Moins de ${euro.format(max)} ${symbol}`;
  return `${euro.format(min as number)} ${symbol} et plus`;
}

const relative = new Intl.RelativeTimeFormat('fr', { numeric: 'auto' });

/** « Il y a 21 minutes », « Hier », « Il y a 3 jours »… */
export function timeAgo(iso: string | null, now: Date = new Date()): string {
  if (!iso) return '';
  const seconds = Math.round((new Date(iso).getTime() - now.getTime()) / 1000);
  const abs = Math.abs(seconds);
  const capitalize = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);
  if (abs < 60) return "À l'instant";
  if (abs < 3600) return capitalize(relative.format(Math.round(seconds / 60), 'minute'));
  if (abs < 86400) return capitalize(relative.format(Math.round(seconds / 3600), 'hour'));
  if (abs < 86400 * 30) return capitalize(relative.format(Math.round(seconds / 86400), 'day'));
  return `Le ${formatDate(iso)}`;
}

export function formatDate(iso: string, options: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'long', year: 'numeric' }): string {
  return new Intl.DateTimeFormat('fr-FR', { timeZone: DISPLAY_TIMEZONE, ...options }).format(new Date(iso));
}

export function formatTime(iso: string, timeZone = DISPLAY_TIMEZONE): string {
  return new Intl.DateTimeFormat('fr-FR', { timeZone, hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}

/** Jour et mois abrégé pour les pastilles de date (« 14 », « DÉC. »). */
export function dateBadge(iso: string, timeZone = DISPLAY_TIMEZONE): { day: string; month: string } {
  const date = new Date(iso);
  return {
    day: new Intl.DateTimeFormat('fr-FR', { timeZone, day: 'numeric' }).format(date),
    month: new Intl.DateTimeFormat('fr-FR', { timeZone, month: 'short' }).format(date).toUpperCase(),
  };
}

/** Durée entre deux dates : « 45 min », « 1 h 30 ». */
export function duration(startIso: string, endIso: string): string {
  const minutes = Math.max(0, Math.round((new Date(endIso).getTime() - new Date(startIso).getTime()) / 60000));
  if (minutes < 60) return `${minutes} min`;
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  return rest === 0 ? `${hours} h` : `${hours} h ${String(rest).padStart(2, '0')}`;
}

const LANGUAGES: Record<string, string> = { fr: 'Français', en: 'Anglais', es: 'Espagnol', de: 'Allemand', it: 'Italien' };

export function languageName(code: string): string {
  return LANGUAGES[code] ?? code.toUpperCase();
}

const FORMATS: Record<string, string> = { online: 'En ligne', in_person: 'En présentiel', hybrid: 'Hybride' };

export function eventFormatName(format: string): string {
  return FORMATS[format] ?? format;
}

const COUNTRIES: Record<string, string> = {
  FR: 'France', BE: 'Belgique', CH: 'Suisse', LU: 'Luxembourg', MC: 'Monaco', DE: 'Allemagne', AT: 'Autriche', ES: 'Espagne', IT: 'Italie',
  PT: 'Portugal', NL: 'Pays-Bas', IE: 'Irlande', PL: 'Pologne', SE: 'Suède', DK: 'Danemark', FI: 'Finlande', GR: 'Grèce', CZ: 'Tchéquie',
  RO: 'Roumanie', GB: 'Royaume-Uni', CA: 'Canada',
};

export function countryName(code: string | null): string {
  if (!code) return '';
  return COUNTRIES[code] ?? code;
}

export function initials(name: string | null | undefined): string {
  if (!name) return '?';
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('');
}

/** Nombre de caractères (pas d'octets) pour les compteurs des champs. */
export function length(value: string): number {
  return Array.from(value).length;
}
