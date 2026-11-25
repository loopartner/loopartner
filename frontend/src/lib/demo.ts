/**
 * Mode démonstration (NEXT_PUBLIC_DEMO_MODE=true) : le frontend répond lui-même avec des
 * données fictives, sans appeler l'API. Il sert à montrer le rendu sur un aperçu Vercel
 * tant que l'API n'est pas en ligne. **Jamais en production** : la variable n'est définie que pour
 * l'environnement Preview, et un bandeau « Démonstration » reste visible sur chaque page.
 *
 * Comptes de test (fictifs, sans existence côté API) : voir DEMO_ACCOUNTS.
 * La session et les créations (projets, inscriptions) restent dans le navigateur (localStorage).
 */

import { LEGAL_TEXTS } from '@/content/legal';
import { demoMediaUrl, storeDemoMedia } from './demo-media';
import { demoMatching } from './demo-matching';
import { demoOffers } from './demo-offers';
import { demoQuotes } from './demo-quotes';
import type { ArticleDetail, ArticleSummary, BrowsableProject, Category, Me, OwnEvent, OwnProject, PublicEvent, Role } from './types';

export const DEMO_MODE = process.env.NEXT_PUBLIC_DEMO_MODE === 'true';

export const DEMO_PASSWORD = 'Demo-Loopartner-2026';

export const DEMO_ACCOUNTS: Record<string, { role: Role; name: string; label: string; premium?: boolean }> = {
  'client@demo.loopartner.fr': { role: 'particulier', name: 'Camille Durand', label: 'Client (particulier)' },
  'entrepreneur@demo.loopartner.fr': { role: 'entrepreneur', name: 'Lucas Bernard', label: 'Client (entrepreneur)' },
  'prestataire@demo.loopartner.fr': { role: 'prestataire', name: 'Cabinet Morel Conseil', label: 'Prestataire (juriste)' },
  'comptable@demo.loopartner.fr': { role: 'prestataire', name: 'Compta Claire', label: 'Prestataire Premium (comptable)', premium: true },
  'marques@demo.loopartner.fr': { role: 'prestataire', name: 'Marques & Co', label: 'Prestataire (propriété intellectuelle)' },
};

export class DemoError extends Error {
  constructor(
    readonly status: number,
    readonly body: Record<string, unknown>,
  ) {
    super(typeof body.message === 'string' ? body.message : 'Erreur de démonstration');
  }
}

/* ---------------------------------------------------------------- Données fictives */

const NOW = Date.now();
const ago = (minutes: number) => new Date(NOW - minutes * 60_000).toISOString().replace(/\.\d{3}Z$/, 'Z');
const ahead = (days: number, hour: number, minute = 0) => {
  const date = new Date(NOW + days * 86_400_000);
  date.setUTCHours(hour - 1, minute, 0, 0); // heure de Paris en hiver (UTC+1)
  return date.toISOString().replace(/\.\d{3}Z$/, 'Z');
};

const CATEGORIES: Category[] = [
  { slug: 'creation-societe', name: 'Création de société' },
  { slug: 'juridique', name: 'Juridique' },
  { slug: 'comptabilite', name: 'Comptabilité' },
  { slug: 'fiscalite', name: 'Fiscalité' },
  { slug: 'propriete-intellectuelle', name: 'Propriété intellectuelle' },
  { slug: 'domiciliation', name: 'Domiciliation' },
  { slug: 'autres', name: 'Autres' },
];
const cat = (slug: string) => CATEGORIES.find((category) => category.slug === slug) as Category;

