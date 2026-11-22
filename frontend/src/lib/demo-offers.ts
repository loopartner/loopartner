/**
 * Mode démonstration : offres publiques, offres du prestataire, profil prestataire et identité
 * professionnelle fictifs. Les créations du prestataire restent dans le navigateur (localStorage).
 */

import type { Category, OwnOffer, OwnProviderProfile, Paginated, PublicOffer, SellerProfile } from './types';
import type { DemoFail } from './demo-matching';

type Card = { display_name: string; slug: string; headline: string; city: string; country: string; logo_url: null; categories: Category[] };

const iso = (date: Date) => date.toISOString().replace(/\.\d{3}Z$/, 'Z');
const daysAgo = (days: number) => iso(new Date(Date.now() - days * 86_400_000));

const SELLERS: Record<string, { legal_name: string; registration_number: string }> = {
  'cabinet-morel-conseil': { legal_name: 'Morel Conseil SAS', registration_number: '000000000' },
  'compta-claire': { legal_name: 'Claire Martin EI', registration_number: '000000000' },
  'marques-et-co': { legal_name: 'Marques et Compagnie SARL', registration_number: '000000000' },
  'domicile-pro': { legal_name: 'Domicile Pro SAS', registration_number: '000000000' },
};

const RAW: Array<[string, string, string, PublicOffer['pricing']['mode'], number | null, PublicOffer['delivery_mode'], string]> = [
  ['cabinet-morel-conseil', 'Création de SAS clés en main', 'creation-societe', 'fixed', 89000, 'remote', 'Statuts sur mesure, formalités d’immatriculation et accompagnement jusqu’au Kbis, pour une SAS ou une SASU.'],
  ['cabinet-morel-conseil', "Pacte d'associés pour start-up", 'creation-societe', 'from', 120000, 'both', 'Rédaction d’un pacte d’associés adapté à votre future levée : sortie, vesting, gouvernance, non-concurrence.'],
  ['cabinet-morel-conseil', 'CGV et CGU pour site e-commerce', 'juridique', 'fixed', 65000, 'remote', 'Conditions générales de vente et d’utilisation conformes au droit de la consommation, adaptées à votre activité.'],
  ['compta-claire', 'Tenue comptable micro-entreprise', 'comptabilite', 'from', 4900, 'remote', 'Suivi mensuel de vos recettes et dépenses, déclarations et conseils pour rester serein toute l’année.'],
  ['compta-claire', 'Bilan et liasse fiscale', 'comptabilite', 'on_quote', null, 'remote', 'Établissement des comptes annuels et de la liasse fiscale de votre société, avec un rendez-vous de restitution.'],
  ['compta-claire', 'Choix du statut : simulation', 'fiscalite', 'fixed', 25000, 'remote', 'Comparaison chiffrée micro-entreprise, EURL et SASU selon votre chiffre d’affaires prévu, présentée en visio.'],
  ['marques-et-co', 'Recherche d’antériorité de marque', 'propriete-intellectuelle', 'fixed', 29000, 'remote', 'Recherche des marques identiques et similaires avant votre dépôt, avec un rapport et une recommandation.'],
  ['marques-et-co', 'Dépôt de marque INPI', 'propriete-intellectuelle', 'from', 45000, 'remote', 'Préparation et dépôt de votre marque auprès de l’INPI, choix des classes et suivi de la procédure.'],
  ['domicile-pro', 'Domiciliation à Bordeaux', 'domiciliation', 'from', 1900, 'on_site', 'Adresse de siège au centre de Bordeaux, réception et numérisation du courrier, contrat sans engagement de durée.'],
];

function offersFor(providers: Card[]): PublicOffer[] {
  return RAW.map(([slug, title, category, mode, cents, delivery, summary], index) => {
    const provider = providers.find((item) => item.slug === slug) as Card;
    return {
      slug: `${title
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')}`,
      title,
      summary,
      description: `${summary}\n\nCe qui est inclus :\n- un premier échange pour comprendre votre besoin ;\n- la réalisation de la prestation ;\n- un suivi par la messagerie Loopartner.\n\nOffre de démonstration : les informations sont fictives.`,
      category: provider.categories.find((item) => item.slug === category) ?? { slug: category, name: category },
      pricing: { mode, price_cents: cents, currency: 'EUR', tax_basis: 'ttc', indicative: true },
      delivery_mode: delivery,
      country: 'FR',
      city: provider.city,
      provider: { display_name: provider.display_name, slug: provider.slug, headline: provider.headline, city: provider.city, country: provider.country, logo_url: null },
      seller: { declared_status: 'professional', legal_name: SELLERS[slug].legal_name, trade_name: provider.display_name, registration_number: SELLERS[slug].registration_number, country: 'FR' },
      published_at: daysAgo(index * 3 + 1),
      updated_at: daysAgo(index * 3 + 1),
    };
  });
}

function paginate<T>(items: T[], query: Record<string, unknown>): Paginated<T> {
  const perPage = Math.min(50, Number(query.per_page ?? 20) || 20);
  const page = Math.max(1, Number(query.page ?? 1) || 1);
  return { data: items.slice((page - 1) * perPage, page * perPage), meta: { current_page: page, last_page: Math.max(1, Math.ceil(items.length / perPage)), per_page: perPage, total: items.length } };
}

