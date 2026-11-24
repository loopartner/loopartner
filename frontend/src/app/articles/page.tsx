import type { Metadata } from 'next';
import Link from 'next/link';
import { ArticleCard, FeaturedArticle } from '@/components/ArticleCard';
import { Highlight, PageShell } from '@/components/PageShell';
import { Pagination } from '@/components/Pagination';
import { apiOrNull } from '@/lib/api';
import type { ArticleSummary, Category, Paginated } from '@/lib/types';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: 'Nos articles' };

export default async function ArticlesPage({ searchParams }: { searchParams: Promise<{ page?: string; categorie?: string }> }) {
  const { page: rawPage, categorie } = await searchParams;
  const page = Math.max(1, Number.parseInt(rawPage ?? '1', 10) || 1);

  const [articles, categories] = await Promise.all([
    apiOrNull<Paginated<ArticleSummary>>('articles', { query: { page, per_page: 8, category: categorie } }),
    apiOrNull<{ data: Category[] }>('categories', { revalidate: 600 }),
  ]);

  const list = articles?.data ?? [];
  const featured = page === 1 ? list.slice(0, 2) : [];
  const rest = page === 1 ? list.slice(2) : list;
  const href = (target: number) => `/articles?${new URLSearchParams({ ...(categorie ? { categorie } : {}), page: String(target) })}`;

  return (
    <PageShell
      title={
        <>
          Nos <Highlight>articles</Highlight>
        </>
      }
      subtitle="Conseils et guides pour créer et gérer votre entreprise"
    >
      <nav className="filter-pills" aria-label="Catégories">
        <Link href="/articles" aria-current={!categorie ? 'page' : undefined}>
          Tous
        </Link>
        {(categories?.data ?? []).map((category) => (
          <Link key={category.slug} href={`/articles?categorie=${category.slug}`} aria-current={categorie === category.slug ? 'page' : undefined}>
            {category.name}
          </Link>
        ))}
      </nav>

      {articles === null ? (
        <div className="card empty">Les articles ne peuvent pas être chargés pour le moment.</div>
      ) : list.length === 0 ? (
        <div className="card empty">Aucun article publié dans cette catégorie pour le moment.</div>
      ) : (
        <>
          {featured.length > 0 ? (
            <section aria-labelledby="featured-title" style={{ marginBottom: 64 }}>
              <h2 id="featured-title" className="section-title section-title--bullet" style={{ fontSize: 22 }}>
                À la une
              </h2>
              <div className="card-grid card-grid--2">
                {featured.map((article) => (
                  <FeaturedArticle key={article.slug} article={article} />
                ))}
              </div>
            </section>
          ) : null}
          {rest.length > 0 ? (
            <section aria-labelledby="all-title">
              <h2 id="all-title" className="section-title section-title--bullet" style={{ fontSize: 22 }}>
                Tous les articles
              </h2>
              <div className="card-grid card-grid--3">
                {rest.map((article) => (
                  <ArticleCard key={article.slug} article={article} />
                ))}
              </div>
            </section>
          ) : null}
          <Pagination current={articles.meta.current_page} last={articles.meta.last_page} href={href} />
        </>
      )}
    </PageShell>
  );
}