const PROJECTS: BrowsableProject[] = [
  {
    title: "Création d'une SAS pour une application mobile",
    category: cat('creation-societe'),
    budget_min: 500,
    budget_max: 1000,
    remote_ok: true,
    published_at: ago(21),
    description:
      "Dans le cadre du lancement de notre application mobile, nous recherchons un juriste ou un avocat en droit des sociétés pour nous accompagner dans la création de notre SAS.\n\nNotre mission pour vous : rédiger des statuts adaptés à l'entrée future d'investisseurs, préparer un pacte d'associés entre les deux fondateurs, et effectuer les formalités d'immatriculation.",
  },
  {
    title: 'Audit de conformité RGPD pour une boutique e-commerce',
    category: cat('juridique'),
    budget_min: 1000,
    budget_max: 2500,
    remote_ok: true,
    published_at: ago(180),
    description:
      "Boutique en ligne sur Shopify (environ 2 000 commandes par an). Nous voulons un audit complet : registre des traitements, bandeau cookies, mentions légales, politique de confidentialité et contrats avec nos sous-traitants.",
  },
  {
    title: "Besoin d'un expert-comptable pour une micro-entreprise",
    category: cat('comptabilite'),
    budget_min: null,
    budget_max: 500,
    remote_ok: true,
    published_at: ago(300),
    description:
      "Consultant indépendant en micro-entreprise depuis un an, je cherche un accompagnement pour faire le point sur mes déclarations et savoir s'il est temps de changer de statut.",
  },
  {
    title: 'Dépôt de marque pour une marque de cosmétiques',
    category: cat('propriete-intellectuelle'),
    budget_min: 1000,
    budget_max: 2500,
    remote_ok: false,
    published_at: ago(26 * 60),
    description:
      "Nous lançons une gamme de cosmétiques naturels. Nous avons besoin d'une recherche d'antériorité puis du dépôt du nom et du logo auprès de l'INPI, et d'un conseil sur l'extension à l'Union européenne.",
  },
  {
    title: 'Rédaction de CGV pour une marketplace',
    category: cat('juridique'),
    budget_min: 500,
    budget_max: 1000,
    remote_ok: true,
    published_at: ago(2 * 24 * 60),
    description:
      "Marketplace B2C spécialisée dans l'artisanat. Il nous faut des CGV et des CGU adaptées aux vendeurs professionnels et aux acheteurs particuliers, avec la gestion des retours.",
  },
  {
    title: 'Domiciliation et ouverture de compte pour une SASU',
    category: cat('domiciliation'),
    budget_min: null,
    budget_max: 300,
    remote_ok: true,
    published_at: ago(3 * 24 * 60),
    description:
      "Je crée une SASU de conseil et je cherche une domiciliation à Paris ou Lyon, avec réception du courrier. Conseils bienvenus sur le choix de la banque professionnelle.",
  },
  {
    title: 'Choisir entre micro-entreprise et EURL',
    category: cat('fiscalite'),
    budget_min: 300,
    budget_max: 800,
    remote_ok: true,
    published_at: ago(4 * 24 * 60),
    description:
      "Graphiste, je prévois environ 45 000 € de chiffre d'affaires la première année. J'aimerais une simulation comparée des charges et de l'impôt entre micro-entreprise et EURL.",
  },
  {
    title: "Pacte d'associés pour une start-up à trois fondateurs",
    category: cat('creation-societe'),
    budget_min: 2000,
    budget_max: null,
    remote_ok: false,
    published_at: ago(6 * 24 * 60),
    description:
      "Trois associés, une SAS déjà créée. Nous voulons un pacte d'associés complet : clauses de sortie, vesting, non-concurrence et gouvernance avant une première levée de fonds.",
  },
].map((project, index) => ({
  ...project,
  uuid: `d3e0f000-0000-4000-8000-00000000000${index + 1}`,
  currency: 'EUR',
  country: 'FR',
  city: null,
  desired_start_date: null,
  expires_at: null,
  owner: { account_type: index % 3 === 0 ? 'entrepreneur' : 'particulier', country: 'FR' },
}));

const ORGANIZERS = [
  { kind: 'provider' as const, name: 'Compta Claire', slug: 'compta-claire', headline: 'Expertise comptable pour indépendants', logo_url: null },
  { kind: 'provider' as const, name: 'Marques & Co', slug: 'marques-et-co', headline: 'Conseil en propriété industrielle', logo_url: null },
  { kind: 'provider' as const, name: 'Cabinet Morel Conseil', slug: 'cabinet-morel-conseil', headline: "Juristes en droit des sociétés", logo_url: null },
  { kind: 'named' as const, name: 'Marc L.', slug: null, headline: 'Expert-comptable', logo_url: null },
  { kind: 'named' as const, name: 'Sarah B.', slug: null, headline: 'Comptable', logo_url: null },
  { kind: 'named' as const, name: 'Antoine D.', slug: null, headline: 'Conseil en propriété industrielle', logo_url: null },
];

const EVENTS: PublicEvent[] = [
  ['Rédiger ses statuts sans erreur', 'creation-societe', 14, 14, 45, 12],
  ['Auto-entrepreneur ou société : faire le bon choix', 'juridique', 16, 10, 90, 4],
  ['Comptabilité pour débutants', 'comptabilite', 18, 15, 60, 0],
  ['Dépôt de marque : les fondamentaux', 'propriete-intellectuelle', 21, 11, 60, 25],
  ['TVA et premières déclarations', 'fiscalite', 23, 14, 60, 8],
  ['Domiciliation : siège, adresse et courrier', 'domiciliation', 0, 12, 45, 18],
  ['Lever ses premiers fonds : le pacte d’associés', 'creation-societe', 0, 18, 90, 6],
  ['Facturation : les mentions obligatoires', 'comptabilite', 0, 9, 45, 20],
  ['Protéger un logiciel ou une application', 'propriete-intellectuelle', 0, 17, 60, 14],
  ['CGV et CGU : ce qu’il faut prévoir', 'juridique', 0, 11, 60, 2],
  ['Charges sociales de l’indépendant', 'fiscalite', 0, 15, 60, 22],
  ['Ouvrir un compte bancaire professionnel', 'domiciliation', 0, 10, 30, 30],
].map(([title, category, day, hour, minutes, spots], index) => {
  void day;
  const starts = ahead(index * 2 + 3, hour as number);
  return {
    slug: `webinar-demo-${index + 1}`,
    status: 'published' as const,
    title: title as string,
    description:
      'Un webinar pratique pour comprendre les points clés, éviter les erreurs fréquentes et poser vos questions en direct à un professionnel. Aucun prérequis.',
    type: 'webinar',
    format: 'online' as const,
    language: 'fr',
    categories: [cat(category as string)],
    starts_at: starts,
    ends_at: new Date(new Date(starts).getTime() + (minutes as number) * 60_000).toISOString().replace(/\.\d{3}Z$/, 'Z'),
    timezone: 'Europe/Paris',
    venue: null,
    external_url: null,
    is_free: true,
    ticket_url: null,
    capacity: 30,
    cover_url: null,
    organizer: ORGANIZERS[index % ORGANIZERS.length],
    registration: { mode: 'internal' as const, is_open: true, is_full: spots === 0, spots_left: spots as number },
  };
});

