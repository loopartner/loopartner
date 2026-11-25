/**
 * Mode démonstration : demandes de mise en relation, conversations, messages et signalements.
 *
 * Une seule « base » fictive, partagée par tous les comptes de test du navigateur
 * (localStorage `loopartner:demo-world`) : une demande relie deux parties, et chacune la voit de
 * son côté. Un message envoyé par Cabinet Morel Conseil apparaît chez Lucas Bernard quand on se
 * connecte avec son compte, comme sur le vrai site (où les deux lisent la même conversation sur
 * le serveur). Une partie sans compte de test (porteur fictif, Domicile Pro) répond
 * automatiquement.
 */

import type { ChatMessage, Conversation, Counterpart, MatchRequest, ModerationInfo, Paginated } from './types';

export type DemoFail = (status: number, body: Record<string, unknown>) => never;

/* ---------------------------------------------------------------- Parties */

type ProviderCard = NonNullable<Counterpart['provider']>;

/** Une partie d'une mise en relation : un compte de test (email) ou un personnage fictif. */
interface Party {
  email: string | null;
  kind: 'provider' | 'particulier' | 'entrepreneur';
  name: string;
  card: ProviderCard | null;
}

const CARDS: Record<string, ProviderCard> = {
  morel: { display_name: 'Cabinet Morel Conseil', slug: 'cabinet-morel-conseil', headline: 'Juristes en droit des sociétés', city: 'Lyon', country: 'FR', logo_url: '/images/hero/expert-1.webp' },
  claire: { display_name: 'Compta Claire', slug: 'compta-claire', headline: 'Expertise comptable pour indépendants', city: 'Nantes', country: 'FR', logo_url: '/images/hero/expert-2.webp' },
  marques: { display_name: 'Marques & Co', slug: 'marques-et-co', headline: 'Conseil en propriété industrielle', city: 'Paris', country: 'FR', logo_url: '/images/hero/expert-3.webp' },
  domicile: { display_name: 'Domicile Pro', slug: 'domicile-pro', headline: "Domiciliation d'entreprises", city: 'Bordeaux', country: 'FR', logo_url: null },
};

const PARTIES = {
  camille: { email: 'client@demo.loopartner.fr', kind: 'particulier', name: 'Camille Durand', card: null },
  lucas: { email: 'entrepreneur@demo.loopartner.fr', kind: 'entrepreneur', name: 'Lucas Bernard', card: null },
  morel: { email: 'prestataire@demo.loopartner.fr', kind: 'provider', name: 'Cabinet Morel Conseil', card: CARDS.morel },
  claire: { email: 'comptable@demo.loopartner.fr', kind: 'provider', name: 'Compta Claire', card: CARDS.claire },
  marques: { email: 'marques@demo.loopartner.fr', kind: 'provider', name: 'Marques & Co', card: CARDS.marques },
  domicile: { email: null, kind: 'provider', name: 'Domicile Pro', card: CARDS.domicile },
  julie: { email: null, kind: 'particulier', name: 'Julie Martin', card: null },
  nora: { email: null, kind: 'particulier', name: 'Nora Petit', card: null },
} satisfies Record<string, Party>;

/** Prestataire d'un slug : compte de test s'il en existe un, sinon personnage fictif. */
function providerParty(slug: string): Party {
  const known = Object.values(PARTIES).find((party) => party.card?.slug === slug);
  if (known) return known;
  return { email: null, kind: 'provider', name: slug.replaceAll('-', ' '), card: { display_name: slug.replaceAll('-', ' '), slug, headline: null, city: null, country: 'FR', logo_url: null } };
}

/* ---------------------------------------------------------------- Données */

interface StoredRequest {
  uuid: string;
  type: 'proposal' | 'contact';
  status: MatchRequest['status'];
  author: Party;
  recipient: Party;
  message: string | null;
  price: MatchRequest['price'];
  project: MatchRequest['project'];
  conversation: string | null;
  created_at: string;
  expires_at: string | null;
  responded_at: string | null;
}

