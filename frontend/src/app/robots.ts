import type { MetadataRoute } from 'next';
import { DEMO_MODE } from '@/lib/demo';
import { SITE_URL } from '@/lib/site';

/** Pages privées exclues de l'indexation ; tout est exclu sur les aperçus de démonstration. */
export default function robots(): MetadataRoute.Robots {
  if (DEMO_MODE) return { rules: [{ userAgent: '*', disallow: '/' }] };
  return {
    rules: [
      {
        userAgent: '*',
        allow: '/',
        disallow: ['/espace', '/compte', '/messages', '/agenda', '/demandes', '/mes-evenements', '/articles/rediger', '/evenements/proposer', '/publier/confirmation', '/connexion', '/inscription', '/mot-de-passe-oublie', '/nouveau-mot-de-passe'],
      },
    ],
    sitemap: `${SITE_URL}/sitemap.xml`,
  };
}
