/**
 * Offre Premium telle que décidée (décisions 5 révisée, 32, 35 et 81 de docs/v1/DECISIONS.md).
 *
 * Le paiement en ligne arrive avec le lot 10 (Billing) : d'ici là, ces textes présentent
 * l'offre sans permettre de souscrire. Les prix restent à confirmer avec l'expert-comptable
 * (décision 32) ; au lot 10, ils seront lus dans l'API (prix Stripe) et ce fichier ne gardera
 * que les avantages. À changer en même temps que les décisions.
 */

export const PREMIUM_PRICES = {
  monthly: '14,90 € TTC par mois',
  yearly: '149 € TTC par an',
  trialDays: 30,
} as const;

export type PremiumRole = 'prestataire' | 'particulier' | 'entrepreneur';

export interface PremiumFeature {
  title: string;
  free: string;
  premium: string;
}

export const PREMIUM_FEATURES: Record<PremiumRole, PremiumFeature[]> = {
  prestataire: [
    {
      title: 'Projets des porteurs',
      free: 'Vitrine publique : titre, description, catégorie et budget',
      premium: 'Détail complet (ville, date de début, type de porteur) et propositions aux porteurs de projet',
    },
    { title: 'Nouveaux projets', free: 'Visibles 24 heures après leur publication', premium: 'Visibles dès leur publication' },
    { title: 'Devis PDF', free: 'Devis PDF standard', premium: 'Devis personnalisés : logo et mentions' },
    { title: 'Prise de rendez-vous', free: '2 types de rendez-vous, agenda sur 30 jours', premium: 'Page de réservation publique, 10 types, agenda sur 90 jours' },
    { title: 'Articles', free: '2 articles soumis', premium: 'Articles illimités' },
    {
      title: 'Suivi administratif',
      free: '1 entreprise, 1 délai de rappel',
      premium: '5 entreprises, 3 délais de rappel, paliers personnalisés, estimation',
    },
  ],
  particulier: [
    { title: 'Devis reçus', free: 'Lecture de chaque devis', premium: 'Comparateur côte à côte et export PDF' },
    { title: 'Copilote de projet', free: 'Une feuille de route', premium: 'Usage étendu et cahiers des charges' },
    { title: 'Espaces de projet', free: 'Un espace', premium: 'Plusieurs espaces et modèles de projet' },
  ],
  entrepreneur: [
    { title: 'Devis reçus', free: 'Lecture de chaque devis', premium: 'Comparateur côte à côte et export PDF' },
    { title: 'Copilote de projet', free: 'Une feuille de route', premium: 'Usage étendu et cahiers des charges' },
    { title: 'Espaces de projet', free: 'Un espace', premium: 'Plusieurs espaces et modèles de projet' },
    { title: 'Articles', free: '2 articles soumis', premium: 'Articles illimités' },
    {
      title: 'Suivi administratif',
      free: '1 entreprise, 1 délai de rappel',
      premium: '5 entreprises, 3 délais de rappel, paliers personnalisés, estimation',
    },
  ],
};

export const ROLE_TITLES: Record<PremiumRole, string> = {
  prestataire: 'Prestataires',
  particulier: 'Particuliers',
  entrepreneur: 'Entrepreneurs',
};
