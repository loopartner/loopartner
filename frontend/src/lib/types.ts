/** Types des réponses de l'API, limités aux champs utilisés par le frontend. */

export interface Paginated<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface Category {
  slug: string;
  name: string;
  children?: Category[];
}

export type Role = 'particulier' | 'prestataire' | 'entrepreneur' | 'redacteur' | 'admin';

export interface Me {
  name: string;
  email: string;
  roles: Role[];
  locale: string | null;
  timezone: string | null;
  country: string | null;
  avatar_url: string | null;
  email_verified: boolean;
  two_factor_enabled: boolean;
  two_factor_required: boolean;
  premium: { active: boolean; source: string | null; starts_at: string | null; ends_at: string | null } | null;
  article_quota: { premium: boolean; limit: number | null; used: number; remaining: number | null } | null;
}

export interface PublicProject {
  uuid: string;
  title: string;
  /** Publique depuis la décision 80 ; absente des réponses d'une API plus ancienne. */
  description?: string;
  category: Category | null;
  budget_min: number | null;
  budget_max: number | null;
  currency: string;
  country: string;
  remote_ok: boolean;
  published_at: string | null;
}

/** Projet vu par un prestataire autorisé (GET /projects/{uuid}). */
export interface BrowsableProject extends PublicProject {
  description: string;
  city: string | null;
  desired_start_date: string | null;
  expires_at: string | null;
  owner: { account_type: 'particulier' | 'entrepreneur' | null; country: string | null };
}

export interface OwnProject extends BrowsableProject {
  status: 'draft' | 'published' | 'closed';
}

export interface ProviderSummary {
  slug: string;
  display_name: string;
  headline: string | null;
  city: string | null;
  country: string | null;
  logo_url: string | null;
  /** Présente dans l'annuaire (GET /providers), décision 82. */
  banner_url?: string | null;
  categories?: Category[];
}

export interface Segment {
  text: string;
  marks: Array<'bold' | 'italic'>;
  link: { href: string; rel: string } | null;
}

export type ArticleBlock =
  | { type: 'paragraph'; content: Segment[] }
  | { type: 'heading'; level: 2 | 3; content: Segment[] }
  | { type: 'list'; style: 'bullet' | 'ordered'; items: Segment[][] }
  | { type: 'quote'; content: Segment[]; attribution?: string | null }
  | { type: 'image'; url: string; width: number; height: number; alt: string | null; caption?: string | null }
  | { type: 'divider' };

export interface ArticleAuthor {
  kind: 'editorial' | 'provider' | 'entrepreneur';
  name: string | null;
  slug: string | null;
  headline: string | null;
  logo_url: string | null;
}

export interface ArticleSummary {
  slug: string;
  title: string;
  excerpt: string;
  language: string;
  cover: { url: string; width: number; height: number } | null;
  categories: Category[];
  author: ArticleAuthor;
  reading_time_minutes: number;
  published_at: string;
}

export interface ArticleDetail extends ArticleSummary {
  body: { version: number; blocks: ArticleBlock[] };
  seo: { title: string; description: string };
  related: ArticleSummary[];
}

export interface EventLocalTime {
  date: string;
  time: string;
  [key: string]: unknown;
}

export interface PublicEvent {
  slug: string;
  status: 'published' | 'past';
  title: string;
  description: string;
  type: string;
  format: 'in_person' | 'online' | 'hybrid';
  language: string;
  categories: Category[];
  starts_at: string;
  ends_at: string;
  timezone: string;
  venue: { name: string | null; address_line: string | null; postal_code: string | null; city: string | null; country: string | null } | null;
  external_url: string | null;
  is_free: boolean;
  ticket_url: string | null;
  capacity: number | null;
  cover_url: string | null;
  organizer: { kind: 'provider' | 'named'; name: string | null; slug: string | null; headline: string | null; logo_url: string | null };
  registration: { mode: 'internal' | 'external'; is_open: boolean; is_full: boolean; spots_left: number | null };
}

export interface OwnEvent {
  uuid: string;
  slug: string | null;
  status: 'draft' | 'pending_review' | 'published' | 'rejected' | 'cancelled' | 'past';
  title: string;
  starts_at: string;
  ends_at: string;
  timezone: string;
  format: string;
  registrations_count?: number;
  rejection_reason?: string | null;
  [key: string]: unknown;
}

export interface LegalDocumentSummary {
  type: 'terms' | 'privacy';
  version: string;
  title: string;
  effective_at: string;
}

/* ---------------------------------------------------------------- Mise en relation et messagerie */

export type MatchRequestStatus = 'pending' | 'accepted' | 'declined' | 'withdrawn' | 'expired';

