/**
 * Mode démonstration : arbre des catégories, recopié de `ServiceCategorySeeder` côté API (noms
 * français, même ordre). À changer avec lui.
 */
import type { Category } from './types';

const tree = (entries: Array<[string, string, Array<[string, string]>?]>): Category[] =>
  entries.map(([slug, name, children = []]) => ({ slug, name, children: children.map(([childSlug, childName]) => ({ slug: childSlug, name: childName })) }));

export const DEMO_CATEGORIES: Category[] = tree([
  ['juridique', 'Juridique', [
    ['creation-statuts', 'Création et statuts'],
    ['contrats-cgv', 'Contrats et CGV'],
    ['propriete-intellectuelle', 'Propriété intellectuelle'],
  ]],
  ['comptabilite-fiscalite', 'Comptabilité et fiscalité', [
    ['tenue-comptable', 'Tenue comptable'],
    ['conseil-fiscal', 'Conseil fiscal'],
    ['paie', 'Paie'],
  ]],
  ['financement', 'Financement', [
    ['business-plan', 'Business plan et prévisionnel'],
    ['levee-de-fonds', 'Levée de fonds'],
    ['aides-subventions', 'Aides et subventions'],
  ]],
  ['marketing-communication', 'Marketing et communication', [
    ['strategie-marketing', 'Stratégie marketing'],
    ['reseaux-sociaux', 'Réseaux sociaux'],
    ['referencement-seo', 'Référencement (SEO)'],
    ['relations-presse', 'Relations presse'],
    ['photo-video', 'Photo et vidéo'],
    ['publicite-en-ligne', 'Publicité en ligne'],
    ['influence', "Marketing d'influence"],
  ]],
  ['design', 'Design', [
    ['identite-visuelle', 'Identité visuelle et logo'],
    ['design-ui-ux', 'Design UI/UX'],
    ['supports-imprimes', 'Supports imprimés'],
    ['packaging', 'Packaging'],
    ['motion-design-3d', 'Motion design et 3D'],
  ]],
  ['developpement-web', 'Développement web', [
    ['site-vitrine', 'Site vitrine'],
    ['e-commerce', 'E-commerce'],
    ['applications-web-mobiles', 'Applications web et mobiles'],
    ['logiciel-sur-mesure', 'Logiciel sur mesure'],
    ['automatisation-no-code', 'Automatisation et no-code'],
    ['data-ia', 'Data et intelligence artificielle'],
    ['cybersecurite', 'Cybersécurité'],
    ['maintenance-hebergement', 'Maintenance et hébergement'],
  ]],
  ['arts-contenus', 'Arts et contenus', [
    ['illustration', 'Illustration'],
    ['photographie', 'Photographie'],
    ['video-montage', 'Vidéo et montage'],
    ['musique-sound-design', 'Musique et sound design'],
    ['redaction-copywriting', 'Rédaction et copywriting'],
    ['voix-off', 'Voix off'],
  ]],
  ['conseil-strategie', 'Conseil en stratégie', [
    ['etude-de-marche', 'Étude de marché'],
    ['modele-economique', 'Modèle économique'],
    ['coaching-entrepreneur', 'Accompagnement du dirigeant'],
  ]],
  ['ressources-humaines', 'Ressources humaines', [
    ['recrutement', 'Recrutement'],
    ['droit-du-travail', 'Droit du travail'],
    ['formation', 'Formation'],
  ]],
  ['assurances', 'Assurances'],
  ['traduction-localisation', 'Traduction et localisation'],
  ['autres', 'Autres'],
]);

const FLAT = DEMO_CATEGORIES.flatMap((parent) => [parent, ...(parent.children ?? [])]);

/** Catégorie d'un slug (sans ses sous-catégories), « Autres » si le slug est inconnu. */
export function demoCategory(slug: string): Category {
  const found = FLAT.find((item) => item.slug === slug) ?? (FLAT.find((item) => item.slug === 'autres') as Category);
  return { slug: found.slug, name: found.name };
}

/** Vrai si `slug` est la catégorie filtrée ou l'une de ses sous-catégories (comme l'API). */
export function inDemoCategory(slug: string | null | undefined, filter: unknown): boolean {
  if (!filter) return true;
  if (!slug) return false;
  if (slug === filter) return true;
  return DEMO_CATEGORIES.some((parent) => parent.slug === filter && (parent.children ?? []).some((child) => child.slug === slug));
}
