import Image from 'next/image';
import Link from 'next/link';

export function SiteFooter() {
  const year = new Date().getFullYear();

  return (
    <footer className="site-footer">
      <div className="container">
        <div className="site-footer__grid">
          <div>
            <Link href="/" className="site-footer__logo" aria-label="Loopartner, accueil">
              <Image src="/images/logo-white.png" alt="" width={640} height={214} />
            </Link>
            <p className="site-footer__pitch">
              La plateforme pour lancer votre entreprise avec les bons experts. Publiez votre projet et échangez avec des
              prestataires indépendants.
            </p>
          </div>
          <div>
            <h2>Plateforme</h2>
            <ul>
              <li>
                <Link href="/projets">Voir les projets</Link>
              </li>
              <li>
                <Link href="/prestataires">Trouver un prestataire</Link>
              </li>
              <li>
                <Link href="/#comment-ca-marche">Comment ça marche</Link>
              </li>
              <li>
                <Link href="/evenements">Événements</Link>
              </li>
            </ul>
          </div>
          <div>
            <h2>Ressources</h2>
            <ul>
              <li>
                <Link href="/articles">Articles</Link>
              </li>
              <li>
                <Link href="/publier">Publier un projet</Link>
              </li>
            </ul>
          </div>
          <div>
            <h2>Légal</h2>
            <ul>
              <li>
                <Link href="/legal/terms">Conditions générales d&apos;utilisation</Link>
              </li>
              <li>
                <Link href="/legal/privacy">Confidentialité</Link>
              </li>
            </ul>
          </div>
          <div>
            <h2>Compte</h2>
            <ul>
              <li>
                <Link href="/connexion">Connexion</Link>
              </li>
              <li>
                <Link href="/inscription">Inscription gratuite</Link>
              </li>
            </ul>
          </div>
        </div>
        <div className="site-footer__bottom">
          <span>© {year} Loopartner. Tous droits réservés.</span>
          <span>Français (FR)</span>
        </div>
      </div>
    </footer>
  );
}