interface StoredConversation {
  uuid: string;
  request: string;
  /** Dernier message lu, par participant (email, ou nom pour un personnage fictif). */
  read_up_to: Record<string, string | null>;
  blocked_by: string[];
}

interface StoredMessage {
  uuid: string;
  conversation: string;
  body: string;
  /** Email ou nom de l'expéditeur. */
  from: string;
  sent_at: string;
}

interface World {
  version: 2;
  requests: StoredRequest[];
  conversations: StoredConversation[];
  messages: StoredMessage[];
}

const iso = (date: Date) => date.toISOString().replace(/\.\d{3}Z$/, 'Z');
const minutesAgo = (minutes: number) => iso(new Date(Date.now() - minutes * 60_000));
const idOf = (party: Party) => party.email ?? party.name;

function project(title: string, slug: string, label: string, min: number | null, max: number | null): NonNullable<MatchRequest['project']> {
  return { uuid: crypto.randomUUID(), title, description: 'Projet de démonstration.', category: { slug, name: label }, budget_min: min, budget_max: max, currency: 'EUR', country: 'FR', city: null, remote_ok: true };
}

function newRequest(
  partial: Pick<StoredRequest, 'type' | 'status' | 'author' | 'recipient' | 'message'> & Partial<StoredRequest>,
  ageMinutes: number,
): StoredRequest {
  return {
    uuid: crypto.randomUUID(),
    price: null,
    project: null,
    conversation: null,
    created_at: minutesAgo(ageMinutes),
    expires_at: partial.status === 'pending' ? iso(new Date(Date.now() + (30 * 24 * 60 - ageMinutes) * 60_000)) : null,
    responded_at: partial.status === 'pending' ? null : minutesAgo(Math.max(ageMinutes - 60, 1)),
    ...partial,
  };
}