const segment = (text: string, marks: Array<'bold' | 'italic'> = []) => ({ text, marks, link: null });

const PROVIDER_AUTHORS = [
  ['Cabinet Morel Conseil', 'cabinet-morel-conseil', 'Juristes en droit des sociétés'],
  ['Compta Claire', 'compta-claire', 'Expertise comptable pour indépendants'],
  ['Marques & Co', 'marques-et-co', 'Conseil en propriété industrielle'],
];

const ARTICLES: ArticleSummary[] = [
  ['Le guide pour créer sa SASU en 2026', 'creation-societe', 'editorial'],
  'Optimisation fiscale : les questions à se poser,fiscalite,provider'.split(','),
  ['RGPD : mettre son site en conformité', 'juridique', 'editorial'],
  ['Où domicilier son entreprise ?', 'domiciliation', 'entrepreneur'],
  ['Protéger sa marque : pourquoi c’est essentiel', 'propriete-intellectuelle', 'editorial'],
  ['Tenir sa comptabilité en ligne : les avantages', 'comptabilite', 'provider'],
  ['SARL ou SAS : le comparatif', 'creation-societe', 'entrepreneur'],
  ['TVA : comprendre la franchise en base', 'fiscalite', 'editorial'],
  ['Les 7 erreurs à éviter en créant sa société', 'creation-societe', 'provider'],
  ['Micro-entreprise : quand changer de statut ?', 'fiscalite', 'editorial'],
  ['Rédiger un devis professionnel', 'comptabilite', 'provider'],
  ['Choisir le nom de son entreprise', 'propriete-intellectuelle', 'entrepreneur'],
  ['Mentions légales : le minimum obligatoire', 'juridique', 'editorial'],
  ['Associé ou salarié : bien s’entourer', 'creation-societe', 'entrepreneur'],
  ['Tenir ses premiers comptes sans stress', 'comptabilite', 'editorial'],
  ['Adresse de domiciliation ou bureau : comparer', 'domiciliation', 'provider'],
].map(([title, category, kind], index) => ({
  slug: `article-demo-${index + 1}`,
  title,
  excerpt: "Les étapes indispensables, les points d'attention et les erreurs à éviter, expliqués simplement pour bien démarrer.",
  language: 'fr',
  cover: null,
  categories: [cat(category)],
  author:
    kind === 'editorial'
      ? { kind: 'editorial' as const, name: 'Rédaction Loopartner', slug: null, headline: null, logo_url: null }
      : kind === 'provider'
        ? { kind: 'provider' as const, name: PROVIDER_AUTHORS[index % 3][0], slug: PROVIDER_AUTHORS[index % 3][1], headline: PROVIDER_AUTHORS[index % 3][2], logo_url: null }
        : { kind: 'entrepreneur' as const, name: 'Claire M.', slug: null, headline: null, logo_url: null },
  reading_time_minutes: 4 + (index % 4),
  published_at: ago((index + 1) * 26 * 60),
}));

const ARTICLE_BODY = {
  version: 1,
  blocks: [
    { type: 'heading' as const, level: 2 as const, content: [segment('Pourquoi les statuts comptent')] },
    {
      type: 'paragraph' as const,
      content: [
        segment('Lors de la création d’une société, la rédaction des statuts est une étape décisive. '),
        segment('Ils fixent les règles du jeu', ['bold']),
        segment(' entre associés pour les années à venir.'),
      ],
    },
    { type: 'heading' as const, level: 3 as const, content: [segment('Ce que vous allez découvrir')] },
    {
      type: 'list' as const,
      style: 'bullet' as const,
      items: [
        [segment('L’objet social : ', ['bold']), segment('ne pas brider votre croissance future.')],
        [segment('La clause d’agrément : ', ['bold']), segment('garder le contrôle sur les nouveaux associés.')],
        [segment('Les pouvoirs du président : ', ['bold']), segment('les délimiter clairement.')],
      ],
    },
    { type: 'quote' as const, content: [segment('Une semaine de plus sur vos statuts aujourd’hui peut vous éviter des années de litiges.')], attribution: 'Exemple de démonstration' },
  ],
};

