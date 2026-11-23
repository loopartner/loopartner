import Link from 'next/link';
import { DELIVERY_LABELS, offerPrice } from '@/lib/offers';
import type { PublicOffer } from '@/lib/types';
import { Icon } from './Icon';

/** Carte d'une offre publiée : prix indicatif TTC saisi par le prestataire, jamais recalculé. */
export function OfferCard({ offer, showProvider = true }: { offer: PublicOffer; showProvider?: boolean }) {
  return (
    <article className="card offer-card offer-card--image">
      <Link href={`/offres/${offer.slug}`} className={`offer-card__media${offer.image_url ? '' : ' offer-card__media--empty'}`} tabIndex={-1} aria-hidden="true">
        {offer.image_url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={offer.image_url} alt="" loading="lazy" />
        ) : (
          <Icon name="briefcase" size={36} />
        )}
      </Link>
      <div className="project-card__pills">
        {offer.category ? <span className="pill pill--tag">{offer.category.name}</span> : null}
        <span className="pill pill--soft">{DELIVERY_LABELS[offer.delivery_mode]}</span>
      </div>
      <h3 className="offer-card__title">
        <Link href={`/offres/${offer.slug}`}>{offer.title}</Link>
      </h3>
      <p className="offer-card__summary">{offer.summary}</p>
      <div style={{ marginTop: 'auto', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
        <span className="offer-card__price">{offerPrice(offer.pricing.mode, offer.pricing.price_cents, offer.pricing.currency)}</span>
        <Link href={`/offres/${offer.slug}`} className="link-arrow">
          Voir l&apos;offre <Icon name="arrowRight" size={16} />
        </Link>
      </div>
      {showProvider && offer.provider ? <small style={{ color: 'var(--muted)' }}>Par {offer.provider.display_name}</small> : null}
    </article>
  );
}
