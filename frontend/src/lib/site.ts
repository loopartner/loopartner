/** Adresse publique du site (sans slash final), pour les liens absolus, robots.txt et sitemap.xml. */
export const SITE_URL = (process.env.NEXT_PUBLIC_SITE_URL ?? 'http://localhost:3000').replace(/\/+$/, '');
