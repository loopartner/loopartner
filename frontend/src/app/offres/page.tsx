import type { Metadata } from 'next';
import { Highlight, PageShell } from '@/components/PageShell';
import { OfferCard } from '@/components/OfferCard';
import { Pagination } from '@/components/Pagination';
import { apiOrNull } from '@/lib/api';
import { PRICING_LABELS } from '@/lib/offers';
import type { Category, Paginated, PricingMode, PublicOffer } from '@/lib/types';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: 'Offres des prestataires' };

const MODES = Object.keys(PRICING_LABELS) as PricingMode[];

/** Catalogue public des offres publiées (GET /offers), du plus récent au plus ancien, le même ordre pour tous. */
export default async function OffersPage({ searchParams }: { searchParams: Promise<{ page?: string; categorie?: string; q?: string; tarif?: string }> }) {
  const { page: rawPage, categorie, q, tarif } = await searchParams;
  const page = Math.max(1, Number.parseInt(rawPage ?? '1', 10) || 1);
  const query = q && q.trim().length >= 2 ? q.trim().slice(0, 100) : undefined;
  const mode = MODES.includes(tarif as PricingMode) ? tarif : undefined;

  const [offers, categories] = await Promise.all([
    apiOrNull<Paginated<PublicOffer>>('offers', { query: { page, per_page: 12, category: categorie, q: query, pricing_mode: mode } }),
    apiOrNull<{ data: Category[] }>('categories', { revalidate: 600 }),
  ]);

  const href = (target: number) =>
    `/offres?${new URLSearchParams({ ...(categorie ? { categorie } : {}), ...(query ? { q: query } : {}), ...(mode ? { tarif: mode } : {}), page: String(target) })}`;

  return (
    <PageShell
      title={
        <>
          Les <Highlight>offres</Highlight>
        </>
      }
      subtitle="Des prestations prêtes à commander, proposées par des professionnels déclarés."
    >
      <form className="toolbar" style={{ marginBottom: 24 }} role="search">
        <div className="search">
          <label htmlFor="offer-q" className="visually-hidden">
            Rechercher une offre
          </label>
          <input id="offer-q" name="q" className="input" placeholder="Rechercher une offre" defaultValue={query} minLength={2} maxLength={100} />
        </div>
        <label htmlFor="offer-category" className="visually-hidden">
          Catégorie
        </label>
        <select id="offer-category" name="categorie" className="select select--pill" defaultValue={categorie ?? ''}>
          <option value="">Toutes les catégories</option>
          {(categories?.data ?? []).map((category) => (
            <option key={category.slug} value={category.slug}>
              {category.name}
            </option>
          ))}
        </select>
        <label htmlFor="offer-mode" className="visually-hidden">
          Type de tarif
        </label>
        <select id="offer-mode" name="tarif" className="select select--pill" defaultValue={mode ?? ''}>
          <option value="">Tous les tarifs</option>
          {MODES.map((value) => (
            <option key={value} value={value}>
              {PRICING_LABELS[value]}
            </option>
          ))}
        </select>
        <button type="submit" className="btn btn--dark">
          Rechercher
        </button>
      </form>

      {offers === null ? (
        <div className="card empty">Les offres ne peuvent pas être chargées pour le moment.</div>
      ) : offers.data.length === 0 ? (
        <div className="card empty">Aucune offre ne correspond à votre recherche.</div>
      ) : (
        <div className="card-grid card-grid--3">
          {offers.data.map((offer) => (
            <OfferCard key={offer.slug} offer={offer} />
          ))}
        </div>
      )}
      {offers ? <Pagination current={offers.meta.current_page} last={offers.meta.last_page} href={href} /> : null}
      <p className="disclaimer" style={{ marginTop: 32 }}>
        Prix indicatifs TTC saisis par les prestataires ; le prix définitif figure dans le devis. Résultats classés du plus récent au plus ancien, sans
        mise en avant payante.
      </p>
    </PageShell>
  );
}