function read<T>(key: string, fallback: T): T {
  try {
    const raw = window.localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as T) : fallback;
  } catch {
    return fallback;
  }
}

function write(key: string, value: unknown) {
  try {
    window.localStorage.setItem(key, JSON.stringify(value));
  } catch {
    // Stockage indisponible.
  }
}

const SELLER_VERSION = '2026-11-17';

/** Profil, identité et offres de départ du compte prestataire de démonstration. */
function seedProfile(card: Card): OwnProviderProfile {
  return {
    display_name: card.display_name,
    slug: card.slug,
    headline: card.headline,
    bio: `${card.display_name} accompagne les créateurs et les dirigeants de TPE. Profil de démonstration : les informations sont fictives.`,
    city: card.city,
    country: card.country,
    website: null,
    logo_url: null,
    categories: card.categories,
    published_at: daysAgo(90),
    status: 'published',
    moderation: null,
    created_at: daysAgo(90),
    updated_at: daysAgo(5),
  };
}

function seedSeller(card: Card): SellerProfile {
  return {
    legal_name: SELLERS[card.slug]?.legal_name ?? card.display_name,
    trade_name: card.display_name,
    legal_form: 'sas',
    registration_number: '000000000',
    registry: 'rcs',
    registry_city: card.city,
    share_capital_cents: 100000,
    vat_number: null,
    address_line1: '1 rue de la Démonstration',
    address_line2: null,
    postal_code: '69001',
    city: card.city,
    country: 'FR',
    phone: '+33 1 00 00 00 00',
    email: 'contact@demo.loopartner.fr',
    insurer_name: null,
    insurer_contact: null,
    insurance_coverage: null,
    insurance_ten_year: false,
    mediator_name: null,
    mediator_url: null,
    regulated_profession_title: null,
    regulated_profession_state: null,
    regulated_profession_body: null,
    terms_url: null,
    quote_footer: null,
    self_certification: { accepted_at: daysAgo(30), version: SELLER_VERSION, current_version: SELLER_VERSION },
    registration_verification: { status: null, verified_at: null },
    missing_for_offers: [],
    missing_for_quotes: [],
    missing_for_consumer_quotes: ['mediator_name', 'mediator_url'],
    updated_at: daysAgo(30),
  };
}

function toOwn(offer: PublicOffer, index: number): OwnOffer {
  return {
    uuid: `0ffe0000-0000-4000-8000-00000000000${index}`,
    slug: offer.slug,
    title: offer.title,
    summary: offer.summary,
    description: offer.description,
    category: offer.category,
    pricing_mode: offer.pricing.mode,
    price_cents: offer.pricing.price_cents,
    currency: 'EUR',
    delivery_mode: offer.delivery_mode,
    country: offer.country,
    city: offer.city,
    status: 'published',
    version: 1,
    moderation: null,
    published_at: offer.published_at,
    unpublished_at: null,
    created_at: offer.published_at ?? daysAgo(10),
    updated_at: offer.updated_at,
  };
}

