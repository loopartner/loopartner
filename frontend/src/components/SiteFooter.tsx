import StickerSlapFooter, { type FooterColumn } from './StickerFooter';

const COLUMNS: FooterColumn[] = [
  {
    label: 'Plateforme',
    links: [
      { label: 'Voir les projets', href: '/projets' },
      { label: 'Trouver un prestataire', href: '/prestataires' },
      { label: 'Les offres', href: '/offres' },
      { label: 'Événements', href: '/evenements' },
    ],
  },
  {
    label: 'Ressources',
    links: [
      { label: 'Publier un projet', href: '/publier' },
      { label: 'Devenir prestataire', href: '/devenir-prestataire' },
      { label: 'Articles', href: '/articles' },
      { label: 'Premium', href: '/premium' },
    ],
  },
  {
    label: 'Compte et légal',
    small: true,
    links: [
      { label: 'Connexion', href: '/connexion' },
      { label: 'Inscription gratuite', href: '/inscription' },
      { label: 'Comment ça marche', href: '/#comment-ca-marche' },
      { label: "Conditions générales d'utilisation", href: '/legal/terms' },
      { label: 'Conditions de vente Premium', href: '/legal/sales' },
      { label: 'Confidentialité', href: '/legal/privacy' },
      { label: 'Cookies', href: '/legal/cookies' },
      { label: 'Mentions légales', href: '/legal/notice' },
    ],
  },
];

export function SiteFooter() {
  return <StickerSlapFooter columns={COLUMNS} badge={`© ${new Date().getFullYear()} Loopartner`} />;
}