function seed(): World {
  const world: World = { version: 2, requests: [], conversations: [], messages: [] };
  const withConversation = (item: StoredRequest, lines: Array<[Party, string, number]>) => {
    const conversation: StoredConversation = { uuid: crypto.randomUUID(), request: item.uuid, read_up_to: {}, blocked_by: [] };
    item.conversation = conversation.uuid;
    world.conversations.push(conversation);
    world.messages.push(...lines.map(([from, body, age]) => ({ uuid: crypto.randomUUID(), conversation: conversation.uuid, body, from: idOf(from), sent_at: minutesAgo(age) })));
    // Chacun a lu jusqu'à son dernier message envoyé : la suite est « non lue ».
    for (const party of [item.author, item.recipient]) {
      const mine = world.messages.filter((message) => message.conversation === conversation.uuid && message.from === idOf(party));
      conversation.read_up_to[idOf(party)] = mine.at(-1)?.uuid ?? null;
    }
  };

  const sas = project("Création d'une SAS pour une application mobile", 'creation-societe', 'Création de société', 500, 1000);

  // Compta Claire ↔ Camille Durand : proposition acceptée, conversation en cours (devis reçu en démo).
  const claire = newRequest(
    {
      type: 'proposal',
      status: 'accepted',
      author: PARTIES.claire,
      recipient: PARTIES.camille,
      project: sas,
      price: { amount: 700, currency: 'EUR' },
      message: "Bonjour, je peux m'occuper de la partie comptable du lancement : choix du régime, prévisionnel et mise en place de la tenue des comptes.",
    },
    60 * 26,
  );
  withConversation(claire, [
    [PARTIES.claire, "Bonjour Camille, merci d'avoir accepté ma proposition. Avez-vous déjà une idée du chiffre d'affaires de la première année ?", 60 * 25],
    [PARTIES.camille, "Bonjour ! Nous visons environ 60 000 € la première année, avec un lancement de l'application en mars.", 60 * 24],
    [PARTIES.claire, 'Parfait. Je vous prépare un prévisionnel et une comparaison des régimes. Seriez-vous disponible jeudi pour en parler ?', 50],
    [PARTIES.claire, 'Je vous envoie aussi un devis détaillé.', 45],
  ]);
  world.requests.push(claire);

  // Marques & Co → Camille : proposition en attente, à accepter côté client.
  world.requests.push(
    newRequest(
      {
        type: 'proposal',
        status: 'pending',
        author: PARTIES.marques,
        recipient: PARTIES.camille,
        project: sas,
        price: { amount: 450, currency: 'EUR' },
        message: "Bonjour, nous proposons la recherche d'antériorité et le dépôt du nom de votre application auprès de l'INPI, sous dix jours.",
      },
      90,
    ),
  );

  // Camille → Cabinet Morel Conseil : contact en attente, à accepter côté prestataire.
  world.requests.push(
    newRequest(
      {
        type: 'contact',
        status: 'pending',
        author: PARTIES.camille,
        recipient: PARTIES.morel,
        project: sas,
        message: "Bonjour, je crée une SAS pour mon application et j'aimerais être accompagnée pour les statuts et l'immatriculation. Quel serait votre délai ?",
      },
      35,
    ),
  );

  // Camille → Domicile Pro (sans compte de test) : contact en attente.
  world.requests.push(
    newRequest(
      {
        type: 'contact',
        status: 'pending',
        author: PARTIES.camille,
        recipient: PARTIES.domicile,
        message: "Bonjour, je cherche une domiciliation à Lyon pour ma future SAS, avec réception et scan du courrier. Quels sont vos délais d'ouverture ?",
      },
      60 * 5,
    ),
  );

  // Lucas Bernard ↔ Cabinet Morel Conseil : contact accepté, conversation en cours.
  const lucas = newRequest(
    {
      type: 'contact',
      status: 'accepted',
      author: PARTIES.lucas,
      recipient: PARTIES.morel,
      message: "Bonjour, nous sommes trois associés et voulons rédiger un pacte d'associés avant une levée de fonds. Est-ce dans vos compétences ?",
    },
    60 * 30,
  );
  withConversation(lucas, [
    [PARTIES.morel, "Bonjour Lucas, oui tout à fait. Pouvez-vous me dire si la SAS est déjà immatriculée et combien d'investisseurs sont prévus ?", 60 * 29],
    [PARTIES.lucas, 'Oui, immatriculée depuis juin. Un seul fonds pour le moment, peut-être deux business angels.', 60 * 3],
    [PARTIES.lucas, 'Pouvez-vous nous envoyer un devis ?', 20],
  ]);
  world.requests.push(lucas);

  // Cabinet Morel Conseil → porteurs fictifs : proposition en attente et proposition expirée.
  world.requests.push(
    newRequest(
      {
        type: 'proposal',
        status: 'pending',
        author: PARTIES.morel,
        recipient: PARTIES.julie,
        project: project('Audit de conformité RGPD pour une boutique e-commerce', 'juridique', 'Juridique', 1000, 2500),
        price: { amount: 1800, currency: 'EUR' },
        message: 'Bonjour, nous réalisons des audits RGPD complets pour les boutiques Shopify, avec la mise à jour des documents et du bandeau cookies.',
      },
      60 * 8,
    ),
    newRequest(
      {
        type: 'proposal',
        status: 'expired',
        author: PARTIES.morel,
        recipient: PARTIES.nora,
        project: project('Rédaction de CGV pour une marketplace', 'juridique', 'Juridique', 500, 1000),
        message: 'Bonjour, nous pouvons rédiger vos CGV et CGU sous dix jours ouvrés.',
      },
      60 * 24 * 35,
    ),
  );

  return world;
}

/* ---------------------------------------------------------------- Stockage */

const WORLD_KEY = 'loopartner:demo-world';