/** Routes des offres et du profil ; `undefined` si la route n'est pas gérée ici. */
export function demoOffers(
  route: string,
  method: string,
  query: Record<string, unknown>,
  input: Record<string, unknown>,
  context: { providers: Card[]; categories: Category[]; user: { email: string; isProvider: boolean; name: string } | null },
  fail: DemoFail,
): { handled: true; value: unknown } | undefined {
  const done = (value: unknown) => ({ handled: true as const, value });
  const offers = offersFor(context.providers);

  // Lecture publique
  if (route === 'offers') {
    const q = String(query.q ?? '').toLowerCase();
    return done(
      paginate(
        offers.filter(
          (offer) =>
            (!query.category || offer.category?.slug === query.category) &&
            (!query.pricing_mode || offer.pricing.mode === query.pricing_mode) &&
            (!q || `${offer.title} ${offer.summary}`.toLowerCase().includes(q)),
        ),
        query,
      ),
    );
  }
  const publicOffer = /^offers\/([^/]+)$/.exec(route);
  if (publicOffer) {
    const offer = offers.find((item) => item.slug === publicOffer[1]);
    if (!offer) fail(404, { message: 'Offre introuvable.' });
    return done({ data: offer });
  }
  const byProvider = /^providers\/([^/]+)\/offers$/.exec(route);
  if (byProvider) return done(paginate(offers.filter((offer) => offer.provider?.slug === byProvider[1]), query));
  if (/^providers\/[^/]+\/articles$/.test(route)) return done(paginate([], query));

  if (!['me/provider-profile', 'me/seller-profile'].includes(route) && !route.startsWith('me/offers')) return undefined;
  if (!context.user) fail(401, { message: 'Unauthenticated.' });
  const user = context.user as { email: string; isProvider: boolean; name: string };
  if (!user.isProvider) fail(403, { message: 'Réservé aux prestataires.' });

  const card = context.providers.find((item) => item.display_name === user.name) ?? context.providers[0];
  const profileKey = `loopartner:demo-provider-profile:${user.email}`;
  const sellerKey = `loopartner:demo-seller:${user.email}`;
  const offersKey = `loopartner:demo-own-offers:${user.email}`;

  if (route === 'me/provider-profile') {
    if (method === 'PUT') {
      const current = read<OwnProviderProfile>(profileKey, seedProfile(card));
      const slugs = Array.isArray(input.categories) ? (input.categories as string[]) : current.categories.map((item) => item.slug);
      if (input.status === 'published' && slugs.length === 0) fail(422, { message: 'Catégorie manquante.', errors: { categories: ['Choisissez au moins une catégorie pour publier.'] } });
      const flat = context.categories.flatMap((parent) => [parent, ...(parent.children ?? [])]);
      const updated: OwnProviderProfile = {
        ...current,
        display_name: String(input.display_name ?? current.display_name),
        headline: (input.headline as string | null) ?? null,
        bio: (input.bio as string | null) ?? null,
        city: (input.city as string | null) ?? null,
        country: (input.country as string | null) ?? null,
        website: (input.website as string | null) ?? null,
        categories: slugs.map((slug) => flat.find((item) => item.slug === slug) ?? { slug, name: slug }),
        status: input.status === 'draft' ? 'draft' : 'published',
        updated_at: iso(new Date()),
      };
      write(profileKey, updated);
      return done({ data: updated });
    }
    return done({ data: read<OwnProviderProfile>(profileKey, seedProfile(card)) });
  }

  if (route === 'me/seller-profile') {
    if (method === 'PUT') {
      if (!input.self_certification) fail(422, { message: 'Déclaration manquante.', errors: { self_certification: ['Cochez la déclaration sur l’honneur.'] } });
      const current = read<SellerProfile>(sellerKey, seedSeller(card));
      const updated: SellerProfile = { ...current, ...(input as Partial<SellerProfile>), self_certification: { accepted_at: iso(new Date()), version: SELLER_VERSION, current_version: SELLER_VERSION }, updated_at: iso(new Date()) };
      updated.missing_for_consumer_quotes = updated.mediator_name && updated.mediator_url ? [] : ['mediator_name', 'mediator_url'];
      write(sellerKey, updated);
      return done({ data: updated });
    }
    return done({ data: read<SellerProfile>(sellerKey, seedSeller(card)) });
  }

  // Offres du prestataire
  const own = read<OwnOffer[]>(offersKey, offers.filter((offer) => offer.provider?.slug === card.slug).map(toOwn));
  const persist = (list: OwnOffer[]) => write(offersKey, list);
  if (route === 'me/offers' && method === 'GET') return done(paginate(own, query));
  if (route === 'me/offers' && method === 'POST') {
    const created: OwnOffer = {
      uuid: crypto.randomUUID(),
      slug: null,
      title: String(input.title ?? ''),
      summary: String(input.summary ?? ''),
      description: String(input.description ?? ''),
      category: context.categories.flatMap((parent) => [parent, ...(parent.children ?? [])]).find((item) => item.slug === input.category) ?? null,
      pricing_mode: (input.pricing_mode as OwnOffer['pricing_mode']) ?? 'fixed',
      price_cents: (input.price_cents as number | undefined) ?? null,
      currency: 'EUR',
      delivery_mode: (input.delivery_mode as OwnOffer['delivery_mode']) ?? 'remote',
      country: String(input.country ?? 'FR'),
      city: (input.city as string | null) ?? null,
      status: 'draft',
      version: 1,
      moderation: null,
      published_at: null,
      unpublished_at: null,
      created_at: iso(new Date()),
      updated_at: iso(new Date()),
    };
    if (created.title.length < 10) fail(422, { message: 'Titre trop court.', errors: { title: ['Le titre doit contenir au moins 10 caractères.'] } });
    persist([created, ...own]);
    return done({ data: created });
  }
  const match = /^me\/offers\/([^/]+)(?:\/(publish|unpublish))?$/.exec(route);
  if (match) {
    const offer = own.find((item) => item.uuid === match[1]);
    if (!offer) fail(404, { message: 'Offre introuvable.' });
    const target = offer as OwnOffer;
    if (match[2] === 'publish') {
      target.status = 'published';
      target.published_at = iso(new Date());
      target.slug = target.slug ?? `offre-${target.uuid.slice(0, 8)}`;
    } else if (match[2] === 'unpublish') {
      target.status = 'unpublished';
      target.unpublished_at = iso(new Date());
    } else if (method === 'DELETE') {
      persist(own.filter((item) => item.uuid !== target.uuid));
      return done(undefined);
    } else if (method === 'PATCH') {
      Object.assign(target, input, { updated_at: iso(new Date()), version: target.version + 1 });
      if (typeof input.category === 'string') {
        target.category = context.categories.flatMap((parent) => [parent, ...(parent.children ?? [])]).find((item) => item.slug === input.category) ?? target.category;
      }
      if (input.pricing_mode === 'on_quote') target.price_cents = null;
    } else {
      return done({ data: target });
    }
    persist(own);
    return done({ data: target });
  }
  return undefined;
}
