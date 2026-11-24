'use client';

import Link from 'next/link';
import { Icon } from '@/components/Icon';
import { useSession } from '@/lib/session';

/**
 * Encarts de la page des projets selon la personne : un visiteur voit les deux invitations
 * (publier, s'inscrire comme prestataire) ; un porteur de projet, seulement « publier » ; un
 * prestataire, ce qui lui sert (Premium pour proposer, ou ses messages).
 */
export function ProjectsAsideCtas() {
  const { user, hasRole } = useSession();
  const isProvider = hasRole('prestataire');

  if (isProvider) {
    const premium = user?.premium?.active ?? false;
    return (
      <div className="card aside-card">
        <h3>{premium ? 'Répondez aux projets' : 'Proposez vos services'}</h3>
        <p>
          {premium
            ? 'Ouvrez un projet pour voir son détail et envoyer votre proposition. Les réponses arrivent dans vos messages.'
            : 'Le détail des projets et l’envoi de propositions sont réservés aux prestataires Premium.'}
        </p>
        <Link href={premium ? '/messages' : '/premium'} className="btn btn--lime btn--block">
          {premium ? 'Mes messages' : 'Découvrir Premium'}
        </Link>
      </div>
    );
  }

  return (
    <>
      <div className="card aside-card">
        <h3>Trouvez un expert rapidement</h3>
        <p>Postez votre projet gratuitement pour être mis en relation avec des prestataires disponibles.</p>
        <Link href="/publier" className="btn btn--lime btn--block">
          Publier un projet
        </Link>
      </div>
      {!user ? (
        <div className="card aside-card">
          <h3>Vous êtes prestataire ?</h3>
          <p>Rejoignez notre communauté et répondez aux opportunités qui vous correspondent.</p>
          <Link href="/inscription?profil=prestataire" className="link-arrow">
            Inscription gratuite <Icon name="arrowRight" size={16} />
          </Link>
        </div>
      ) : null}
    </>
  );
}

/** Bandeau d'inscription des prestataires, au milieu de la liste : visiteurs seulement. */
export function ProviderSignupBanner() {
  const { user, loading } = useSession();
  if (user || loading) return null;
  return (
    <div className="cta-banner">
      <span>Vous êtes prestataire ? Inscrivez-vous gratuitement pour répondre aux projets.</span>
      <Link href="/inscription?profil=prestataire" className="btn btn--dark">
        Créer mon compte
      </Link>
    </div>
  );
}
