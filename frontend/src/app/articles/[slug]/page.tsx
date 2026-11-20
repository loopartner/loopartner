import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { ArticleBody } from '@/components/ArticleBody';
import { Icon } from '@/components/Icon';
import { PageShell } from '@/components/PageShell';
import { apiOrNull } from '@/lib/api';
import { dateBadge, duration, eventFormatName, formatDate, initials, languageName } from '@/lib/format';
import type { ArticleDetail, Paginated, PublicEvent } from '@/lib/types';

export const dynamic = 'force-dynamic';

const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

async function load(slug: string): Promise<ArticleDetail | null> {
  if (!SLUG.test(slug)) return null;
  const response = await apiOrNull<{ data: ArticleDetail }>(`articles/${slug}`);
  return response?.data ?? null;
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const article = await load((await params).slug);
  return article ? { title: article.seo.title, description: article.seo.description } : { title: 'Article' };
}

export default async function ArticlePage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const article = await load(slug);
  if (article === null) notFound();

  const nextEvents = await apiOrNull<Paginated<PublicEvent>>('events', { query: { per_page: 1, category: article.categories[0]?.slug } });
  const nextEvent = nextEvents?.data[0] ?? null;

  return (
    <PageShell
      eyebrow={
        <>
          {article.categories[0] ? <span className="pill pill--soft">{article.categories[0].name}</span> : null}
          <span style={{ fontSize: 14, opacity: 0.85 }}>{formatDate(article.published_at)}</span>
        </>
      }
      title={article.title}
    >
      <div className="with-aside with-aside--right">
        <div className="form-stack" style={{ gap: 32 }}>
          {article.cover ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={article.cover.url} alt="" style={{ width: '100%', borderRadius: 32, aspectRatio: '16 / 7.3', objectFit: 'cover' }} />
          ) : null}
          <article className="card" style={{ padding: 48 }}>
            <p style={{ fontFamily: 'var(--font-text)', fontSize: 18, fontWeight: 500, margin: '0 0 24px' }}>{article.excerpt}</p>
            <ArticleBody blocks={article.body.blocks} />
            <p className="disclaimer" style={{ textAlign: 'left', borderTop: '1px solid var(--border)', paddingTop: 24, marginTop: 32 }}>
              Cet article est informatif et ne constitue pas un conseil juridique personnalisé.
            </p>
          </article>
        </div>

        <aside className="aside-stack">
          <div className="card" style={{ textAlign: 'center' }}>
            {article.author.logo_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={article.author.logo_url} alt="" className="avatar avatar--lg" style={{ margin: '0 auto 12px' }} />
            ) : (
              <span className="avatar avatar--lg" style={{ margin: '0 auto 12px' }}>
                {initials(article.author.name)}
              </span>
            )}
            <strong style={{ display: 'block', fontSize: 18 }}>{article.author.name}</strong>
            {article.author.headline ? <span style={{ fontFamily: 'var(--font-text)', fontSize: 13, color: 'var(--muted)' }}>{article.author.headline}</span> : null}
            {article.author.kind === 'editorial' ? (
              <span className="pill pill--editorial" style={{ marginTop: 12 }}>
                Rédaction Loopartner
              </span>
            ) : null}
            {article.author.kind === 'provider' && article.author.slug ? (
              <Link href={`/prestataires?q=${encodeURIComponent(article.author.name ?? '')}`} className="btn btn--outline btn--block" style={{ marginTop: 20 }}>
                Voir le profil
              </Link>
            ) : null}
          </div>

          {nextEvent ? (
            <div className="card" style={{ background: 'var(--lime)', borderColor: 'transparent' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'flex-start' }}>
                <small style={{ fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase', fontSize: 11 }}>Prochain événement</small>
                <span className="date-badge date-badge--sm" style={{ background: '#fff' }}>
                  <strong>{dateBadge(nextEvent.starts_at, nextEvent.timezone).day}</strong>
                  <span>{dateBadge(nextEvent.starts_at, nextEvent.timezone).month}</span>
                </span>
              </div>
              <h3 style={{ margin: '12px 0', fontSize: 20 }}>{nextEvent.title}</h3>
              <ul style={{ listStyle: 'none', padding: 0, margin: '0 0 20px', fontFamily: 'var(--font-text)', fontSize: 13, display: 'grid', gap: 6 }}>
                <li>
                  <Icon name="video" size={14} /> {eventFormatName(nextEvent.format)}
                </li>
                <li>
                  <Icon name="clock" size={14} /> {duration(nextEvent.starts_at, nextEvent.ends_at)}
                </li>
                <li>
                  <Icon name="globe" size={14} /> {languageName(nextEvent.language)}
                </li>
              </ul>
              <Link href={`/evenements/${nextEvent.slug}`} className="btn btn--dark btn--block">
                S&apos;inscrire
              </Link>
            </div>
          ) : null}
        </aside>
      </div>

      {article.related.length > 0 ? (
        <section style={{ marginTop: 80 }} aria-labelledby="related-title">
          <h2 id="related-title" className="section-title section-title--bullet">
            À lire aussi
          </h2>
          <div className="card-grid card-grid--4">
            {article.related.map((item) => (
              <article key={item.slug} className="card article-card">
                {item.cover ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={item.cover.url} alt="" className="article-card__cover" loading="lazy" />
                ) : (
                  <div className="article-card__cover" aria-hidden="true" />
                )}
                <div className="article-card__body">
                  {item.categories[0] ? <small style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', color: 'var(--muted)' }}>{item.categories[0].name}</small> : null}
                  <h3 className="article-card__title" style={{ fontSize: 16 }}>
                    <Link href={`/articles/${item.slug}`}>{item.title}</Link>
                  </h3>
                  <Link href={`/articles/${item.slug}`} className="link-arrow" style={{ fontSize: 13 }}>
                    Lire <Icon name="arrowRight" size={14} />
                  </Link>
                </div>
              </article>
            ))}
          </div>
        </section>
      ) : null}
    </PageShell>
  );
}