function load(): World {
  try {
    const raw = window.localStorage.getItem(WORLD_KEY);
    if (raw) {
      const parsed = JSON.parse(raw) as World;
      if (parsed.version === 2) return parsed;
    }
  } catch {
    // Stockage indisponible : données recréées.
  }
  const world = seed();
  save(world);
  return world;
}

function save(world: World) {
  try {
    window.localStorage.setItem(WORLD_KEY, JSON.stringify(world));
  } catch {
    // Stockage indisponible : la démonstration continue sans mémoire.
  }
}

/** Parties d'une demande (pour les devis de démonstration). */
export function demoMatchParties(uuid: string): { provider: string | null; client: string | null; clientName: string; providerCard: ProviderCard | null } | null {
  const item = load().requests.find((entry) => entry.uuid === uuid);
  if (!item) return null;
  const providerSide = item.author.kind === 'provider' ? item.author : item.recipient;
  const clientSide = item.author.kind === 'provider' ? item.recipient : item.author;
  return { provider: providerSide.email, client: clientSide.email, clientName: clientSide.name, providerCard: providerSide.card };
}

/* ---------------------------------------------------------------- Lecture */

const visible = (world: World, conversation: string) =>
  world.messages.filter((message) => message.conversation === conversation && new Date(message.sent_at).getTime() <= Date.now());

function involved(item: StoredRequest, me: string) {
  return idOf(item.author) === me || idOf(item.recipient) === me;
}

/** L'autre partie vue par `me` (le nom d'un porteur n'apparaît qu'une fois la demande acceptée). */
function counterpartOf(item: StoredRequest, me: string): Counterpart {
  const other = idOf(item.author) === me ? item.recipient : item.author;
  if (other.kind === 'provider') return { deleted: false, account_type: 'prestataire', country: null, name: null, provider: other.card };
  return { deleted: false, account_type: other.kind, country: 'FR', name: item.status === 'accepted' ? other.name : null, provider: null };
}

function toRequest(item: StoredRequest, me: string): MatchRequest {
  return {
    uuid: item.uuid,
    type: item.type,
    status: item.status,
    role: idOf(item.author) === me ? 'author' : 'recipient',
    message: item.message,
    price: item.price,
    project: item.project,
    counterpart: counterpartOf(item, me),
    conversation: item.conversation ? { uuid: item.conversation } : null,
    created_at: item.created_at,
    expires_at: item.expires_at,
    responded_at: item.responded_at,
  };
}

const toMessage = (message: StoredMessage, me: string): ChatMessage => ({ uuid: message.uuid, body: message.body, mine: message.from === me, sent_at: message.sent_at });

function unreadCount(world: World, conversation: StoredConversation, me: string): number {
  const list = visible(world, conversation.uuid);
  const marker = conversation.read_up_to[me];
  const index = marker ? list.findIndex((message) => message.uuid === marker) : -1;
  return list.slice(index + 1).filter((message) => message.from !== me).length;
}

function toConversation(world: World, conversation: StoredConversation, me: string): Conversation {
  const item = world.requests.find((entry) => entry.uuid === conversation.request) as StoredRequest;
  const last = visible(world, conversation.uuid).at(-1);
  const blockedByMe = conversation.blocked_by.includes(me);
  return {
    uuid: conversation.uuid,
    match_request: { uuid: item.uuid, type: item.type },
    project: item.project ? { uuid: item.project.uuid, title: item.project.title } : null,
    counterpart: counterpartOf(item, me),
    last_message: last ? { uuid: last.uuid, excerpt: last.body.slice(0, 140), mine: last.from === me, sent_at: last.sent_at } : null,
    unread_count: unreadCount(world, conversation, me),
    blocked_by_me: blockedByMe,
    can_send: conversation.blocked_by.length === 0,
    created_at: item.responded_at ?? item.created_at,
    last_activity_at: last?.sent_at ?? null,
  };
}

