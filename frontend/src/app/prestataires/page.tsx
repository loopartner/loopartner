import type { Metadata } from 'next';
import Link from 'next/link';
import { Icon } from '@/components/Icon';
import { Highlight, PageShell } from '@/components/PageShell';
import { Pagination } from '@/components/Pagination';
import { apiOrNull } from '@/lib/api';
import { countryName, initials } from '@/lib/format';
import type { Category, Paginated, ProviderSummary } from '@/lib/types';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: 'Prestataires' };

/** Annuaire public des prestataires au profil publié (GET /providers). Pas de maquette : même style. */
export default async function ProvidersPage({ searchParams }: { searchParams: Promise<{ page?: string; categorie?: string; q?: string }> }) {
  const { page: rawPage, categorie, q } = await searchParams;
  const page = Math.max(1, Number.parseInt(rawPage ?? '1', 10) || 1);
  const query = q && q.trim().length >= 2 ? q.trim().slice(0, 100) : undefined;

  const [providers, categories] = await Promise.all([
    apiOrNull<Paginated<ProviderSummary>>('providers', { query: { page, per_page: 12, category: categorie, q: query } }),
    apiOrNull<{ data: Category[] }>('categories', { revalidate: 600 }),
  ]);

  const href = (target: number) =>
    `/prestataires?${new URLSearchParams({ ...(categorie ? { categorie } : {}), ...(query ? { q: query } : {}), page: String(target) })}`;

  return (
    <PageShell
      title={
        <>
          Nos <Highlight>prestataires</Highlight>
        </>
      }
      subtitle="Des professionnels indépendants pour vous accompagner dans votre projet."
    >
      <form className="toolbar" style={{ marginBottom: 24 }} role="search">
        <div className="search">
          <label htmlFor="provider-q" className="visually-hidden">
            Rechercher un prestataire
          </label>
          <input id="provider-q" name="q" className="input" placeholder="Rechercher un prestataire" defaultValue={query} minLength={2} maxLength={100} />
        </div>
        <label htmlFor="provider-category" className="visually-hidden">
          Catégorie
        </label>
        <select id="provider-category" name="categorie" className="select select--pill" defaultValue={categorie ?? ''}>
          <option value="">Toutes les catégories</option>
          {(categories?.data ?? []).map((category) => (
            <option key={category.slug} value={category.slug}>
              {category.name}
            </option>
          ))}
        </select>
        <button type="submit" className="btn btn--dark">
          Rechercher
        </button>
      </form>

      {providers === null ? (
        <div className="card empty">L&apos;annuaire ne peut pas être chargé pour le moment.</div>
      ) : providers.data.length === 0 ? (
        <div className="card empty">Aucun prestataire ne correspond à votre recherche.</div>
      ) : (
        <div className="card-grid card-grid--3">
          {providers.data.map((provider) => (
            <article key={provider.slug} className="card" style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              <div className="author">
                {provider.logo_url ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={provider.logo_url} alt="" className="avatar avatar--lg" />
                ) : (
                  <span className="avatar avatar--lg">{initials(provider.display_name)}</span>
                )}
                <div>
                  <h2 style={{ margin: 0, fontSize: 18 }}>
                    <Link href={`/prestataires/${provider.slug}`}>{provider.display_name}</Link>
                  </h2>
                  <small style={{ color: 'var(--muted)' }}>{[provider.city, countryName(provider.country)].filter(Boolean).join(', ')}</small>
                </div>
              </div>
              {provider.headline ? <p style={{ margin: 0, fontFamily: 'var(--font-text)', fontSize: 14, color: 'var(--muted)' }}>{provider.headline}</p> : null}
              <div className="project-card__pills">
                {(provider.categories ?? []).slice(0, 3).map((category) => (
                  <span key={category.slug} className="pill pill--tag">
                    {category.name}
                  </span>
                ))}
              </div>
              <div style={{ marginTop: 'auto', display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
                <Link href={`/prestataires/${provider.slug}`} className="link-arrow">
                  Voir le profil <Icon name="arrowRight" size={16} />
                </Link>
                <Link href={`/prestataires/${provider.slug}/contacter`} className="link-arrow">
                  Contacter
                </Link>
              </div>
            </article>
          ))}
        </div>
      )}
      {providers ? <Pagination current={providers.meta.current_page} last={providers.meta.last_page} href={href} /> : null}
      <p className="disclaimer" style={{ marginTop: 32 }}>
        Résultats classés du plus récent au plus ancien. Aucun prestataire ne paie pour apparaître en premier.{' '}
        <Link href="/inscription?profil=prestataire">Vous êtes prestataire ? Inscription gratuite</Link>
      </p>
    </PageShell>
  );
}