/** Interlocuteur : prestataire (résumé public) ou porteur de projet (nom après acceptation). */
export interface Counterpart {
  deleted: boolean;
  account_type: 'particulier' | 'prestataire' | 'entrepreneur' | null;
  country: string | null;
  name: string | null;
  provider: { display_name: string; slug: string | null; headline: string | null; city: string | null; country: string | null; logo_url: string | null } | null;
}

export interface MatchRequest {
  uuid: string;
  type: 'proposal' | 'contact';
  status: MatchRequestStatus;
  role: 'author' | 'recipient';
  message: string | null;
  price: { amount: number; currency: string } | null;
  project: {
    uuid: string;
    title: string;
    description: string;
    category: Category | null;
    budget_min: number | null;
    budget_max: number | null;
    currency: string;
    country: string;
    city: string | null;
    remote_ok: boolean;
  } | null;
  counterpart: Counterpart;
  conversation: { uuid: string } | null;
  created_at: string;
  expires_at: string | null;
  responded_at: string | null;
}

export interface Conversation {
  uuid: string;
  match_request: { uuid: string; type: 'proposal' | 'contact' } | null;
  project: { uuid: string; title: string } | null;
  counterpart: Counterpart;
  last_message: { uuid: string; excerpt: string | null; mine: boolean; sent_at: string } | null;
  unread_count: number;
  blocked_by_me: boolean;
  can_send: boolean;
  created_at: string;
  last_activity_at: string | null;
}

export interface ChatMessage {
  uuid: string;
  body: string | null;
  mine: boolean;
  sent_at: string;
}

export interface ProviderProfile {
  display_name: string;
  slug: string;
  headline: string | null;
  bio: string | null;
  city: string | null;
  country: string | null;
  website: string | null;
  logo_url: string | null;
  /** Bannière du profil public (décision 82). */
  banner_url: string | null;
  categories: Category[];
  published_at: string | null;
}

/* ---------------------------------------------------------------- Signalement (DSA) */

export interface ModerationInfo {
  contact: { users_email: string | null; authorities_email: string | null; languages: string[] };
  target_types: Array<{ key: string; label: string }>;
  categories: Array<{ key: string; label: string; priority: 'urgent' | 'standard'; identity_optional: boolean }>;
  appeal_window_months: number;
  explanation: { min: number; max: number };
}

/* ---------------------------------------------------------------- Prestataires et offres */

export interface OwnProviderProfile extends ProviderProfile {
  status: 'draft' | 'published';
  moderation: { restricted: boolean; decision: string | null } | null;
  created_at: string;
  updated_at: string;
}

export type PricingMode = 'fixed' | 'from' | 'hourly' | 'daily' | 'on_quote';
export type DeliveryMode = 'on_site' | 'remote' | 'both';

export interface SellerCard {
  declared_status: 'professional';
  legal_name: string;
  trade_name: string | null;
  registration_number: string;
  country: string;
}

export interface PublicOffer {
  slug: string;
  title: string;
  summary: string;
  description: string;
  category: Category | null;
  pricing: { mode: PricingMode; price_cents: number | null; currency: string; tax_basis: 'ttc'; indicative: true };
  delivery_mode: DeliveryMode;
  country: string;
  city: string | null;
  /** Image de couverture facultative (décision 83). */
  image_url: string | null;
  provider: { display_name: string; slug: string | null; headline: string | null; city: string | null; country: string | null; logo_url: string | null } | null;
  seller: SellerCard | null;
  published_at: string | null;
  updated_at: string;
}

export interface OwnOffer {
  uuid: string;
  slug: string | null;
  title: string;
  summary: string;
  description: string;
  category: Category | null;
  pricing_mode: PricingMode;
  price_cents: number | null;
  currency: string;
  delivery_mode: DeliveryMode;
  country: string;
  city: string | null;
  image_url: string | null;
  status: 'draft' | 'published' | 'unpublished';
  version: number;
  moderation: { restricted: boolean; decision: string | null } | null;
  published_at: string | null;
  unpublished_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface SellerProfile {
  legal_name: string;
  trade_name: string | null;
  legal_form: string;
  registration_number: string;
  registry: 'rcs' | 'rne';
  registry_city: string | null;
  share_capital_cents: number | null;
  vat_number: string | null;
  address_line1: string;
  address_line2: string | null;
  postal_code: string;
  city: string;
  country: string;
  phone: string;
  email: string;
  insurer_name: string | null;
  insurer_contact: string | null;
  insurance_coverage: string | null;
  insurance_ten_year: boolean;
  mediator_name: string | null;
  mediator_url: string | null;
  regulated_profession_title: string | null;
  regulated_profession_state: string | null;
  regulated_profession_body: string | null;
  terms_url: string | null;
  quote_footer: string | null;
  self_certification: { accepted_at: string | null; version: string | null; current_version: string };
  registration_verification: { status: string | null; verified_at: string | null };
  missing_for_offers: string[];
  missing_for_quotes: string[];
  missing_for_consumer_quotes: string[];
  updated_at: string;
}
