'use client';

import Link from 'next/link';
import { Icon } from '@/components/Icon';
import { formatDate } from '@/lib/format';
import { PREMIUM_FEATURES, PREMIUM_PRICES, ROLE_TITLES, type PremiumRole } from '@/lib/premium';
import { useSession } from '@/lib/session';

const ROLES: PremiumRole[] = ['prestataire', 'particulier', 'entrepreneur'];

/**
 * Présentation de l'offre (décisions 32, 35 et 81). Le profil de la personne connectée passe en
 * premier. Pas de souscription en ligne avant le lot 10 (Billing) : le bouton l'annonce.
 */
export function PremiumPlans() {
  const { user } = useSession();
  const own = ROLES.find((role) => user?.roles.includes(role));
  const ordered = own ? [own, ...ROLES.filter((role) => role !== own)] : ROLES;
  const active = user?.premium?.active ?? false;

  return (
    <div className="form-stack" style={{ gap: 40 }}>
      <section className="card premium-offer">
        <div>
          <span className="pill pill--tag">
            <Icon name="gift" size={14} /> Essai gratuit de {PREMIUM_PRICES.trialDays} jours
          </span>
          <h2 className="section-title" style={{ fontSize: 28, margin: '16px 0 8px' }}>
            {PREMIUM_PRICES.monthly}
          </h2>
          <p className="premium-offer__alt">ou {PREMIUM_PRICES.yearly}, sans engagement, résiliable en ligne à tout moment.</p>
          <p className="premium-offer__note">Prix de lancement prévus, affichés à titre indicatif.</p>
        </div>
        <div className="premium-offer__cta">
          {active ? (
            <p className="alert alert--success" style={{ margin: 0 }}>
              <Icon name="check" size={16} /> Votre compte est Premium
              {user?.premium?.ends_at ? ` jusqu'au ${formatDate(user.premium.ends_at)}` : ''}.
            </p>
          ) : (
            <>
              <button type="button" className="btn btn--lime btn--lg" disabled aria-describedby="premium-soon">
                Passer Premium
              </button>
              <p id="premium-soon" className="premium-offer__note">
                Le paiement en ligne arrive bientôt. En attendant, l&apos;équipe Loopartner peut activer Premium sur votre compte.
              </p>
            </>
          )}
          {!user ? (
            <Link href="/inscription" className="link-arrow">
              Créer un compte gratuit <Icon name="arrowRight" size={14} />
            </Link>
          ) : null}
        </div>
      </section>

      {ordered.map((role) => (
        <section key={role} aria-labelledby={`premium-${role}`}>
          <h2 id={`premium-${role}`} className="section-title" style={{ fontSize: 24 }}>
            {ROLE_TITLES[role]}
            {role === own ? <span className="pill pill--status" style={{ marginLeft: 12, verticalAlign: 'middle' }}>Votre profil</span> : null}
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
                {PREMIUM_FEATURES[role].map((feature) => (
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
      ))}

      <p className="premium-offer__note">
        Aucune mise en avant payante : Premium ne change jamais l&apos;ordre des résultats, et les avis ne sont ni triés ni masqués. Les conditions
        détaillées seront précisées dans les conditions générales de vente.
      </p>
    </div>
  );
}
