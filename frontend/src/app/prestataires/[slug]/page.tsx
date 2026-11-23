import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { ArticleCard } from '@/components/ArticleCard';
import { Icon } from '@/components/Icon';
import { OfferCard } from '@/components/OfferCard';
import { PageShell } from '@/components/PageShell';
import { ProviderCover } from '@/components/ProviderCover';
import { ReportButton } from '@/components/ReportButton';
import { apiOrNull } from '@/lib/api';
import { countryName, formatDate } from '@/lib/format';
import type { ArticleSummary, Paginated, ProviderProfile, PublicOffer } from '@/lib/types';

export const dynamic = 'force-dynamic';

async function load(slug: string) {
  return apiOrNull<{ data: ProviderProfile }>(`providers/${encodeURIComponent(slug)}`);
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const response = await load(slug);
  return response ? { title: response.data.display_name, description: response.data.headline ?? undefined } : { title: 'Prestataire introuvable' };
}

/** Page publique d'un prestataire : présentation, offres, articles, contact. */
export default async function ProviderPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const response = await load(slug);
  if (!response) notFound();
  const provider = response.data;

  const [offers, articles] = await Promise.all([
    apiOrNull<Paginated<PublicOffer>>(`providers/${encodeURIComponent(slug)}/offers`, { query: { per_page: 6 } }),
    apiOrNull<Paginated<ArticleSummary>>(`providers/${encodeURIComponent(slug)}/articles`, { query: { per_page: 3 } }),
  ]);
  const place = [provider.city, countryName(provider.country)].filter(Boolean).join(', ');

  return (
    <PageShell
      title={provider.display_name}
      subtitle={provider.headline ?? undefined}
      bandExtra={
        <div style={{ display: 'flex', gap: 12, marginTop: 24, flexWrap: 'wrap' }}>
          <Link href={`/prestataires/${provider.slug}/contacter`} className="btn btn--lime">
            <Icon name="message" size={18} /> Contacter
          </Link>
        </div>
      }
    >
      <div className="with-aside with-aside--right">
        <div className="form-stack" style={{ gap: 32 }}>
          <section className="provider-profile">
            <ProviderCover name={provider.display_name} bannerUrl={provider.banner_url} logoUrl={provider.logo_url} subtitle={[provider.headline, place].filter(Boolean).join(' · ') || undefined}>
              <div className="project-card__pills" style={{ marginTop: 10 }}>
                {provider.categories.map((category) => (
                  <Link key={category.slug} href={`/prestataires?categorie=${category.slug}`} className="pill pill--tag">
                    {category.name}
                  </Link>
                ))}
              </div>
            </ProviderCover>
            <div className="card provider-profile__about">
              <h2 className="section-title" style={{ fontSize: 20, marginBottom: 12 }}>
                À propos
              </h2>
              {provider.bio ? <p className="preline" style={{ margin: 0 }}>{provider.bio}</p> : <p style={{ color: 'var(--muted)', margin: 0 }}>Ce prestataire n&apos;a pas encore rédigé de présentation.</p>}
            </div>
          </section>

          <section>
            <h2 className="section-title section-title--bullet">Offres</h2>
            {offers && offers.data.length > 0 ? (
              <div className="card-grid card-grid--2">
                {offers.data.map((offer) => (
                  <OfferCard key={offer.slug} offer={offer} showProvider={false} />
                ))}
              </div>
            ) : (
              <div className="card empty">Aucune offre publiée pour le moment. Vous pouvez contacter ce prestataire pour lui décrire votre besoin.</div>
            )}
          </section>

          {articles && articles.data.length > 0 ? (
            <section>
              <h2 className="section-title section-title--bullet">Articles</h2>
              <div className="card-grid card-grid--3">
                {articles.data.map((article) => (
                  <ArticleCard key={article.slug} article={article} />
                ))}
              </div>
            </section>
          ) : null}
        </div>

        <aside className="aside-stack">
          <div className="card aside-card">
            <h3>Travailler avec {provider.display_name}</h3>
            <p>Décrivez votre besoin : la réponse arrive dans votre messagerie Loopartner. Vos coordonnées ne sont jamais transmises.</p>
            <Link href={`/prestataires/${provider.slug}/contacter`} className="btn btn--dark btn--block">
              Envoyer une demande
            </Link>
          </div>
          <div className="card aside-card">
            <dl className="info-list">
              {provider.published_at ? (
                <div>
                  <dt>Sur Loopartner depuis</dt>
                  <dd>{formatDate(provider.published_at, { month: 'long', year: 'numeric' })}</dd>
                </div>
              ) : null}
              {place ? (
                <div>
                  <dt>Localisation</dt>
                  <dd>{place}</dd>
                </div>
              ) : null}
              {provider.website && /^https?:\/\//i.test(provider.website) ? (
                <div>
                  <dt>Site</dt>
                  <dd>
                    <a href={provider.website} target="_blank" rel="nofollow ugc noopener noreferrer">
                      {provider.website.replace(/^https?:\/\//, '').replace(/\/$/, '')}
                    </a>
                  </dd>
                </div>
              ) : null}
            </dl>
            <p className="disclaimer" style={{ textAlign: 'left' }}>
              Informations déclarées par le prestataire. Loopartner n&apos;est pas partie aux contrats conclus avec lui.
            </p>
          </div>
          <div style={{ textAlign: 'center' }}>
            <ReportButton type="provider_profile" reference={provider.slug} label="Signaler ce profil" />
          </div>
        </aside>
      </div>
    </PageShell>
  );
}