const PROVIDERS = [
  { slug: 'cabinet-morel-conseil', display_name: 'Cabinet Morel Conseil', headline: 'Juristes en droit des sociétés', city: 'Lyon', country: 'FR', logo_url: '/images/hero/expert-1.webp', banner_url: '/images/demo/banner-1.webp', categories: [cat('juridique'), cat('creation-societe')] },
  { slug: 'compta-claire', display_name: 'Compta Claire', headline: 'Expertise comptable pour indépendants', city: 'Nantes', country: 'FR', logo_url: '/images/hero/expert-2.webp', banner_url: '/images/demo/banner-2.webp', categories: [cat('comptabilite'), cat('fiscalite')] },
  { slug: 'marques-et-co', display_name: 'Marques & Co', headline: 'Conseil en propriété industrielle', city: 'Paris', country: 'FR', logo_url: '/images/hero/expert-3.webp', banner_url: '/images/demo/banner-3.webp', categories: [cat('propriete-intellectuelle')] },
  { slug: 'domicile-pro', display_name: 'Domicile Pro', headline: "Domiciliation d'entreprises", city: 'Bordeaux', country: 'FR', logo_url: '/images/hero/expert-4.webp', banner_url: '/images/demo/banner-4.webp', categories: [cat('domiciliation')] },
  { slug: 'avocats-delaunay', display_name: 'Delaunay Avocats', headline: 'Avocats en droit des affaires et des start-up', city: 'Paris', country: 'FR', logo_url: '/images/hero/expert-5.webp', banner_url: '/images/demo/banner-1.webp', categories: [cat('juridique'), cat('creation-societe')] },
  { slug: 'fisca-conseil', display_name: 'Fisca Conseil', headline: 'Optimisation et conformité fiscale', city: 'Toulouse', country: 'FR', logo_url: null, banner_url: '/images/demo/banner-2.webp', categories: [cat('fiscalite')] },
  { slug: 'atelier-compta', display_name: 'Atelier Compta', headline: 'Comptabilité en ligne pour TPE', city: 'Lille', country: 'FR', logo_url: null, banner_url: '/images/demo/banner-3.webp', categories: [cat('comptabilite')] },
  { slug: 'statuts-express', display_name: 'Statuts Express', headline: 'Création de société clés en main', city: 'Marseille', country: 'FR', logo_url: null, banner_url: '/images/demo/banner-4.webp', categories: [cat('creation-societe'), cat('domiciliation')] },
  { slug: 'innov-ip', display_name: 'Innov IP', headline: 'Brevets, marques et logiciels', city: 'Grenoble', country: 'FR', logo_url: null, banner_url: '/images/demo/banner-1.webp', categories: [cat('propriete-intellectuelle')] },
  { slug: 'rgpd-solutions', display_name: 'RGPD Solutions', headline: 'Mise en conformité RGPD des sites et boutiques', city: 'Rennes', country: 'FR', logo_url: null, banner_url: '/images/demo/banner-2.webp', categories: [cat('juridique')] },
  { slug: 'gestion-independants', display_name: 'Gestion Indépendants', headline: 'Accompagnement des micro-entrepreneurs', city: 'Strasbourg', country: 'FR', logo_url: null, banner_url: '/images/demo/banner-3.webp', categories: [cat('comptabilite'), cat('fiscalite')] },
  { slug: 'siege-lyon', display_name: 'Siège Lyon Centre', headline: 'Domiciliation et bureaux à la carte', city: 'Lyon', country: 'FR', logo_url: null, banner_url: '/images/demo/banner-4.webp', categories: [cat('domiciliation')] },
];

/* ---------------------------------------------------------------- Stockage local */

const isBrowser = typeof window !== 'undefined';

function load<T>(key: string, fallback: T): T {
  if (!isBrowser) return fallback;
  try {
    const raw = window.localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as T) : fallback;
  } catch {
    return fallback;
  }
}

function save(key: string, value: unknown) {
  if (!isBrowser) return;
  try {
    window.localStorage.setItem(key, JSON.stringify(value));
  } catch {
    // Stockage indisponible : la démonstration continue sans mémoire.
  }
}

const SESSION_KEY = 'loopartner:demo-session';
const PROJECTS_KEY = 'loopartner:demo-projects';
const REGISTRATIONS_KEY = 'loopartner:demo-registrations';
const FEED_KEY = 'loopartner:demo-agenda-feed';
/** Inscriptions de départ, pour que l'agenda de démonstration ne soit pas vide. */
const DEFAULT_REGISTRATIONS = ['webinar-demo-2', 'webinar-demo-5'];

/** Photos envoyées pendant la démonstration (uuid → adresse locale, perdues au rechargement). */
const mediaUrls = new Map<string, string>();
const profileKey = (email: string) => `loopartner:demo-me:${email}`;

function currentUser(): Me | null {
  const email = load<string | null>(SESSION_KEY, null);
  const account = email ? DEMO_ACCOUNTS[email] : undefined;
  if (!email || !account) return null;
  const edits = load<{ name?: string; country?: string; avatar_url?: string | null; locale?: string; timezone?: string; two_factor_enabled?: boolean }>(profileKey(email), {});
  return {
    name: edits.name ?? account.name,
    email,
    roles: [account.role],
    locale: edits.locale ?? 'fr',
    timezone: edits.timezone ?? 'Europe/Paris',
    country: edits.country ?? 'FR',
    avatar_url: edits.avatar_url ?? null,
    email_verified: true,
    two_factor_enabled: edits.two_factor_enabled ?? false,
    two_factor_required: false,
    premium: account.premium
      ? { active: true, source: 'manual', starts_at: '2026-11-01T00:00:00Z', ends_at: null }
      : { active: false, source: null, starts_at: null, ends_at: null },
    article_quota: account.role === 'prestataire' ? (account.premium ? { premium: true, limit: null, used: 0, remaining: null } : { premium: false, limit: 2, used: 0, remaining: 2 }) : null,
  };
}

/* ---------------------------------------------------------------- Réponses */

