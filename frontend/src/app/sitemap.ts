import type { MetadataRoute } from 'next';
import { apiOrNull } from '@/lib/api';
import { SITE_URL } from '@/lib/site';

export const revalidate = 3600;

type Page<T> = { data: T[] } | null;

/**
 * Plan du site : pages publiques fixes, articles (GET /articles/sitemap) et premières pages des
 * prestataires, offres, événements et projets publics. Aucune page privée.
 */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const fixed = ['', '/projets', '/prestataires', '/offres', '/articles', '/evenements', '/premium', '/devenir-prestataire', '/publier', '/legal/terms', '/legal/privacy'];
  const [articles, providers, offers, events, projects] = await Promise.all([
    apiOrNull<Page<{ slug: string; updated_at: string | null }>>('articles/sitemap'),
    apiOrNull<Page<{ slug: string }>>('providers', { query: { per_page: 50 } }),
    apiOrNull<Page<{ slug: string }>>('offers', { query: { per_page: 50 } }),
    apiOrNull<Page<{ slug: string }>>('events', { query: { per_page: 50 } }),
    apiOrNull<Page<{ uuid: string; published_at: string | null }>>('public/projects', { query: { per_page: 50 } }),
  ]);

  return [
    ...fixed.map((path) => ({ url: `${SITE_URL}${path}`, changeFrequency: 'daily' as const })),
    ...(articles?.data ?? []).map((item) => ({ url: `${SITE_URL}/articles/${item.slug}`, lastModified: item.updated_at ?? undefined })),
    ...(providers?.data ?? []).filter((item) => item.slug).map((item) => ({ url: `${SITE_URL}/prestataires/${item.slug}` })),
    ...(offers?.data ?? []).filter((item) => item.slug).map((item) => ({ url: `${SITE_URL}/offres/${item.slug}` })),
    ...(events?.data ?? []).filter((item) => item.slug).map((item) => ({ url: `${SITE_URL}/evenements/${item.slug}` })),
    ...(projects?.data ?? []).map((item) => ({ url: `${SITE_URL}/projets/${item.uuid}`, lastModified: item.published_at ?? undefined })),
  ];
}