function paginate<T>(items: T[], query: Record<string, unknown>): Paginated<T> {
  const perPage = Math.min(50, Number(query.per_page ?? 20) || 20);
  const page = Math.max(1, Number(query.page ?? 1) || 1);
  return { data: items.slice((page - 1) * perPage, page * perPage), meta: { current_page: page, last_page: Math.max(1, Math.ceil(items.length / perPage)), per_page: perPage, total: items.length } };
}

/* ---------------------------------------------------------------- Routes */

const MODERATION_INFO: ModerationInfo = {
  contact: { users_email: 'moderation@demo.loopartner.fr', authorities_email: 'autorites@demo.loopartner.fr', languages: ['fr', 'en'] },
  target_types: [],
  categories: [
    { key: 'scam_fraud', label: 'Arnaque ou fraude', priority: 'standard', identity_optional: false },
    { key: 'hate_harassment', label: 'Contenu haineux ou harcèlement', priority: 'standard', identity_optional: false },
    { key: 'privacy', label: 'Atteinte à la vie privée ou aux données personnelles', priority: 'standard', identity_optional: false },
    { key: 'intellectual_property', label: 'Contrefaçon ou atteinte à la propriété intellectuelle', priority: 'standard', identity_optional: false },
    { key: 'illegal_activity', label: 'Activité ou profession illégale', priority: 'standard', identity_optional: false },
    { key: 'child_sexual_abuse', label: 'Abus sexuels sur mineurs', priority: 'urgent', identity_optional: true },
    { key: 'violence_terrorism', label: 'Violence ou terrorisme', priority: 'urgent', identity_optional: false },
    { key: 'terms_violation', label: 'Non-respect des conditions d’utilisation', priority: 'standard', identity_optional: false },
  ],
  appeal_window_months: 6,
  explanation: { min: 20, max: 5000 },
};

const AUTO_REPLY = 'Merci pour votre message ! Je reviens vers vous très vite. (Réponse automatique de démonstration : ce contact n’a pas de compte de test.)';

/**
 * Routes de la mise en relation et du signalement. Renvoie `undefined` si la route n'est pas
 * gérée ici ; `user` est null sans session de démonstration.
 */
