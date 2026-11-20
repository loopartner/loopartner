/**
 * Client de l'API Laravel (`/api/v1`), en mode SPA de Sanctum :
 * - session par cookie, envoyée avec `credentials: 'include'` ;
 * - avant toute écriture, `GET /sanctum/csrf-cookie`, puis l'en-tête `X-XSRF-TOKEN` lu dans le
 *   cookie `XSRF-TOKEN` ;
 * - les erreurs deviennent des `ApiError` qui gardent le code stable de l'API
 *   (`consent_required`, `premium_required`, `email_not_verified`…) et les erreurs de champ.
 *
 * Utilisable côté navigateur (pages connectées) et côté serveur (pages publiques), sans cookie.
 */

import { DEMO_MODE, DemoError, demoApi } from './demo';

export const API_URL = (process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000').replace(/\/+$/, '');

export type FieldErrors = Record<string, string[]>;

export class ApiError extends Error {
  readonly status: number;
  readonly code: string | null;
  readonly errors: FieldErrors;
  readonly body: Record<string, unknown>;

  constructor(status: number, body: Record<string, unknown>) {
    super(typeof body.message === 'string' ? body.message : ApiError.defaultMessage(status));
    this.status = status;
    this.code = typeof body.code === 'string' ? body.code : null;
    this.errors = (body.errors as FieldErrors | undefined) ?? {};
    this.body = body;
  }

  /** Premier message d'erreur d'un champ, s'il existe. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0];
  }

  static defaultMessage(status: number): string {
    if (status === 0) return 'Impossible de joindre le serveur. Vérifiez votre connexion et réessayez.';
    if (status === 401) return 'Votre session a expiré. Connectez-vous à nouveau.';
    if (status === 403) return "Vous n'avez pas accès à cette action.";
    if (status === 404) return 'Élément introuvable.';
    if (status === 409) return "Cette action n'est pas possible dans l'état actuel.";
    if (status === 419) return 'La page a expiré. Rechargez-la et réessayez.';
    if (status === 429) return 'Trop de tentatives. Patientez un peu avant de réessayer.';
    if (status >= 500) return 'Une erreur est survenue de notre côté. Réessayez dans un instant.';
    return 'La demande a échoué.';
  }
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export interface RequestOptions {
  method?: Method;
  body?: unknown;
  query?: Record<string, string | number | boolean | null | undefined>;
  /** Côté serveur : délai de mise en cache des pages publiques, en secondes. */
  revalidate?: number;
  signal?: AbortSignal;
}

const isBrowser = typeof window !== 'undefined';

function readCookie(name: string): string | null {
  if (!isBrowser) return null;
  const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

let csrfReady: Promise<void> | null = null;

/** Demande le cookie CSRF de Sanctum une fois par chargement de page (ou après un 419). */
export function ensureCsrf(force = false): Promise<void> {
  if (!isBrowser) return Promise.resolve();
  if (force || csrfReady === null || readCookie('XSRF-TOKEN') === null) {
    csrfReady = fetch(`${API_URL}/sanctum/csrf-cookie`, { credentials: 'include' }).then(() => undefined);
  }
  return csrfReady;
}

function buildUrl(path: string, query?: RequestOptions['query']): string {
  const url = new URL(`${API_URL}/api/v1/${path.replace(/^\/+/, '')}`);
  if (query) {
    for (const [key, value] of Object.entries(query)) {
      if (value !== null && value !== undefined && value !== '') url.searchParams.set(key, String(value));
    }
  }
  return url.toString();
}

async function send<T>(path: string, options: RequestOptions, retried: boolean): Promise<T> {
  const method = options.method ?? 'GET';

  // Mode démonstration (aperçu Vercel) : réponses fictives, aucun appel réseau.
  if (DEMO_MODE) {
    try {
      return (await Promise.resolve(demoApi(path, method, options.query ?? {}, options.body))) as T;
    } catch (error) {
      if (error instanceof DemoError) throw new ApiError(error.status, error.body);
      throw error;
    }
  }
  const isForm = typeof FormData !== 'undefined' && options.body instanceof FormData;

  if (method !== 'GET') await ensureCsrf();

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Accept-Language': 'fr-FR',
    'X-Requested-With': 'XMLHttpRequest',
  };
  if (options.body !== undefined && !isForm) headers['Content-Type'] = 'application/json';
  const xsrf = readCookie('XSRF-TOKEN');
  if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;

  let response: Response;
  try {
    response = await fetch(buildUrl(path, options.query), {
      method,
      headers,
      credentials: 'include',
      body: options.body === undefined ? undefined : isForm ? (options.body as FormData) : JSON.stringify(options.body),
      signal: options.signal,
      ...(isBrowser ? { cache: 'no-store' as const } : { next: { revalidate: options.revalidate ?? 60 } }),
    });
  } catch (error) {
    if ((error as Error).name === 'AbortError') throw error;
    throw new ApiError(0, {});
  }

  // Jeton CSRF expiré : on le renouvelle une seule fois puis on rejoue la requête.
  if (response.status === 419 && !retried && isBrowser) {
    await ensureCsrf(true);
    return send<T>(path, options, true);
  }

  if (response.status === 204) return undefined as T;

  const text = await response.text();
  let body: Record<string, unknown> = {};
  if (text) {
    try {
      body = JSON.parse(text) as Record<string, unknown>;
    } catch {
      body = {};
    }
  }

  if (!response.ok) {
    const error = new ApiError(response.status, body);
    if (isBrowser && error.code === 'consent_required') {
      window.dispatchEvent(new CustomEvent('loopartner:consent-required', { detail: body }));
    }
    throw error;
  }

  return body as T;
}

export function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  return send<T>(path, options, false);
}

/** Variante pour les pages publiques rendues côté serveur : renvoie null si l'API ne répond pas. */
export async function apiOrNull<T>(path: string, options: RequestOptions = {}): Promise<T | null> {
  try {
    return await api<T>(path, options);
  } catch {
    return null;
  }
}

/** Requête d'authentification Fortify (hors `/api/v1`, sous `/api/v1/auth`). */
export function auth<T>(path: string, body?: unknown): Promise<T> {
  return api<T>(`auth/${path.replace(/^\/+/, '')}`, { method: 'POST', body });
}
