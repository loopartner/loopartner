/**
 * Mode démonstration : demandes de mise en relation, conversations, messages et signalements
 * fictifs, gardés dans le navigateur (localStorage) pour chaque compte de test.
 */

import type { ChatMessage, Conversation, Counterpart, MatchRequest, ModerationInfo, Paginated } from './types';

export type DemoFail = (status: number, body: Record<string, unknown>) => never;

interface StoredConversation {
  uuid: string;
  request: string;
  read_up_to: string | null;
  blocked_by_me: boolean;
}

interface StoredMessage {
  uuid: string;
  conversation: string;
  body: string;
  mine: boolean;
  sent_at: string;
}

interface MatchingState {
  requests: MatchRequest[];
  conversations: StoredConversation[];
  messages: StoredMessage[];
}

const iso = (date: Date) => date.toISOString().replace(/\.\d{3}Z$/, 'Z');
const minutesAgo = (minutes: number) => iso(new Date(Date.now() - minutes * 60_000));

const PROVIDER_CARDS: Record<string, Counterpart['provider']> = {
  morel: { display_name: 'Cabinet Morel Conseil', slug: 'cabinet-morel-conseil', headline: 'Juristes en droit des sociétés', city: 'Lyon', country: 'FR', logo_url: null },
  claire: { display_name: 'Compta Claire', slug: 'compta-claire', headline: 'Expertise comptable pour indépendants', city: 'Nantes', country: 'FR', logo_url: null },
  marques: { display_name: 'Marques & Co', slug: 'marques-et-co', headline: 'Conseil en propriété industrielle', city: 'Paris', country: 'FR', logo_url: null },
  domicile: { display_name: 'Domicile Pro', slug: 'domicile-pro', headline: "Domiciliation d'entreprises", city: 'Bordeaux', country: 'FR', logo_url: null },
};

const provider = (key: keyof typeof PROVIDER_CARDS): Counterpart => ({ deleted: false, account_type: 'prestataire', country: null, name: null, provider: PROVIDER_CARDS[key] });
const client = (type: 'particulier' | 'entrepreneur', name: string | null): Counterpart => ({ deleted: false, account_type: type, country: 'FR', name, provider: null });

function project(title: string, slug: string, label: string, min: number | null, max: number | null) {
  return {
    uuid: crypto.randomUUID(),
    title,
    description: 'Projet de démonstration.',
    category: { slug, name: label },
    budget_min: min,
    budget_max: max,
    currency: 'EUR',
    country: 'FR',
    city: null,
    remote_ok: true,
  };
}

