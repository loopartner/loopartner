import Link from 'next/link';
import { formatDate, initials } from '@/lib/format';
import type { ArticleSummary } from '@/lib/types';
import { Icon } from './Icon';

function AuthorBadge({ article }: { article: ArticleSummary }) {
  if (article.author.kind === 'editorial') return <span className="pill pill--editorial">Article Loopartner</span>;
  return article.author.name ? <span className="pill pill--category">Par {article.author.name}</span> : null;
}

export function ArticleCard({ article }: { article: ArticleSummary }) {
  return (
    <article className="card article-card">
      {article.cover ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={article.cover.url} alt="" className="article-card__cover" loading="lazy" />
      ) : (
        <div className="article-card__cover" aria-hidden="true" />
      )}
      <div className="article-card__body">
        <div className="project-card__pills">
          {article.categories[0] ? <span className="pill pill--category">{article.categories[0].name}</span> : null}
          <AuthorBadge article={article} />
        </div>
        <h3 className="article-card__title">
          <Link href={`/articles/${article.slug}`}>{article.title}</Link>
        </h3>
        <p className="article-card__excerpt">{article.excerpt}</p>
        <div className="article-card__footer">
          <span className="author">
            {article.author.logo_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={article.author.logo_url} alt="" className="avatar" />
            ) : (
              <span className="avatar">{initials(article.author.name)}</span>
            )}
            <span>
              <strong>{article.author.name}</strong>
              <br />
              <span style={{ color: 'var(--muted)' }}>
                {formatDate(article.published_at, { day: 'numeric', month: 'short', year: 'numeric' })} · {article.reading_time_minutes} min
              </span>
            </span>
          </span>
          <Link href={`/articles/${article.slug}`} className="link-arrow" aria-label={`Lire « ${article.title} »`}>
            Lire <Icon name="arrowRight" size={14} />
          </Link>
        </div>
      </div>
    </article>
  );
}

export function FeaturedArticle({ article }: { article: ArticleSummary }) {
  return (
    <article className="card featured">
      {article.cover ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={article.cover.url} alt="" className="featured__cover" />
      ) : (
        <div className="featured__cover" aria-hidden="true" />
      )}
      <div>
        <h3 style={{ fontFamily: 'var(--font-text)', fontSize: 22, margin: '0 0 12px', lineHeight: 1.3 }}>
          <Link href={`/articles/${article.slug}`}>{article.title}</Link>
        </h3>
        <p className="article-card__excerpt" style={{ marginBottom: 16 }}>
          {article.excerpt}
        </p>
        <Link href={`/articles/${article.slug}`} className="link-arrow">
          Lire l&apos;article <Icon name="arrowRight" size={14} />
        </Link>
      </div>
    </article>
  );
}
