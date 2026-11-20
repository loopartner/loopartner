/**
 * Mode démonstration (NEXT_PUBLIC_DEMO_MODE=true) : le frontend répond lui-même avec des
 * données fictives, sans appeler l'API. Il sert à montrer le rendu sur un aperçu Vercel
 * tant que l'API n'est pas en ligne. **Jamais en production** : la variable n'est définie que pour
 * l'environnement Preview, et un bandeau « Démonstration » reste visible sur chaque page.
 *
 * Comptes de test (fictifs, sans existence côté API) : voir DEMO_ACCOUNTS.
 * La session et les créations (projets, inscriptions) restent dans le navigateur (localStorage).
 */

import type { ArticleDetail, ArticleSummary, BrowsableProject, Category, Me, OwnEvent, OwnProject, PublicEvent, Role } from './types';

export const DEMO_MODE = process.env.NEXT_PUBLIC_DEMO_MODE === 'true';

export const DEMO_PASSWORD = 'Demo-Loopartner-2026';

export const DEMO_ACCOUNTS: Record<string, { role: Role; name: string; label: string }> = {
  'client@demo.loopartner.fr': { role: 'particulier', name: 'Camille Durand', label: 'Client (particulier)' },
  'prestataire@demo.loopartner.fr': { role: 'prestataire', name: 'Cabinet Morel Conseil', label: 'Prestataire' },
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

const ARTICLES: ArticleSummary[] = [
  ['Le guide pour créer sa SASU en 2026', 'creation-societe', 'editorial'],
  'Optimisation fiscale : les questions à se poser,fiscalite,provider'.split(','),
  ['RGPD : mettre son site en conformité', 'juridique', 'editorial'],
  ['Où domicilier son entreprise ?', 'domiciliation', 'entrepreneur'],
  ['Protéger sa marque : pourquoi c’est essentiel', 'propriete-intellectuelle', 'editorial'],
  ['Tenir sa comptabilité en ligne : les avantages', 'comptabilite', 'provider'],
  ['SARL ou SAS : le comparatif', 'creation-societe', 'entrepreneur'],
  ['TVA : comprendre la franchise en base', 'fiscalite', 'editorial'],
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
        ? { kind: 'provider' as const, name: 'Cabinet Morel Conseil', slug: 'cabinet-morel-conseil', headline: 'Juristes en droit des sociétés', logo_url: null }
        : { kind: 'entrepreneur' as const, name: 'Claire M.', slug: null, headline: null, logo_url: null },
  reading_time_minutes: 4 + (index % 4),
  published_at: ago((index + 1) * 2 * 24 * 60),
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
  { slug: 'cabinet-morel-conseil', display_name: 'Cabinet Morel Conseil', headline: 'Juristes en droit des sociétés', city: 'Lyon', country: 'FR', logo_url: null, categories: [cat('juridique'), cat('creation-societe')] },
  { slug: 'compta-claire', display_name: 'Compta Claire', headline: 'Expertise comptable pour indépendants', city: 'Nantes', country: 'FR', logo_url: null, categories: [cat('comptabilite'), cat('fiscalite')] },
  { slug: 'marques-et-co', display_name: 'Marques & Co', headline: 'Conseil en propriété industrielle', city: 'Paris', country: 'FR', logo_url: null, categories: [cat('propriete-intellectuelle')] },
  { slug: 'domicile-pro', display_name: 'Domicile Pro', headline: "Domiciliation d'entreprises", city: 'Bordeaux', country: 'FR', logo_url: null, categories: [cat('domiciliation')] },
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

function currentUser(): Me | null {
  const email = load<string | null>(SESSION_KEY, null);
  const account = email ? DEMO_ACCOUNTS[email] : undefined;
  if (!email || !account) return null;
  return {
    name: account.name,
    email,
    roles: [account.role],
    locale: 'fr',
    timezone: 'Europe/Paris',
    country: 'FR',
    avatar_url: null,
    email_verified: true,
    two_factor_enabled: false,
    two_factor_required: false,
    premium: { active: false, source: null, starts_at: null, ends_at: null },
    article_quota: account.role === 'prestataire' ? { premium: false, limit: 2, used: 0, remaining: 2 } : null,
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
  if (route.startsWith('auth/')) return ok;

  // Références publiques
  if (route === 'categories') return { data: CATEGORIES };
  if (route === 'legal/documents') {
    return {
      data: (['terms', 'privacy'] as const).map((type) => ({
        type,
        current: { type, version: 'demo', title: type === 'terms' ? "Conditions générales d'utilisation" : 'Politique de confidentialité', effective_at: ago(60 * 24 * 30) },
        upcoming: null,
      })),
    };
  }
  if (route.startsWith('legal/documents/')) {
    const type = route.split('/')[2];
    return {
      data: {
        type,
        version: 'demo',
        title: type === 'terms' ? "Conditions générales d'utilisation" : 'Politique de confidentialité',
        effective_at: ago(60 * 24 * 30),
        body: '[Démonstration] Ce texte est un exemple sans valeur juridique. Les documents réels seront rédigés et validés par le juriste avant l’ouverture.',
      },
    };
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
  if (/^projects\/[^/]+\/proposals$/.test(route)) {
    requireUser();
    return { data: { uuid: crypto.randomUUID(), status: 'pending' } };
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
    const slugs = load<string[]>(REGISTRATIONS_KEY, []);
    save(REGISTRATIONS_KEY, method === 'DELETE' ? slugs.filter((slug) => slug !== registration[1]) : [...new Set([...slugs, registration[1]])]);
    return method === 'DELETE' ? undefined : { data: { uuid: crypto.randomUUID() } };
  }
  const mine = /^me\/event-registrations\/([^/]+)$/.exec(route);
  if (mine) {
    requireUser();
    if (!load<string[]>(REGISTRATIONS_KEY, []).includes(mine[1])) throw new DemoError(404, { message: 'Inscription introuvable.' });
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
  if (route === 'me') return { data: requireUser() };
  if (route === 'me/legal') return { data: { consent_required: false, documents: [] } };
  if (route === 'media') {
    requireUser();
    const file = body instanceof FormData ? body.get('file') : null;
    return { data: { uuid: crypto.randomUUID(), url: file instanceof Blob ? URL.createObjectURL(file) : '' } };
  }

  throw new DemoError(404, { message: 'Introuvable (démonstration).' });
}