function request(partial: Partial<MatchRequest> & Pick<MatchRequest, 'type' | 'status' | 'role' | 'message' | 'counterpart'>, ageMinutes: number): MatchRequest {
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

function seed(isProvider: boolean): MatchingState {
  const state: MatchingState = { requests: [], conversations: [], messages: [] };
  const withConversation = (item: MatchRequest, lines: Array<[boolean, string, number]>, unread: number) => {
    const conversation: StoredConversation = { uuid: crypto.randomUUID(), request: item.uuid, read_up_to: null, blocked_by_me: false };
    item.conversation = { uuid: conversation.uuid };
    const stored = lines.map(([mine, body, age]) => ({ uuid: crypto.randomUUID(), conversation: conversation.uuid, body, mine, sent_at: minutesAgo(age) }));
    conversation.read_up_to = stored[stored.length - 1 - unread]?.uuid ?? null;
    state.conversations.push(conversation);
    state.messages.push(...stored);
  };

  if (!isProvider) {
    const sas = project("Création d'une SAS pour une application mobile", 'creation-societe', 'Création de société', 500, 1000);
    state.requests.push(
      request(
        {
          type: 'proposal',
          status: 'pending',
          role: 'recipient',
          project: sas,
          price: { amount: 850, currency: 'EUR' },
          counterpart: provider('morel'),
          message:
            "Bonjour, nous accompagnons régulièrement des start-up dans la création de leur SAS. Nous pouvons rédiger des statuts adaptés à l'entrée d'investisseurs, préparer le pacte d'associés et gérer l'immatriculation sous trois semaines.",
        },
        90,
      ),
    );
    const accepted = request(
      {
        type: 'proposal',
        status: 'accepted',
        role: 'recipient',
        project: sas,
        price: { amount: 700, currency: 'EUR' },
        counterpart: provider('claire'),
        message: "Bonjour, je peux m'occuper de la partie comptable du lancement : choix du régime, prévisionnel et mise en place de la tenue des comptes.",
      },
      60 * 26,
    );
    withConversation(
      accepted,
      [
        [false, "Bonjour Camille, merci d'avoir accepté ma proposition. Avez-vous déjà une idée du chiffre d'affaires de la première année ?", 60 * 25],
        [true, "Bonjour ! Nous visons environ 60 000 € la première année, avec un lancement de l'application en mars.", 60 * 24],
        [false, 'Parfait. Je vous prépare un prévisionnel et une comparaison des régimes. Seriez-vous disponible jeudi pour en parler ?', 50],
        [false, 'Je peux aussi vous envoyer un devis détaillé si vous le souhaitez.', 45],
      ],
      2,
    );
    state.requests.push(accepted);
    state.requests.push(
      request(
        {
          type: 'proposal',
          status: 'declined',
          role: 'recipient',
          project: sas,
          counterpart: provider('marques'),
          message: "Bonjour, nous proposons la recherche d'antériorité et le dépôt du nom de votre application auprès de l'INPI.",
        },
        60 * 72,
      ),
    );
    state.requests.push(
      request(
        {
          type: 'contact',
          status: 'pending',
          role: 'author',
          counterpart: provider('domicile'),
          message: "Bonjour, je cherche une domiciliation à Lyon pour ma future SAS, avec réception et scan du courrier. Quels sont vos délais d'ouverture ?",
        },
        60 * 5,
      ),
    );
  } else {
    state.requests.push(
      request(
        {
          type: 'contact',
          status: 'pending',
          role: 'recipient',
          counterpart: client('particulier', null),
          message:
            "Bonjour, je souhaite créer une SASU de conseil d'ici deux mois. Pouvez-vous m'aider pour les statuts et les formalités ? Quel serait votre délai ?",
        },
        35,
      ),
    );
    const accepted = request(
      {
        type: 'contact',
        status: 'accepted',
        role: 'recipient',
        counterpart: client('entrepreneur', 'Lucas Bernard'),
        message: "Bonjour, nous sommes trois associés et voulons rédiger un pacte d'associés avant une levée de fonds. Est-ce dans vos compétences ?",
      },
      60 * 30,
    );
    withConversation(
      accepted,
      [
        [true, "Bonjour Lucas, oui tout à fait. Pouvez-vous me dire si la SAS est déjà immatriculée et combien d'investisseurs sont prévus ?", 60 * 29],
        [false, 'Oui, immatriculée depuis juin. Un seul fonds pour le moment, peut-être deux business angels.', 60 * 3],
        [false, 'Pouvez-vous nous envoyer un devis ?', 20],
      ],
      1,
    );
    state.requests.push(accepted);
    state.requests.push(
      request(
        {
          type: 'proposal',
          status: 'pending',
          role: 'author',
          project: project('Audit de conformité RGPD pour une boutique e-commerce', 'juridique', 'Juridique', 1000, 2500),
          price: { amount: 1800, currency: 'EUR' },
          counterpart: client('particulier', null),
          message: 'Bonjour, nous réalisons des audits RGPD complets pour les boutiques Shopify, avec la mise à jour des documents et du bandeau cookies.',
        },
        60 * 8,
      ),
    );
    state.requests.push(
      request(
        {
          type: 'proposal',
          status: 'expired',
          role: 'author',
          project: project('Rédaction de CGV pour une marketplace', 'juridique', 'Juridique', 500, 1000),
          counterpart: client('particulier', null),
          message: 'Bonjour, nous pouvons rédiger vos CGV et CGU sous dix jours ouvrés.',
        },
        60 * 24 * 35,
      ),
    );
  }

  return state;
}

/* ---------------------------------------------------------------- Stockage */

function key(email: string) {
  return `loopartner:demo-matching:${email}`;
}

function load(email: string, isProvider: boolean): MatchingState {
  try {
    const raw = window.localStorage.getItem(key(email));
    if (raw) return JSON.parse(raw) as MatchingState;
  } catch {
    // Stockage indisponible : données recréées.
  }
  const state = seed(isProvider);
  save(email, state);
  return state;
}

function save(email: string, state: MatchingState) {
  try {
    window.localStorage.setItem(key(email), JSON.stringify(state));
  } catch {
    // Stockage indisponible : la démonstration continue sans mémoire.
  }
}

/* ---------------------------------------------------------------- Lecture */

const visible = (state: MatchingState, conversation: string) =>
  state.messages.filter((message) => message.conversation === conversation && new Date(message.sent_at).getTime() <= Date.now());

function toMessage(message: StoredMessage): ChatMessage {
  return { uuid: message.uuid, body: message.body, mine: message.mine, sent_at: message.sent_at };
}

function unreadCount(state: MatchingState, conversation: StoredConversation): number {
  const list = visible(state, conversation.uuid);
  const index = conversation.read_up_to ? list.findIndex((message) => message.uuid === conversation.read_up_to) : -1;
  return list.slice(index + 1).filter((message) => !message.mine).length;
}

function toConversation(state: MatchingState, conversation: StoredConversation): Conversation {
  const item = state.requests.find((entry) => entry.uuid === conversation.request) as MatchRequest;
  const last = visible(state, conversation.uuid).at(-1);
  return {
    uuid: conversation.uuid,
    match_request: { uuid: item.uuid, type: item.type },
    project: item.project ? { uuid: item.project.uuid, title: item.project.title } : null,
    counterpart: item.counterpart,
    last_message: last ? { uuid: last.uuid, excerpt: last.body.slice(0, 140), mine: last.mine, sent_at: last.sent_at } : null,
    unread_count: unreadCount(state, conversation),
    blocked_by_me: conversation.blocked_by_me,
    can_send: !conversation.blocked_by_me,
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

const AUTO_REPLY = 'Merci pour votre message ! Je reviens vers vous très vite. (Réponse automatique de démonstration.)';

/**
 * Routes de la mise en relation et du signalement. Renvoie `undefined` si la route n'est pas
 * gérée ici ; `user` est null sans session de démonstration.
 */
export function demoMatching(
  route: string,
  method: string,
  query: Record<string, unknown>,
  input: Record<string, unknown>,
  user: { email: string; isProvider: boolean } | null,
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
  const account = user as { email: string; isProvider: boolean };
  const state = load(account.email, account.isProvider);
  const persist = () => save(account.email, state);

  // Nouvelles demandes
  const contact = /^providers\/([^/]+)\/contact$/.exec(route);
  if (contact) {
    if (account.isProvider) fail(403, { message: 'Seuls les particuliers et les entrepreneurs peuvent contacter un prestataire.' });
    const card = Object.values(PROVIDER_CARDS).find((item) => item?.slug === contact[1]) ?? {
      display_name: contact[1].replaceAll('-', ' '),
      slug: contact[1],
      headline: null,
      city: null,
      country: 'FR',
      logo_url: null,
    };
    if (state.requests.some((item) => item.role === 'author' && item.counterpart.provider?.slug === contact[1] && ['pending', 'accepted'].includes(item.status))) {
      fail(409, { message: 'Vous avez déjà une demande en cours avec ce prestataire.' });
    }
    const message = String(input.message ?? '');
    if (message.length < 20) fail(422, { message: 'Message trop court.', errors: { message: ['Le message doit contenir au moins 20 caractères.'] } });
    const created = request({ type: 'contact', status: 'pending', role: 'author', message, counterpart: { deleted: false, account_type: 'prestataire', country: null, name: null, provider: card } }, 0);
    state.requests.unshift(created);
    persist();
    return done({ data: created });
  }
  if (/^projects\/[^/]+\/proposals$/.test(route)) {
    const message = String(input.message ?? '');
    const created = request(
      {
        type: 'proposal',
        status: 'pending',
        role: 'author',
        message,
        price: input.price_amount ? { amount: Number(input.price_amount), currency: String(input.price_currency ?? 'EUR') } : null,
        project: project('Projet de démonstration', 'juridique', 'Juridique', null, null),
        counterpart: client('particulier', null),
      },
      0,
    );
    state.requests.unshift(created);
    persist();
    return done({ data: created });
  }

  // Demandes
  if (route === 'me/match-requests') {
    const box = query.box === 'sent' ? 'author' : 'recipient';
    const list = state.requests
      .filter((item) => item.role === box && (!query.status || item.status === query.status))
      .sort((a, b) => b.created_at.localeCompare(a.created_at));
    return done(paginate(list, query));
  }
  const single = /^me\/match-requests\/([^/]+)$/.exec(route);
  if (single) {
    const item = state.requests.find((entry) => entry.uuid === single[1]);
    if (!item) fail(404, { message: 'Demande introuvable.' });
    return done({ data: item });
  }
  const action = /^me\/match-requests\/([^/]+)\/(accept|decline|withdraw|block|report)$/.exec(route);
  if (action) {
    const item = state.requests.find((entry) => entry.uuid === action[1]);
    if (!item) fail(404, { message: 'Demande introuvable.' });
    const target = item as MatchRequest;
    if (action[2] === 'report') return done({ data: { uuid: crypto.randomUUID() } });
    if (target.status !== 'pending' && action[2] !== 'block') fail(409, { message: "Cette demande n'est plus en attente." });
    if (action[2] === 'accept') {
      target.status = 'accepted';
      target.responded_at = iso(new Date());
      target.expires_at = null;
      if (target.counterpart.account_type !== 'prestataire') target.counterpart = { ...target.counterpart, name: target.counterpart.account_type === 'entrepreneur' ? 'Lucas Bernard' : 'Camille Durand' };
      const conversation: StoredConversation = { uuid: crypto.randomUUID(), request: target.uuid, read_up_to: null, blocked_by_me: false };
      state.conversations.push(conversation);
      target.conversation = { uuid: conversation.uuid };
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
    return done(action[2] === 'block' ? undefined : { data: target });
  }

  // Conversations
  if (route === 'me/conversations/unread') {
    const counts = state.conversations.map((conversation) => unreadCount(state, conversation));
    return done({ data: { conversations: counts.filter((count) => count > 0).length, messages: counts.reduce((sum, count) => sum + count, 0) } });
  }
  if (route === 'me/conversations') {
    const list = state.conversations
      .map((conversation) => toConversation(state, conversation))
      .sort((a, b) => (b.last_activity_at ?? b.created_at).localeCompare(a.last_activity_at ?? a.created_at));
    return done(paginate(list, query));
  }
  const conversationRoute = /^me\/conversations\/([^/]+)(?:\/(messages|read|block))?(?:\/([^/]+)\/report)?$/.exec(route);
  if (conversationRoute) {
    const conversation = state.conversations.find((entry) => entry.uuid === conversationRoute[1]);
    if (!conversation) fail(404, { message: 'Conversation introuvable.' });
    const current = conversation as StoredConversation;
    const part = conversationRoute[2];

    if (conversationRoute[3]) return done({ data: { uuid: crypto.randomUUID() } });
    if (!part) return done({ data: toConversation(state, current) });
    if (part === 'block') {
      current.blocked_by_me = method !== 'DELETE';
      persist();
      return done(undefined);
    }
    if (part === 'read') {
      current.read_up_to = String(input.up_to ?? visible(state, current.uuid).at(-1)?.uuid ?? '') || null;
      persist();
      return done(undefined);
    }
    if (method === 'POST') {
      if (current.blocked_by_me) fail(403, { message: 'Vous avez bloqué cette personne.' });
      const body = String(input.body ?? '').trim();
      if (!body) fail(422, { message: 'Message vide.', errors: { body: ['Écrivez un message.'] } });
      const sent: StoredMessage = { uuid: crypto.randomUUID(), conversation: current.uuid, body, mine: true, sent_at: iso(new Date()) };
      // Réponse simulée quelques secondes plus tard, visible au prochain rafraîchissement.
      const reply: StoredMessage = { uuid: crypto.randomUUID(), conversation: current.uuid, body: AUTO_REPLY, mine: false, sent_at: iso(new Date(Date.now() + 4_000)) };
      state.messages.push(sent, reply);
      current.read_up_to = sent.uuid;
      persist();
      return done({ data: toMessage(sent) });
    }
    const all = visible(state, current.uuid);
    if (query.after) {
      const index = all.findIndex((message) => message.uuid === query.after);
      return done({ data: all.slice(index + 1, index + 101).map(toMessage), meta: { has_more: false } });
    }
    return done(paginate([...all].reverse().map(toMessage), query));
  }

  return undefined;
}
