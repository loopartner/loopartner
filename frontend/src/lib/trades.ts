import type { IconName } from '@/components/Icon';

/**
 * Familles de métiers mises en avant sur le site (décision 84). Les slugs sont ceux des
 * catégories parentes de l'API (`ServiceCategorySeeder`) : une famille absente de
 * GET /categories n'est pas affichée.
 */
export const TRADE_FAMILIES: Array<{ slug: string; label: string; icon: IconName; examples: string }> = [
  { slug: 'developpement-web', label: 'Développement et tech', icon: 'globe', examples: 'Sites, applications, logiciels, automatisation, data et IA' },
  { slug: 'design', label: 'Design', icon: 'image', examples: 'Logo et identité visuelle, UI/UX, packaging, motion design' },
  { slug: 'arts-contenus', label: 'Arts et contenus', icon: 'video', examples: 'Illustration, photo, vidéo, musique, rédaction, voix off' },
  { slug: 'marketing-communication', label: 'Marketing', icon: 'send', examples: 'Stratégie, réseaux sociaux, SEO, publicité, relations presse' },
  { slug: 'juridique', label: 'Juridique', icon: 'shield', examples: 'Statuts, contrats et CGV, propriété intellectuelle' },
  { slug: 'comptabilite-fiscalite', label: 'Comptabilité et fiscalité', icon: 'wallet', examples: 'Tenue comptable, conseil fiscal, paie' },
  { slug: 'financement', label: 'Financement', icon: 'briefcase', examples: 'Business plan, levée de fonds, aides et subventions' },
  { slug: 'conseil-strategie', label: 'Conseil', icon: 'bulb', examples: 'Étude de marché, modèle économique, accompagnement' },
];

/** Métiers qui défilent dans le titre de l'accueil (« avec les bons … »). */
export const HERO_TRADES = ['experts', 'développeurs', 'designers', 'juristes', 'illustrateurs', 'comptables', 'vidéastes', 'rédacteurs'];
