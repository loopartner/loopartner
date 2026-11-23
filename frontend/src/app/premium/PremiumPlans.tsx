'use client';

import Link from 'next/link';
import { useState } from 'react';
import { Icon } from '@/components/Icon';
import { formatDate } from '@/lib/format';
import { PREMIUM_FEATURES, PREMIUM_PRICES, ROLE_TITLES, type PremiumRole } from '@/lib/premium';
import { useSession } from '@/lib/session';

const ROLES: PremiumRole[] = ['prestataire', 'particulier', 'entrepreneur'];

/**
 * Présentation de l'offre (décisions 32, 35 et 81) : trois cartes (Gratuit, Premium mensuel mis
 * en avant, Premium annuel) dont les avantages suivent le profil choisi, puis le tableau détaillé.
 * Pas de souscription en ligne avant le lot 10 (Billing) : les boutons l'annoncent.
 */
export function PremiumPlans() {
  const { user } = useSession();
  const own = ROLES.find((role) => user?.roles.includes(role));
  const [role, setRole] = useState<PremiumRole>(own ?? 'prestataire');
  const active = user?.premium?.active ?? false;
  const features = PREMIUM_FEATURES[role];

  return (
    <div className="pricing">
      <div className="pricing__roles" role="tablist" aria-label="Votre profil">
        {ROLES.map((item) => (
          <button
            key={item}
            type="button"
            role="tab"
            aria-selected={item === role}
            className={item === role ? 'is-active' : undefined}
            onClick={() => setRole(item)}
          >
            {ROLE_TITLES[item]}
            {item === own ? <span className="pricing__mine">Vous</span> : null}
          </button>
        ))}
      </div>

      {active ? (
        <p className="alert alert--success pricing__active">
          <Icon name="check" size={16} /> Votre compte est Premium
          {user?.premium?.ends_at ? ` jusqu'au ${formatDate(user.premium.ends_at)}` : ''}.
        </p>
      ) : null}

      <div className="pricing__grid" role="tabpanel" aria-label={`Offres pour les ${ROLE_TITLES[role].toLowerCase()}`}>
        <article className="pricing-card">
          <PricingArt variant="free" />
          <h2 className="pricing-card__name">Gratuit</h2>
          <p className="pricing-card__price">0 €</p>
          <p className="pricing-card__period">pour toujours</p>
          <p className="pricing-card__pitch">Pour découvrir Loopartner et mener vos premiers échanges.</p>
          <ul className="pricing-card__list">
            {features.map((feature) => (
              <li key={feature.title}>
                <Icon name="check" size={16} />
                <span>{feature.free}</span>
              </li>
            ))}
          </ul>
          <div className="pricing-card__cta">
            {user ? (
              <span className="btn btn--ghost" aria-disabled="true">
                {active ? 'Inclus dans votre compte' : 'Votre offre actuelle'}
              </span>
            ) : (
              <Link href="/inscription" className="btn btn--dark">
                Commencer gratuitement
              </Link>
            )}
          </div>
        </article>

        <article className="pricing-card pricing-card--featured">
          <PricingArt variant="premium" />
          <span className="pricing-card__badge">
            <Icon name="gift" size={14} /> {PREMIUM_PRICES.trialDays} jours d&apos;essai gratuit
          </span>
          <h2 className="pricing-card__name">Premium</h2>
          <p className="pricing-card__price">{PREMIUM_PRICES.monthly.split(' ')[0]} €</p>
          <p className="pricing-card__period">TTC par mois, sans engagement</p>
          <p className="pricing-card__pitch">Pour avancer plus vite, avec tous les outils de votre profil.</p>
          <ul className="pricing-card__list">
            <li>
              <Icon name="check" size={16} />
              <span>Tout ce que propose l&apos;offre gratuite</span>
            </li>
            {features.map((feature) => (
              <li key={feature.title}>
                <Icon name="check" size={16} />
                <span>{feature.premium}</span>
              </li>
            ))}
          </ul>
          <div className="pricing-card__cta">
            <SubscribeButton active={active} light />
          </div>
        </article>

        <article className="pricing-card">
          <PricingArt variant="yearly" />
          <h2 className="pricing-card__name">Premium annuel</h2>
          <p className="pricing-card__price">{PREMIUM_PRICES.yearly.split(' ')[0]} €</p>
          <p className="pricing-card__period">TTC par an</p>
          <p className="pricing-card__pitch">Les mêmes avantages que Premium, en un seul paiement par an.</p>
          <ul className="pricing-card__list">
            <li>
              <Icon name="check" size={16} />
              <span>Tous les avantages Premium</span>
            </li>
            <li>
              <Icon name="check" size={16} />
              <span>Essai gratuit de {PREMIUM_PRICES.trialDays} jours</span>
            </li>
            <li>
              <Icon name="check" size={16} />
              <span>Résiliable en ligne à tout moment</span>
            </li>
          </ul>
          <div className="pricing-card__cta">
            <SubscribeButton active={active} />
          </div>
        </article>
      </div>

      <p className="pricing__note" id="premium-soon">
        Le paiement en ligne arrive bientôt. En attendant, l&apos;équipe Loopartner peut activer Premium sur votre compte. Prix de lancement prévus,
        affichés à titre indicatif.
      </p>

      <section aria-labelledby="premium-details" className="pricing__details">
        <h2 id="premium-details" className="section-title" style={{ fontSize: 24 }}>
          Comparer en détail : {ROLE_TITLES[role].toLowerCase()}
        </h2>
        <div className="card" style={{ padding: 0, overflowX: 'auto' }}>
          <table className="table premium-table">
            <thead>
              <tr>
                <th scope="col">Fonctionnalité</th>
                <th scope="col">Gratuit</th>
                <th scope="col">Premium</th>
              </tr>
            </thead>
            <tbody>
              {features.map((feature) => (
                <tr key={feature.title}>
                  <th scope="row">{feature.title}</th>
                  <td>{feature.free}</td>
                  <td>
                    <strong>{feature.premium}</strong>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <p className="pricing__note">
        Aucune mise en avant payante : Premium ne change jamais l&apos;ordre des résultats. Les conditions détaillées figurent dans les{' '}
        <Link href="/legal/sales">conditions générales de vente</Link>.
      </p>
    </div>
  );
}

function SubscribeButton({ active, light = false }: { active: boolean; light?: boolean }) {
  if (active) {
    return <span className={light ? 'btn btn--lime' : 'btn btn--ghost'}>Votre offre actuelle</span>;
  }
  return (
    <button type="button" className={light ? 'btn btn--lime' : 'btn btn--dark'} disabled aria-describedby="premium-soon">
      Bientôt disponible
    </button>
  );
}

/** Petites figures géométriques (cercles, demi-cercles, hexagones) aux couleurs de Loopartner. */
function PricingArt({ variant }: { variant: 'free' | 'premium' | 'yearly' }) {
  const face = (x: number, y: number, r: number) => (
    <g stroke="#1a1a1a" strokeWidth={r / 9} strokeLinecap="round" fill="none">
      <path d={`M${x - r * 0.35} ${y - r * 0.1}q${r * 0.1} -${r * 0.12} ${r * 0.2} 0`} />
      <path d={`M${x + r * 0.15} ${y - r * 0.1}q${r * 0.1} -${r * 0.12} ${r * 0.2} 0`} />
      <path d={`M${x - r * 0.3} ${y + r * 0.2}q${r * 0.3} ${r * 0.3} ${r * 0.6} 0`} />
    </g>
  );
  const hexagon = (x: number, y: number, r: number, fill: string) => {
    const points = Array.from({ length: 6 }, (_, index) => {
      const angle = (Math.PI / 3) * index + Math.PI / 6;
      return `${(x + r * Math.cos(angle)).toFixed(1)},${(y + r * Math.sin(angle)).toFixed(1)}`;
    }).join(' ');
    return <polygon points={points} fill={fill} />;
  };

  if (variant === 'free') {
    return (
      <svg className="pricing-card__art" viewBox="0 0 120 110" aria-hidden="true">
        <circle cx="60" cy="14" r="8" fill="#e1e4ab" />
        <path d="M40 32h40a20 20 0 0 1-40 0z" fill="#6aa6da" />
        {hexagon(60, 78, 24, '#1a1a1a')}
        <g stroke="#e1e4ab" strokeWidth="2.4" strokeLinecap="round" fill="none">
          <path d="M51 74q2-2 4 0M65 74q2-2 4 0M52 84q8 7 16 0" />
        </g>
      </svg>
    );
  }
  if (variant === 'premium') {
    return (
      <svg className="pricing-card__art pricing-card__art--large" viewBox="0 0 220 120" aria-hidden="true">
        <path d="M40 6l12 18H28z" fill="#e1e4ab" />
        <rect x="45" y="26" width="10" height="10" transform="rotate(45 50 31)" fill="#99cefb" />
        <circle cx="50" cy="76" r="34" fill="#ffffff" />
        {face(50, 76, 34)}
        <circle cx="112" cy="50" r="7" fill="#e1e4ab" />
        <rect x="92" y="64" width="40" height="40" rx="4" transform="rotate(-12 112 84)" fill="#f3f3ed" />
        {face(112, 84, 20)}
        <rect x="160" y="8" width="20" height="20" transform="rotate(20 170 18)" fill="#99cefb" />
        {hexagon(170, 66, 36, '#e1e4ab')}
        {face(170, 66, 34)}
        <path d="M150 110a20 20 0 0 1 40 0z" fill="#6aa6da" />
      </svg>
    );
  }
  return (
    <svg className="pricing-card__art" viewBox="0 0 200 110" aria-hidden="true">
      {[0, 1, 2, 3, 4].map((index) => {
        const x = 22 + index * 39;
        const colors = ['#6aa6da', '#e1e4ab', '#1a1a1a', '#99cefb', '#e1e4ab'];
        return (
          <g key={index}>
            <circle cx={x} cy={index % 2 ? 16 : 12} r="6" fill={colors[(index + 1) % 5]} />
            {index % 2 ? hexagon(x, 44, 14, colors[index]) : <rect x={x - 12} y="32" width="24" height="24" transform={`rotate(45 ${x} 44)`} fill={colors[index]} />}
            {index % 3 === 0 ? <circle cx={x} cy="86" r="16" fill="#1a1a1a" /> : <path d={`M${x - 18} 86a18 18 0 0 1 36 0v14h-36z`} fill={index % 2 ? '#6aa6da' : '#1a1a1a'} />}
          </g>
        );
      })}
    </svg>
  );
}