function paginate<T>(items: T[], query: Record<string, unknown>) {
  const perPage = Math.min(50, Number(query.per_page ?? 20) || 20);
  const page = Math.max(1, Number(query.page ?? 1) || 1);
  const last = Math.max(1, Math.ceil(items.length / perPage));
  return { data: items.slice((page - 1) * perPage, page * perPage), meta: { current_page: page, last_page: last, per_page: perPage, total: items.length } };
}

const toPublic = (project: BrowsableProject) => ({
  uuid: project.uuid,
  title: project.title,
  description: project.description,
  category: project.category,
  budget_min: project.budget_min,
  budget_max: project.budget_max,
  currency: project.currency,
  country: project.country,
  remote_ok: project.remote_ok,
  published_at: project.published_at,
});

function requireUser(): Me {
  const user = currentUser();
  if (!user) throw new DemoError(401, { message: 'Unauthenticated.' });
  return user;
}

const ok = { message: 'OK (démonstration : rien n’est envoyé).' };

export function demoApi(path: string, method: string, query: Record<string, unknown>, body: unknown): unknown {
  const input = (body ?? {}) as Record<string, unknown>;
  const route = path.replace(/^\/+/, '').replace(/\/+$/, '');

  // Mise en relation, messagerie et signalement (demo-matching.ts)
  const session = currentUser();
  // Détail des projets et propositions : prestataires Premium seulement (décision 81).
  if (/^projects\/[^/]+(\/proposals)?$/.test(route) && session?.roles.includes('prestataire') && !session.premium?.active) {
    throw new DemoError(403, {
      message: 'Le détail des projets et les propositions sont réservés aux prestataires Premium. Les projets restent visibles dans la vitrine publique.',
      code: 'premium_required',
      feature: 'project_access',
    });
  }
  const matching = demoMatching(
    route,
    method,
    query,
    input,
    session ? { email: session.email, isProvider: session.roles.includes('prestataire'), name: session.name, role: session.roles[0] } : null,
    (status, payload) => {
      throw new DemoError(status, payload);
    },
  );
  if (matching) return matching.value;

  // Offres, profil prestataire et identité professionnelle (demo-offers.ts)
  const offers = demoOffers(
    route,
    method,
    query,
    input,
    { providers: PROVIDERS, categories: CATEGORIES, user: session ? { email: session.email, isProvider: session.roles.includes('prestataire'), name: session.name } : null },
    (status, payload) => {
      throw new DemoError(status, payload);
    },
  );
  if (offers) return offers.value;

  // Devis (demo-quotes.ts)
  const quotes = demoQuotes(route, method, query, input, session ? { email: session.email, isProvider: session.roles.includes('prestataire'), name: session.name } : null, (status, payload) => {
    throw new DemoError(status, payload);
  });
  if (quotes) return quotes.value;

  // Authentification
  if (route === 'auth/login') {
    const email = String(input.email ?? '').trim().toLowerCase();
    if (!DEMO_ACCOUNTS[email] || input.password !== DEMO_PASSWORD) {
      throw new DemoError(422, { message: 'Identifiants incorrects.', errors: { email: ['Ces identifiants ne correspondent à aucun compte de démonstration.'] } });
    }
    save(SESSION_KEY, email);
    return { two_factor: false };
  }
  if (route === 'auth/logout') {
    save(SESSION_KEY, null);
    return undefined;
  }
  if (route === 'auth/register') return { message: 'Démonstration : aucun compte n’est créé. Utilisez un compte de test pour vous connecter.' };
  if (route === 'auth/forgot-password') return { message: 'Démonstration : aucun email n’est envoyé.' };
  if (route === 'auth/user/two-factor-qr-code') {
    return {
      svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 29 29" width="180" height="180"><rect width="29" height="29" fill="#fff"/><path d="M2 2h7v7H2zM20 2h7v7h-7zM2 20h7v7H2z" fill="none" stroke="#1d2939" stroke-width="2"/><path d="M12 3h2v2h-2zM15 6h2v2h-2zM12 12h5v2h-5zM20 13h2v4h-2zM13 20h3v3h-3zM18 22h4v2h-4zM24 18h2v6h-2z" fill="#1d2939"/></svg>',
    };
  }
  if (route === 'auth/user/two-factor-secret-key') return { secretKey: 'DEMODEMODEMODEMO' };
  if (route === 'auth/user/two-factor-recovery-codes' && method === 'GET') {
    return ['demo1-abcde', 'demo2-fghij', 'demo3-klmno', 'demo4-pqrst', 'demo5-uvwxy', 'demo6-zabcd', 'demo7-efghi', 'demo8-jklmn'];
  }
  if (route === 'auth/user/confirmed-two-factor-authentication') {
    const user = requireUser();
    if (!/^\d{6}$/.test(String(input.code ?? ''))) throw new DemoError(422, { message: 'Code invalide.', errors: { code: ['Le code fourni est invalide (démonstration : n’importe quels 6 chiffres).'] } });
    const edits = load<Record<string, unknown>>(profileKey(user.email), {});
    save(profileKey(user.email), { ...edits, two_factor_enabled: true });
    return ok;
  }
  if (route === 'auth/user/two-factor-authentication' && method === 'DELETE') {
    const user = requireUser();
    const edits = load<Record<string, unknown>>(profileKey(user.email), {});
    save(profileKey(user.email), { ...edits, two_factor_enabled: false });
    return undefined;
  }
  if (route.startsWith('auth/')) return ok;

  // Références publiques
  if (route === 'categories') return { data: CATEGORIES };
  if (route === 'legal/documents') {
    return {
      data: (['terms', 'privacy'] as const).map((type) => ({
        type,
        current: { type, version: LEGAL_TEXTS[type].version, title: LEGAL_TEXTS[type].title, effective_at: ago(60 * 24 * 30) },
        upcoming: null,
      })),
    };
  }
  if (route.startsWith('legal/documents/')) {
    const type = route.split('/')[2];
    if (type !== 'terms' && type !== 'privacy') throw new DemoError(404, { message: 'Document introuvable.' });
    const text = LEGAL_TEXTS[type];
    return { data: { type, version: text.version, title: text.title, effective_at: ago(60 * 24 * 30), body: text.body } };
  }

  // Projets
  const ownProjects = load<OwnProject[]>(PROJECTS_KEY, []);
  const published = [...ownProjects.filter((project) => project.status === 'published'), ...PROJECTS];
  if (route === 'public/projects') {
    const list = published.filter((project) => !query.category || project.category?.slug === query.category);
    return paginate(list.map(toPublic), query);
  }
  if (route.startsWith('public/projects/')) {
    const project = published.find((item) => item.uuid === route.split('/')[2]);
    if (!project) throw new DemoError(404, { message: 'Projet introuvable.' });
    return { data: toPublic(project) };
  }
  if (/^projects\/[^/]+$/.test(route)) {
    const user = requireUser();
    if (!user.roles.includes('prestataire')) throw new DemoError(403, { message: 'Réservé aux prestataires.' });
    const project = published.find((item) => item.uuid === route.split('/')[1]);
    if (!project) throw new DemoError(404, { message: 'Projet introuvable.' });
    return { data: project };
  }
  if (route === 'me/projects' && method === 'GET') {
    requireUser();
    return paginate(ownProjects, query);
  }
  if (route === 'me/projects' && method === 'POST') {
    const user = requireUser();
    const category = CATEGORIES.find((item) => item.slug === input.category) ?? null;
    const project: OwnProject = {
      uuid: crypto.randomUUID(),
      title: String(input.title ?? ''),
      description: String(input.description ?? ''),
      category,
      budget_min: (input.budget_min as number | null) ?? null,
      budget_max: (input.budget_max as number | null) ?? null,
      currency: 'EUR',
      country: String(input.country ?? 'FR'),
      city: null,
      remote_ok: Boolean(input.remote_ok),
      desired_start_date: null,
      published_at: null,
      expires_at: null,
      owner: { account_type: user.roles.includes('entrepreneur') ? 'entrepreneur' : 'particulier', country: 'FR' },
      status: 'draft',
    };
    save(PROJECTS_KEY, [project, ...ownProjects]);
    return { data: project };
  }
  const own = /^me\/projects\/([^/]+)\/(publish|close)$/.exec(route);
  if (own) {
    requireUser();
    const updated = ownProjects.map((project) =>
      project.uuid === own[1]
        ? { ...project, status: own[2] === 'publish' ? ('published' as const) : ('closed' as const), published_at: own[2] === 'publish' ? ago(0) : project.published_at, expires_at: ahead(60, 12) }
        : project,
    );
    save(PROJECTS_KEY, updated);
    return { data: updated.find((project) => project.uuid === own[1]) };
  }

  // Prestataires
  if (route === 'providers') {
    const q = String(query.q ?? '').toLowerCase();
    return paginate(
      PROVIDERS.filter((provider) => (!query.category || provider.categories.some((item) => item.slug === query.category)) && (!q || provider.display_name.toLowerCase().includes(q))),
      query,
    );
  }

  const providerProfile = /^providers\/([^/]+)$/.exec(route);
  if (providerProfile) {
    const provider = PROVIDERS.find((item) => item.slug === providerProfile[1]);
    if (!provider) throw new DemoError(404, { message: 'Prestataire introuvable.' });
    return {
      data: {
        ...provider,
        bio: `${provider.display_name} accompagne les créateurs et les dirigeants de TPE depuis plusieurs années. Profil de démonstration : les informations sont fictives.`,
        website: null,
        published_at: ago(60 * 24 * 90),
      },
    };
  }

  // Articles
  if (route === 'articles') return paginate(ARTICLES.filter((article) => !query.category || article.categories[0].slug === query.category), query);
  if (route.startsWith('articles/')) {
    const article = ARTICLES.find((item) => item.slug === route.split('/')[1]);
    if (!article) throw new DemoError(404, { message: 'Article introuvable.' });
    const detail: ArticleDetail = { ...article, body: ARTICLE_BODY, seo: { title: article.title, description: article.excerpt }, related: ARTICLES.filter((item) => item.slug !== article.slug).slice(0, 4) };
    return { data: detail };
  }
  if (route.startsWith('me/articles')) {
    requireUser();
    return { data: { uuid: /^me\/articles\/([^/]+)/.exec(route)?.[1] ?? crypto.randomUUID(), status: 'draft' } };
  }

  // Événements
  if (route === 'events') {
    const q = String(query.q ?? '').toLowerCase();
    return paginate(
      EVENTS.filter((event) => (!query.category || event.categories[0].slug === query.category) && (!q || event.title.toLowerCase().includes(q))),
      query,
    );
  }
  if (/^events\/[^/]+$/.test(route)) {
    const event = EVENTS.find((item) => item.slug === route.split('/')[1]);
    if (!event) throw new DemoError(404, { message: 'Événement introuvable.' });
    return { data: event };
  }
  const registration = /^events\/([^/]+)\/registration$/.exec(route);
  if (registration) {
    requireUser();
    const slugs = load<string[]>(REGISTRATIONS_KEY, DEFAULT_REGISTRATIONS);
    save(REGISTRATIONS_KEY, method === 'DELETE' ? slugs.filter((slug) => slug !== registration[1]) : [...new Set([...slugs, registration[1]])]);
    return method === 'DELETE' ? undefined : { data: { uuid: crypto.randomUUID() } };
  }
  const mine = /^me\/event-registrations\/([^/]+)$/.exec(route);
  if (mine) {
    requireUser();
    if (!load<string[]>(REGISTRATIONS_KEY, DEFAULT_REGISTRATIONS).includes(mine[1])) throw new DemoError(404, { message: 'Inscription introuvable.' });
    return { data: { uuid: 'demo', event: { online_url: 'https://meet.example.org/demo' } } };
  }
  if (route === 'me/events' && method === 'GET') {
    requireUser();
    const events: OwnEvent[] = EVENTS.slice(0, 3).map((event, index) => ({
      uuid: `e0000000-0000-4000-8000-00000000000${index + 1}`,
      slug: index === 2 ? null : event.slug,
      status: (['published', 'pending_review', 'draft'] as const)[index],
      title: event.title,
      starts_at: event.starts_at,
      ends_at: event.ends_at,
      timezone: event.timezone,
      format: event.format,
      registrations_count: index === 0 ? 18 : 0,
      created_at: ago(60 * 24 * (index + 2)),
      submitted_at: index === 1 ? ago(60 * 24 * 2) : null,
    }));
    return paginate(events, query);
  }
  if (route.startsWith('me/events')) {
    requireUser();
    return { data: { uuid: /^me\/events\/([^/]+)/.exec(route)?.[1] ?? crypto.randomUUID(), status: 'draft' } };
  }

  // Compte
  if (route === 'me' && method === 'PATCH') {
    const user = requireUser();
    const edits = load<Record<string, unknown>>(profileKey(user.email), {});
    if (typeof input.name === 'string') edits.name = input.name;
    if (typeof input.country === 'string' || input.country === null) edits.country = input.country ?? undefined;
    if (typeof input.locale === 'string') edits.locale = input.locale;
    if (typeof input.timezone === 'string') edits.timezone = input.timezone;
    if ('avatar' in input) edits.avatar_url = input.avatar ? (mediaUrls.get(String(input.avatar)) ?? demoMediaUrl(input.avatar)) : null;
    save(profileKey(user.email), edits);
    return { data: currentUser() };
  }
  if (route === 'me' && method === 'DELETE') {
    requireUser();
    if (!input.password) throw new DemoError(422, { message: 'Mot de passe requis.', errors: { password: ['Le mot de passe est requis.'] } });
    throw new DemoError(403, { message: 'Démonstration : les comptes de test ne peuvent pas être supprimés.' });
  }
  if (route === 'me') return { data: requireUser() };
  if (route === 'me/notification-preferences') {
    const user = requireUser();
    const key = `loopartner:demo-notifications:${user.email}`;
    const disabled = new Set(load<string[]>(key, []));
    if (method === 'PUT') {
      for (const choice of (input.preferences as { category: string; enabled: boolean }[] | undefined) ?? []) {
        if (choice.enabled) disabled.delete(choice.category);
        else disabled.add(choice.category);
      }
      save(key, [...disabled]);
    }
    const provider = user.roles.includes('prestataire');
    const categories: [string, string, boolean][] = [
      ['account.security', 'Sécurité du compte', true],
      ['directory.match_requests', 'Demandes et propositions', false],
      ['directory.messages', 'Nouveaux messages', false],
      ['events.participation', 'Mes inscriptions aux événements', true],
      ['events.reminders', 'Rappels avant un événement', false],
      ['events.announcements', 'Annonces des organisateurs', false],
      ['marketplace.quotes', 'Devis reçus et refusés', false],
      ['marketplace.quote_decisions', 'Devis acceptés et rétractations', true],
      ['appointments.bookings', 'Rendez-vous', true],
      ['appointments.reminders', 'Rappels de rendez-vous', false],
      ...(provider || user.roles.includes('entrepreneur') ? ([['compliance.reminders', 'Rappels du suivi administratif', false]] as [string, string, boolean][]) : []),
    ];
    return {
      data: categories.map(([category, label, mandatory]) => ({ category, label, mandatory, channels: { mail: mandatory || !disabled.has(category) } })),
    };
  }
  if (route === 'me/agenda') {
    const user = requireUser();
    const role = user.roles[0];
    const registered = load<string[]>(REGISTRATIONS_KEY, DEFAULT_REGISTRATIONS);
    const timed = (source: string, kind: string, ref: string, title: string, starts_at: string, ends_at: string, itemRole: string | null, status = 'confirmed') => ({
      source, kind, ref, title, all_day: false, date: null, starts_at, ends_at, status, role: itemRole,
    });
    const allDay = (source: string, kind: string, ref: string, title: string, days: number, status = 'pending') => {
      const starts = ahead(days, 1);
      return { source, kind, ref, title, all_day: true, date: starts.slice(0, 10), starts_at: `${starts.slice(0, 10)}T00:00:00Z`, ends_at: `${starts.slice(0, 10)}T23:59:59Z`, status, role: null };
    };
    const counterpart = role === 'prestataire' ? 'Lucas Bernard' : 'Compta Claire';
    const items = [
      ...EVENTS.filter((event) => registered.includes(event.slug)).map((event) =>
        timed('events', 'event_registration', event.slug, event.title, event.starts_at, event.ends_at, 'participant'),
      ),
      ...(role === 'prestataire' || role === 'entrepreneur'
        ? [timed('events', 'event_organized', EVENTS[6].slug, EVENTS[6].title, EVENTS[6].starts_at, EVENTS[6].ends_at, 'organizer', 'published')]
        : []),
      timed('appointments', 'appointment', 'demo-rdv-1', `Rendez-vous : premier échange (${counterpart})`, ahead(3, 10), ahead(3, 10, 30), role === 'prestataire' ? 'provider' : 'client'),
      timed('appointments', 'appointment', 'demo-rdv-2', `Rendez-vous : point d’étape (${counterpart})`, ahead(12, 14), ahead(12, 15), role === 'prestataire' ? 'provider' : 'client'),
      ...(role === 'particulier'
        ? []
        : [
            allDay('compliance', 'deadline', 'demo-echeance-1', '[Démo] Déclaration de chiffre d’affaires', 9),
            allDay('compliance', 'deadline', 'demo-echeance-2', '[Démo] Échéance trimestrielle', 38),
          ]),
      ...(role === 'particulier' || role === 'entrepreneur'
        ? [
            allDay('workspaces', 'step', 'demo-etape-1', 'Choisir le statut juridique', 6),
            allDay('workspaces', 'task', 'demo-tache-1', 'Comparer trois devis', 20),
          ]
        : []),
    ];
    const from = typeof query.from === 'string' ? query.from : '0000-01-01';
    const to = typeof query.to === 'string' ? `${query.to}T23:59:59Z` : '9999-12-31';
    const inRange = items.filter((item) => item.ends_at >= from && item.starts_at <= to).sort((a, b) => a.starts_at.localeCompare(b.starts_at));
    const disclaimers =
      role === 'particulier'
        ? {}
        : { compliance: { text: '[Démo] Le suivi administratif est une aide à l’organisation : il ne remplace pas un expert-comptable.', version: 'demo' } };
    return { data: inRange, meta: { from, to: query.to ?? null, sources: ['appointments', 'events', 'compliance', 'workspaces'] }, disclaimers };
  }
  if (route === 'me/agenda/feed') {
    requireUser();
    if (method === 'DELETE') {
      save(FEED_KEY, null);
      return undefined;
    }
    if (method === 'POST') {
      const created = new Date().toISOString();
      save(FEED_KEY, created);
      return {
        data: {
          active: true,
          created_at: created,
          last_accessed_at: null,
          url: `https://api.loopartner.fr/api/v1/agenda/demo${crypto.randomUUID().replace(/-/g, '')}.ics`,
          notice: 'Gardez cette adresse privée : toute personne qui la connaît peut lire les titres et les dates de votre agenda. Régénérez-la si elle a été partagée.',
        },
      };
    }
    const created = load<string | null>(FEED_KEY, null);
    return { data: { active: created !== null, created_at: created, last_accessed_at: null } };
  }
  if (route === 'me/event-registrations') {
    requireUser();
    const registered = load<string[]>(REGISTRATIONS_KEY, DEFAULT_REGISTRATIONS);
    const now = new Date().toISOString();
    const list = EVENTS.filter((event) => registered.includes(event.slug) && (query.when === 'past' ? event.ends_at <= now : event.ends_at > now))
      .sort((a, b) => a.starts_at.localeCompare(b.starts_at))
      .map((event) => ({ uuid: `reg-${event.slug}`, registered_at: ago(60 * 24), event: { ...event, status: 'published' } }));
    return paginate(list, query);
  }
  if (route === 'me/legal') {
    return {
      data: {
        consent_required: false,
        documents: [
          { type: 'terms', version: LEGAL_TEXTS.terms.version, title: LEGAL_TEXTS.terms.title, accepted: true, accepted_at: '2026-11-01T09:00:00Z' },
          { type: 'privacy', version: LEGAL_TEXTS.privacy.version, title: LEGAL_TEXTS.privacy.title, accepted: true, accepted_at: '2026-11-01T09:00:00Z' },
        ],
      },
    };
  }
  if (route === 'media') {
    requireUser();
    const file = body instanceof FormData ? body.get('file') : null;
    if (!(file instanceof Blob)) throw new DemoError(422, { message: 'Image manquante.', errors: { file: ['Choisissez une image.'] } });
    return storeDemoMedia(file).then((media) => {
      mediaUrls.set(media.uuid, media.url);
      return { data: media };
    });
  }

  throw new DemoError(404, { message: 'Introuvable (démonstration).' });
}
