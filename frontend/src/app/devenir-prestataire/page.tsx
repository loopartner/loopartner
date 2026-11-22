import type { Metadata } from 'next';
import Link from 'next/link';
import { Icon } from '@/components/Icon';
import { PageShell } from '@/components/PageShell';
import { PREMIUM_FEATURES, PREMIUM_PRICES } from '@/lib/premium';

export const metadata: Metadata = {
  title: 'Devenir prestataire',
  description: 'Rejoignez Loopartner : créez votre profil, publiez vos offres et répondez aux projets des porteurs.',
};

const STEPS = [
  {
    title: 'Créez votre compte',
    text: 'Inscription gratuite avec le profil « Prestataire ». Vérifiez ensuite votre adresse email.',
  },
  {
    title: 'Publiez votre profil',
    text: 'Présentation, logo et 1 à 5 catégories : votre profil apparaît dans l’annuaire des prestataires.',
  },
  {
    title: 'Ajoutez vos offres',
    text: 'Renseignez votre identité professionnelle, puis publiez vos prestations avec un prix indicatif.',
  },
  {
    title: 'Répondez aux projets',
    text: 'Avec Premium, consultez le détail des projets et envoyez vos propositions aux porteurs.',
  },
];

/** Page d'accueil des prestataires : inscription gratuite, accès aux projets avec Premium (décision 81). */
export default function BecomeProviderPage() {
  return (
    <PageShell
      title="Devenir prestataire"
      subtitle="Faites-vous connaître des porteurs de projet et des entrepreneurs qui se lancent."
      bandExtra={
        <div style={{ display: 'flex', gap: 12, marginTop: 24, flexWrap: 'wrap' }}>
          <Link href="/inscription?profil=prestataire" className="btn btn--lime btn--lg">
            Créer mon compte prestataire
          </Link>
          <Link href="/premium" className="btn btn--outline btn--lg" style={{ background: '#fff' }}>
            Découvrir Premium
          </Link>
        </div>
      }
    >
      <div className="form-stack" style={{ gap: 56 }}>
        <section aria-labelledby="steps-title">
          <h2 id="steps-title" className="section-title">
            Comment ça marche
          </h2>
          <ol className="steps-list">
            {STEPS.map((step, index) => (
              <li key={step.title} className="card">
                <span className="how__step">{index + 1}</span>
                <h3 style={{ margin: 0 }}>{step.title}</h3>
                <p>{step.text}</p>
              </li>
            ))}
          </ol>
        </section>

        <section aria-labelledby="free-premium-title" className="with-aside with-aside--right">
          <div className="card">
            <h2 id="free-premium-title" className="section-title" style={{ fontSize: 24, marginTop: 0 }}>
              Gratuit ou Premium
            </h2>
            <table className="table premium-table">
              <thead>
                <tr>
                  <th scope="col">Fonctionnalité</th>
                  <th scope="col">Gratuit</th>
                  <th scope="col">Premium</th>
                </tr>
              </thead>
              <tbody>
                {PREMIUM_FEATURES.prestataire.map((feature) => (
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
          <aside className="aside-stack">
            <div className="card aside-card">
              <h3>
                <Icon name="gift" size={18} /> Premium
              </h3>
              <p style={{ fontFamily: 'var(--font-text)', margin: 0, lineHeight: 1.6 }}>
                {PREMIUM_PRICES.monthly} ou {PREMIUM_PRICES.yearly}, avec un essai gratuit de {PREMIUM_PRICES.trialDays} jours. Prix de lancement
                prévus.
              </p>
              <Link href="/premium" className="link-arrow">
                Tous les avantages <Icon name="arrowRight" size={14} />
              </Link>
            </div>
            <div className="card aside-card">
              <h3>
                <Icon name="shield" size={18} /> Une mise en relation loyale
              </h3>
              <p style={{ fontFamily: 'var(--font-text)', margin: 0, lineHeight: 1.6 }}>
                Aucune mise en avant payante : l&apos;ordre des résultats est le même pour tous. Les échanges passent par la messagerie, sans
                partager vos coordonnées.
              </p>
            </div>
          </aside>
        </section>

        <section className="card" style={{ textAlign: 'center' }}>
          <h2 className="section-title" style={{ fontSize: 24, marginTop: 0, textAlign: 'center' }}>
            Prêt à vous lancer ?
          </h2>
          <div style={{ display: 'flex', gap: 12, justifyContent: 'center', flexWrap: 'wrap' }}>
            <Link href="/inscription?profil=prestataire" className="btn btn--dark btn--lg">
              Créer mon compte prestataire
            </Link>
            <Link href="/projets" className="btn btn--outline btn--lg">
              Voir les projets
            </Link>
          </div>
        </section>
      </div>
    </PageShell>
  );
}
