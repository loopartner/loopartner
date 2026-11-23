import type { Metadata } from 'next';
import Link from 'next/link';
import { AuthLink } from '@/components/AuthLink';
import { notFound } from 'next/navigation';
import { Icon } from '@/components/Icon';
import { PageShell } from '@/components/PageShell';
import { ReportButton } from '@/components/ReportButton';
import { apiOrNull } from '@/lib/api';
import { countryName, formatDate, initials } from '@/lib/format';
import { DELIVERY_LABELS, offerPrice, PRICING_LABELS } from '@/lib/offers';
import type { PublicOffer } from '@/lib/types';

export const dynamic = 'force-dynamic';

async function load(slug: string) {
  return apiOrNull<{ data: PublicOffer }>(`offers/${encodeURIComponent(slug)}`);
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const response = await load(slug);
  return response ? { title: response.data.title, description: response.data.summary } : { title: 'Offre introuvable' };
}

/** Détail d'une offre : description, prix indicatif, qualité déclarée du vendeur (jamais ses coordonnées). */
export default async function OfferPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const response = await load(slug);
  if (!response) notFound();
  const offer = response.data;
  const provider = offer.provider;
  const seller = offer.seller;

  return (
    <PageShell
      title={offer.title}
      eyebrow={offer.category ? <span className="pill pill--soft">{offer.category.name}</span> : undefined}
    >
      <div className="with-aside with-aside--right">
        <article className="card offer-detail" style={{ padding: offer.image_url ? 0 : 40 }}>
          {offer.image_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={offer.image_url} alt="" className="offer-detail__image" />
          ) : null}
          <div style={offer.image_url ? { padding: 40 } : undefined}>
          <p style={{ fontFamily: 'var(--font-text)', fontSize: 18, fontWeight: 500, margin: '0 0 24px' }}>{offer.summary}</p>
          <p className="preline">{offer.description}</p>
          <p className="disclaimer" style={{ textAlign: 'left', borderTop: '1px solid var(--border)', paddingTop: 24, marginTop: 32 }}>
            Prix indicatif TTC saisi par le prestataire. Le prix et les conditions définitifs figurent dans le devis qu&apos;il vous enverra par
            Loopartner. Loopartner n&apos;est pas partie au contrat.
          </p>
          <ReportButton type="offer" reference={offer.slug} label="Signaler cette offre" />
          </div>
        </article>

        <aside className="aside-stack">
          <div className="card aside-card">
            <span className="field__hint">{PRICING_LABELS[offer.pricing.mode]}</span>
            <p style={{ fontSize: 26, fontWeight: 700, margin: '4px 0 16px', color: 'var(--ink)', fontFamily: 'var(--font-ui, inherit)' }}>{offerPrice(offer.pricing.mode, offer.pricing.price_cents, offer.pricing.currency)}</p>
            <dl className="info-list" style={{ marginBottom: 20 }}>
              <div>
                <dt>Réalisation</dt>
                <dd>{DELIVERY_LABELS[offer.delivery_mode]}</dd>
              </div>
              <div>
                <dt>Lieu</dt>
                <dd>{[offer.city, countryName(offer.country)].filter(Boolean).join(', ')}</dd>
              </div>
              {offer.published_at ? (
                <div>
                  <dt>Publiée le</dt>
                  <dd>{formatDate(offer.published_at)}</dd>
                </div>
              ) : null}
            </dl>
            {provider?.slug ? (
              <AuthLink href={`/prestataires/${provider.slug}/contacter`} reason="Créez un compte gratuit ou connectez-vous pour demander un devis à ce prestataire. Vos échanges restent dans la messagerie Loopartner." className="btn btn--lime btn--block">
                <Icon name="message" size={18} /> Demander un devis
              </AuthLink>
            ) : null}
          </div>

          {provider ? (
            <div className="card aside-card">
              <div className="author" style={{ marginBottom: 12 }}>
                {provider.logo_url ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={provider.logo_url} alt="" className="avatar avatar--lg" />
                ) : (
                  <span className="avatar avatar--lg">{initials(provider.display_name)}</span>
                )}
                <div>
                  <strong>{provider.slug ? <Link href={`/prestataires/${provider.slug}`}>{provider.display_name}</Link> : provider.display_name}</strong>
                  {provider.headline ? <small style={{ display: 'block', color: 'var(--muted)' }}>{provider.headline}</small> : null}
                </div>
              </div>
              {seller ? (
                <p className="disclaimer" style={{ textAlign: 'left', margin: 0 }}>
                  Professionnel déclaré : {seller.trade_name ? `${seller.trade_name} (${seller.legal_name})` : seller.legal_name}, SIREN {seller.registration_number}. Ces
                  informations sont déclarées sur l&apos;honneur par le prestataire.
                </p>
              ) : null}
            </div>
          ) : null}
        </aside>
      </div>
    </PageShell>
  );
}