export function demoMatching(
  route: string,
  method: string,
  query: Record<string, unknown>,
  input: Record<string, unknown>,
  user: { email: string; isProvider: boolean; name?: string; role?: string } | null,
  fail: DemoFail,
): { handled: true; value: unknown } | undefined {
  const done = (value: unknown) => ({ handled: true as const, value });

  if (route === 'moderation/info') return done({ data: MODERATION_INFO });
  if (route === 'moderation/notices' && method === 'POST') {
    const explanation = String(input.explanation ?? '');
    if (explanation.length < 20) fail(422, { message: 'Explication trop courte.', errors: { explanation: ['Expliquez le problème en 20 caractères au moins.'] } });
    return done({ data: { uuid: crypto.randomUUID(), status: 'open' }, ...(user ? {} : { tracking_token: 'DEMO-' + crypto.randomUUID().replaceAll('-', '').slice(0, 40) }) });
  }

  const isMatchingRoute =
    route.startsWith('me/match-requests') || route.startsWith('me/conversations') || /^providers\/[^/]+\/contact$/.test(route) || /^projects\/[^/]+\/proposals$/.test(route);
  if (!isMatchingRoute) return undefined;
  if (!user) fail(401, { message: 'Unauthenticated.' });
  const account = user as { email: string; isProvider: boolean; name?: string; role?: string };
  const me = account.email;
  const world = load();
  const persist = () => save(world);
  const myParty: Party =
    Object.values(PARTIES).find((party) => party.email === me) ??
    ({ email: me, kind: account.isProvider ? 'provider' : account.role === 'entrepreneur' ? 'entrepreneur' : 'particulier', name: account.name ?? me, card: null } as Party);

  // Nouvelles demandes
  const contact = /^providers\/([^/]+)\/contact$/.exec(route);
  if (contact) {
    if (account.isProvider) fail(403, { message: 'Seuls les particuliers et les entrepreneurs peuvent contacter un prestataire.' });
    const target = providerParty(contact[1]);
    if (world.requests.some((item) => idOf(item.author) === me && idOf(item.recipient) === idOf(target) && ['pending', 'accepted'].includes(item.status))) {
      fail(409, { message: 'Vous avez déjà une demande en cours avec ce prestataire.' });
    }
    const message = String(input.message ?? '');
    if (message.length < 20) fail(422, { message: 'Message trop court.', errors: { message: ['Le message doit contenir au moins 20 caractères.'] } });
    const created = newRequest({ type: 'contact', status: 'pending', author: myParty, recipient: target, message }, 0);
    world.requests.unshift(created);
    persist();
    return done({ data: toRequest(created, me) });
  }
  if (/^projects\/[^/]+\/proposals$/.test(route)) {
    const message = String(input.message ?? '');
    if (message.length < 20) fail(422, { message: 'Message trop court.', errors: { message: ['Le message doit contenir au moins 20 caractères.'] } });
    const created = newRequest(
      {
        type: 'proposal',
        status: 'pending',
        author: myParty,
        recipient: PARTIES.julie,
        message,
        price: input.price_amount ? { amount: Number(input.price_amount), currency: String(input.price_currency ?? 'EUR') } : null,
        project: project('Projet de démonstration', 'juridique', 'Juridique', null, null),
      },
      0,
    );
    world.requests.unshift(created);
    persist();
    return done({ data: toRequest(created, me) });
  }

  // Demandes
  if (route === 'me/match-requests') {
    const sent = query.box === 'sent';
    const list = world.requests
      .filter((item) => (sent ? idOf(item.author) === me : idOf(item.recipient) === me) && (!query.status || item.status === query.status))
      .sort((a, b) => b.created_at.localeCompare(a.created_at))
      .map((item) => toRequest(item, me));
    return done(paginate(list, query));
  }
  const single = /^me\/match-requests\/([^/]+)$/.exec(route);
  if (single) {
    const item = world.requests.find((entry) => entry.uuid === single[1] && involved(entry, me));
    if (!item) fail(404, { message: 'Demande introuvable.' });
    return done({ data: toRequest(item as StoredRequest, me) });
  }
  const action = /^me\/match-requests\/([^/]+)\/(accept|decline|withdraw|block|report)$/.exec(route);
  if (action) {
    const item = world.requests.find((entry) => entry.uuid === action[1] && involved(entry, me));
    if (!item) fail(404, { message: 'Demande introuvable.' });
    const target = item as StoredRequest;
    const isRecipient = idOf(target.recipient) === me;
    if (action[2] === 'report') return done({ data: { uuid: crypto.randomUUID() } });
    if (action[2] === 'withdraw' ? isRecipient : !isRecipient) fail(403, { message: 'Cette action revient à l’autre partie.' });
    if (target.status !== 'pending' && action[2] !== 'block') fail(409, { message: "Cette demande n'est plus en attente." });
    if (action[2] === 'accept') {
      target.status = 'accepted';
      target.responded_at = iso(new Date());
      target.expires_at = null;
      const conversation: StoredConversation = { uuid: crypto.randomUUID(), request: target.uuid, read_up_to: {}, blocked_by: [] };
      world.conversations.push(conversation);
      target.conversation = conversation.uuid;
    } else if (action[2] === 'decline' || action[2] === 'block') {
      if (target.status === 'pending') {
        target.status = 'declined';
        target.responded_at = iso(new Date());
        target.expires_at = null;
      }
    } else {
      target.status = 'withdrawn';
      target.expires_at = null;
    }
    persist();
    return done(action[2] === 'block' ? undefined : { data: toRequest(target, me) });
  }

  // Conversations (seulement celles où je suis partie)
  const mine = world.conversations.filter((conversation) => {
    const item = world.requests.find((entry) => entry.uuid === conversation.request);
    return item ? involved(item, me) : false;
  });
  if (route === 'me/conversations/unread') {
    const counts = mine.map((conversation) => unreadCount(world, conversation, me));
    return done({ data: { conversations: counts.filter((count) => count > 0).length, messages: counts.reduce((sum, count) => sum + count, 0) } });
  }
  if (route === 'me/conversations') {
    const list = mine
      .map((conversation) => toConversation(world, conversation, me))
      .sort((a, b) => (b.last_activity_at ?? b.created_at).localeCompare(a.last_activity_at ?? a.created_at));
    return done(paginate(list, query));
  }
  const conversationRoute = /^me\/conversations\/([^/]+)(?:\/(messages|read|block))?(?:\/([^/]+)\/report)?$/.exec(route);
  if (conversationRoute) {
    const conversation = mine.find((entry) => entry.uuid === conversationRoute[1]);
    if (!conversation) fail(404, { message: 'Conversation introuvable.' });
    const current = conversation as StoredConversation;
    const item = world.requests.find((entry) => entry.uuid === current.request) as StoredRequest;
    const other = idOf(item.author) === me ? item.recipient : item.author;
    const part = conversationRoute[2];

    if (conversationRoute[3]) return done({ data: { uuid: crypto.randomUUID() } });
    if (!part) return done({ data: toConversation(world, current, me) });
    if (part === 'block') {
      current.blocked_by = method === 'DELETE' ? current.blocked_by.filter((entry) => entry !== me) : [...new Set([...current.blocked_by, me])];
      persist();
      return done(undefined);
    }
    if (part === 'read') {
      current.read_up_to[me] = String(input.up_to ?? visible(world, current.uuid).at(-1)?.uuid ?? '') || null;
      persist();
      return done(undefined);
    }
    if (method === 'POST') {
      if (current.blocked_by.length > 0) fail(403, { message: current.blocked_by.includes(me) ? 'Vous avez bloqué cette personne.' : 'Vous ne pouvez plus écrire dans cette conversation.' });
      const body = String(input.body ?? '').trim();
      if (!body) fail(422, { message: 'Message vide.', errors: { body: ['Écrivez un message.'] } });
      const sent: StoredMessage = { uuid: crypto.randomUUID(), conversation: current.uuid, body, from: me, sent_at: iso(new Date()) };
      world.messages.push(sent);
      // Un personnage sans compte de test répond tout seul ; un compte de test répond quand on
      // se connecte avec lui (comme sur le vrai site).
      if (!other.email) world.messages.push({ uuid: crypto.randomUUID(), conversation: current.uuid, body: AUTO_REPLY, from: idOf(other), sent_at: iso(new Date(Date.now() + 4_000)) });
      current.read_up_to[me] = sent.uuid;
      persist();
      return done({ data: toMessage(sent, me) });
    }
    const all = visible(world, current.uuid);
    if (query.after) {
      const index = all.findIndex((message) => message.uuid === query.after);
      return done({ data: all.slice(index + 1, index + 101).map((message) => toMessage(message, me)), meta: { has_more: false } });
    }
    return done(paginate([...all].reverse().map((message) => toMessage(message, me)), query));
  }

  return undefined;
}

/** Demande acceptée entre un prestataire et un client (comptes de test), pour le devis d'exemple. */
export function demoAcceptedRequestBetween(providerEmail: string, clientEmail: string): string | null {
  const item = load().requests.find(
    (entry) =>
      entry.status === 'accepted' &&
      [idOf(entry.author), idOf(entry.recipient)].includes(providerEmail) &&
      [idOf(entry.author), idOf(entry.recipient)].includes(clientEmail),
  );
  return item?.uuid ?? null;
}
