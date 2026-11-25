/**
 * Mode démonstration : devis (`/me/quotes`). Une seule base partagée par les comptes de test du
 * navigateur (`loopartner:demo-quotes-world`) : le devis envoyé par un prestataire apparaît chez
 * son client quand on se connecte avec son compte. Le taux de TVA est FICTIF (comme
 * `MarketplaceDemoSeeder` côté API) et annoncé comme tel.
 */
import { demoAcceptedRequestBetween, demoMatchParties } from './demo-matching';
import { lineTotalHt, previewTotals, type Quote, type QuoteDraftInput, type VatRateOption } from './quotes';

type Fail = (status: number, payload: Record<string, unknown>) => never;
type Done = { value: unknown };

const DEMO_RATES: VatRateOption[] = [
  {
    code: 'demo.vat.rate.standard',
    basis_points: 1000,
    label: '[Démo] Taux fictif',
    scope: 'Taux de démonstration, sans valeur fiscale.',
    exclusive: false,
    requires_certification: false,
  },
];

const NOT_A_PARTY = "Loopartner met en relation les parties et fournit l'outil de devis, mais n'est pas partie au contrat conclu entre le prestataire et le client.";

/** Devis stocké avec ses deux parties (email d'un compte de test, ou null pour un client fictif). */
type StoredQuote = Quote & { provider_email: string; client_email: string | null };

const KEY = 'loopartner:demo-quotes-world';

function load(): StoredQuote[] | null {
  try {
    const raw = window.localStorage.getItem(KEY);
    return raw ? (JSON.parse(raw) as StoredQuote[]) : null;
  } catch {
    return null;
  }
}

function store(quotes: StoredQuote[]) {
  try {
    window.localStorage.setItem(KEY, JSON.stringify(quotes));
  } catch {
    // Stockage indisponible : la démonstration continue en mémoire.
  }
}

const iso = (date: Date) => date.toISOString().replace(/\.\d{3}Z$/, 'Z');

function build(input: QuoteDraftInput, base: Partial<Quote>, role: 'provider' | 'client'): Quote {
  const lines = input.lines.map((line, index) => {
    const rate = DEMO_RATES.find((item) => item.code === line.vat_rate_code) ?? DEMO_RATES[0];
    return {
      position: index + 1,
      line_type: line.line_type,
      designation: line.designation,
      details: line.details,
      quantity_milli: line.quantity_milli,
      unit: line.unit,
      unit_price_cents: line.unit_price_cents,
      vat: { code: rate.code, basis_points: rate.basis_points, label: rate.label },
      total_ht_cents: lineTotalHt(line),
    };
  });
  const totals = previewTotals(input.lines, DEMO_RATES);
  return {
    uuid: crypto.randomUUID(),
    number: null,
    version: 1,
    status: 'draft',
    viewer_role: role,
    client_kind: 'consumer',
    offer: null,
    provider: null,
    issuer: null,
    currency: 'EUR',
    mentions: { vat: [], certifications: [], consumer: { withdrawal_days: 14, withdrawal_ends_at: null }, not_a_party: NOT_A_PARTY },
    content_hash: null,
    decline: null,
    acceptance: null,
    sent_at: null,
    created_at: iso(new Date()),
    updated_at: iso(new Date()),
    ...base,
    title: input.title,
    scope: input.scope,
    client: input.client,
    lines,
    totals: { ht_cents: totals.ht_cents, vat_cents: totals.vat_cents, ttc_cents: totals.ttc_cents, vat_breakdown: totals.vat_breakdown },
    validity: { days: input.validity_days, valid_until: null, expires_at: null },
    start_date: input.start_date,
    duration: input.duration,
    payment_terms: input.payment_terms,
    deposit: input.deposit_cents ? { cents: input.deposit_cents, kind: input.deposit_kind } : null,
    quote_fee: { is_free: input.quote_is_free, cents: input.quote_fee_cents },
    keep_replaced_parts: input.keep_replaced_parts,
    notes: input.notes,
  };
}

function receivedSample(): Quote {
  // Juste après le message « Je vous envoie aussi un devis détaillé » de la conversation de démo.
  const sentAt = new Date(Date.now() - 44 * 60_000);
  const validUntil = new Date(sentAt.getTime() + 30 * 24 * 60 * 60_000);
  const quote = build(
    {
      title: 'Lancement comptable de votre SAS',
      scope:
        "Choix du régime fiscal et social, prévisionnel sur trois ans, mise en place de la tenue des comptes et d'un outil de facturation, avec une séance de formation.",
      client: { name: 'Camille Durand', company_name: null, registration_number: null, address: null, execution_place: 'À distance' },
      validity_days: 30,
      start_date: null,
      duration: 'Trois semaines',
      payment_terms: "50 % à l'acceptation, le solde à la remise du prévisionnel.",
      deposit_cents: null,
      deposit_kind: null,
      quote_is_free: true,
      quote_fee_cents: null,
      keep_replaced_parts: null,
      notes: null,
      lines: [
        { line_type: 'service', designation: 'Prévisionnel sur trois ans', details: null, quantity_milli: 1000, unit: 'lump_sum', unit_price_cents: 45000, vat_rate_code: DEMO_RATES[0].code },
        { line_type: 'service', designation: 'Mise en place de la tenue des comptes', details: 'Plan comptable, banque, outil de facturation.', quantity_milli: 1000, unit: 'lump_sum', unit_price_cents: 20000, vat_rate_code: DEMO_RATES[0].code },
        { line_type: 'labour', designation: 'Formation', details: null, quantity_milli: 2000, unit: 'hour', unit_price_cents: 6000, vat_rate_code: DEMO_RATES[0].code },
      ],
    },
    {
      number: 'D-2026-0012',
      status: 'sent',
      sent_at: iso(sentAt),
      content_hash: 'demo',
      provider: { display_name: 'Compta Claire', slug: 'compta-claire', logo_url: null },
      issuer: { legal_name: 'Compta Claire (démonstration)' },
    },
    'client',
  );
  quote.validity = { days: 30, valid_until: iso(validUntil).slice(0, 10), expires_at: iso(validUntil) };
  return quote;
}

const paginate = (items: Quote[]) => ({ data: items, meta: { current_page: 1, last_page: 1, per_page: 50, total: items.length }, links: {} });

/** Devis vu par `me` : rôle calculé, champs internes de la démo retirés. */
function present(quote: StoredQuote, me: string): Quote {
  const { provider_email: providerEmail, client_email: _client, ...rest } = quote;
  void _client;
  return { ...rest, viewer_role: providerEmail === me ? 'provider' : 'client' };
}

export function demoQuotes(
  route: string,
  method: string,
  query: Record<string, unknown>,
  input: Record<string, unknown>,
  user: { email: string; isProvider: boolean; name: string } | null,
  fail: Fail,
): Done | undefined {
  if (!route.startsWith('me/quotes')) return undefined;
  if (!user) fail(401, { message: 'Unauthenticated.' });
  const session = user as { email: string; isProvider: boolean; name: string };
  const me = session.email;
  let quotes = load();
  if (quotes === null) {
    // Devis d'exemple : Compta Claire → Camille Durand, dans leur conversation.
    const sample: StoredQuote = {
      ...receivedSample(),
      match_request: demoAcceptedRequestBetween('comptable@demo.loopartner.fr', 'client@demo.loopartner.fr') ?? undefined,
      provider_email: 'comptable@demo.loopartner.fr',
      client_email: 'client@demo.loopartner.fr',
    };
    quotes = [sample];
    store(quotes);
  }
  const all = quotes;
  const save = () => store(all);
  const done = (value: unknown): Done => ({ value });
  const isParty = (quote: StoredQuote) => quote.provider_email === me || (quote.client_email === me && quote.status !== 'draft');

  if (route === 'me/quotes/vat-rates') {
    if (!session.isProvider) fail(403, { message: 'Réservé aux prestataires.' });
    return done({ data: DEMO_RATES, meta: { country: 'FR', available: true } });
  }
  if (route === 'me/quotes' && method === 'GET') {
    const sent = query.box === 'sent';
    const matchRequest = typeof query.match_request === 'string' ? query.match_request : null;
    const list = all.filter(
      (quote) => (sent ? quote.provider_email === me : quote.client_email === me && quote.status !== 'draft') && (!matchRequest || quote.match_request === matchRequest),
    );
    return done(paginate(list.map((quote) => present(quote, me))));
  }
  if (route === 'me/quotes' && method === 'POST') {
    if (!session.isProvider) fail(403, { message: 'Seul un prestataire peut rédiger un devis.' });
    const draft = input as unknown as QuoteDraftInput;
    const parties = draft.match_request ? demoMatchParties(draft.match_request) : null;
    if (!parties || parties.provider !== me) fail(404, { message: 'Demande introuvable.' });
    if (all.some((quote) => quote.match_request === draft.match_request && ['draft', 'sent'].includes(quote.status))) {
      fail(409, { message: 'Un devis est déjà en cours pour cette demande : modifiez le brouillon, ou faites une nouvelle version du devis envoyé.' });
    }
    const card = parties?.providerCard ?? null;
    const quote: StoredQuote = {
      ...build(
        draft,
        {
          match_request: draft.match_request,
          provider: card ? { display_name: card.display_name, slug: card.slug, logo_url: card.logo_url } : null,
          issuer: { legal_name: `${session.name} (démonstration)` },
        },
        'provider',
      ),
      provider_email: me,
      client_email: parties?.client ?? null,
    };
    all.unshift(quote);
    save();
    return done({ data: present(quote, me) });
  }

  const match = /^me\/quotes\/([^/]+)(?:\/([a-z]+))?$/.exec(route);
  if (!match) return undefined;
  const index = all.findIndex((quote) => quote.uuid === match[1] && isParty(quote));
  if (index < 0) fail(404, { message: 'Devis introuvable.' });
  const quote = all[index];
  const action = match[2];
  const now = iso(new Date());
  const asProvider = quote.provider_email === me;
  const providerOnly = () => {
    if (!asProvider) fail(403, { message: 'Cette action revient au prestataire.' });
  };
  const clientOnly = () => {
    if (asProvider) fail(403, { message: 'Cette action revient au client.' });
  };
  const reply = (item: StoredQuote) => done({ data: present(item, me) });

  if (!action && method === 'GET') return reply(quote);
  if (!action && method === 'PUT') {
    providerOnly();
    if (quote.status !== 'draft') fail(409, { message: 'Seul un brouillon se modifie.' });
    all[index] = {
      ...build(input as unknown as QuoteDraftInput, { uuid: quote.uuid, match_request: quote.match_request, created_at: quote.created_at, number: quote.number, version: quote.version, provider: quote.provider, issuer: quote.issuer }, 'provider'),
      provider_email: quote.provider_email,
      client_email: quote.client_email,
    };
    save();
    return reply(all[index]);
  }
  if (!action && method === 'DELETE') {
    providerOnly();
    if (quote.status !== 'draft') fail(409, { message: 'Seul un brouillon se supprime.' });
    all.splice(index, 1);
    save();
    return done(undefined);
  }
  if (action === 'pdf') fail(409, { message: 'Le PDF du devis est généré par le serveur : il n’est pas disponible en démonstration.' });
  if (action === 'report') {
    clientOnly();
    return done({ message: 'Signalement enregistré (démonstration).' });
  }
  if (['send', 'withdraw', 'revise'].includes(action ?? '')) providerOnly();
  if (['accept', 'decline', 'withdrawal'].includes(action ?? '')) clientOnly();
  if (action === 'send') {
    if (quote.status !== 'draft') fail(409, { message: 'Ce devis a déjà été envoyé.' });
    const count = all.filter((item) => item.provider_email === me && item.number).length + 1;
    const expires = new Date(Date.now() + quote.validity.days * 24 * 60 * 60_000);
    for (const item of all) {
      if (item !== quote && item.match_request === quote.match_request && item.status === 'sent') item.status = 'superseded';
    }
    Object.assign(quote, {
      status: 'sent',
      number: quote.number ?? `D-${new Date().getFullYear()}-${String(count).padStart(4, '0')}`,
      sent_at: now,
      content_hash: 'demo',
      validity: { ...quote.validity, valid_until: iso(expires).slice(0, 10), expires_at: iso(expires) },
      updated_at: now,
    });
    save();
    return reply(quote);
  }
  if (action === 'withdraw') {
    if (quote.status !== 'sent') fail(409, { message: 'Seul un devis envoyé se retire.' });
    Object.assign(quote, { status: 'withdrawn', updated_at: now });
    save();
    return reply(quote);
  }
  if (action === 'revise') {
    if (!['sent', 'declined', 'expired'].includes(quote.status)) fail(409, { message: 'Ce devis ne peut pas faire l’objet d’une nouvelle version.' });
    if (all.some((item) => item.match_request === quote.match_request && item.status === 'draft')) fail(409, { message: 'Un brouillon existe déjà pour cette demande.' });
    const copy: StoredQuote = { ...JSON.parse(JSON.stringify(quote)), uuid: crypto.randomUUID(), status: 'draft', version: quote.version + 1, sent_at: null, content_hash: null, decline: null, created_at: now, updated_at: now };
    all.unshift(copy);
    save();
    return reply(copy);
  }
  if (action === 'accept') {
    if (quote.status !== 'sent') fail(409, { message: 'Ce devis ne peut plus être accepté.' });
    if (input.accept !== true) fail(422, { message: 'Cochez la case pour accepter le devis.', errors: { accept: ['Cochez la case pour accepter le devis.'] } });
    Object.assign(quote, { status: 'accepted', acceptance: { accepted_at: now, express_execution: Boolean(input.express_execution), certifications: [], withdrawal_exercised_at: null }, updated_at: now });
    if (quote.mentions.consumer) quote.mentions.consumer.withdrawal_ends_at = iso(new Date(Date.now() + 14 * 24 * 60 * 60_000));
    save();
    return reply(quote);
  }
  if (action === 'decline') {
    if (quote.status !== 'sent') fail(409, { message: 'Ce devis ne peut plus être refusé.' });
    Object.assign(quote, { status: 'declined', decline: { reason: input.reason ?? 'other', comment: input.comment ?? null }, updated_at: now });
    save();
    return reply(quote);
  }
  if (action === 'withdrawal') {
    if (quote.status !== 'accepted' || !quote.acceptance) fail(409, { message: 'Aucune rétractation possible pour ce devis.' });
    const acceptance = quote.acceptance as NonNullable<Quote['acceptance']>;
    acceptance.withdrawal_exercised_at = now;
    save();
    return reply(quote);
  }
  return undefined;
}
