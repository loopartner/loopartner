import type { ReactNode } from 'react';
import { initials } from '@/lib/format';

/**
 * En-tête visuel d'un profil prestataire (décision 82) : bannière, photo du profil public par
 * dessus, nom, lieu et contenu complémentaire (catégories, boutons). Sans bannière, un dégradé
 * aux couleurs de Loopartner la remplace (aperçu d'un profil en cours de création).
 */
export function ProviderCover({
  name,
  bannerUrl,
  logoUrl,
  subtitle,
  children,
  compact = false,
}: {
  name: string;
  bannerUrl: string | null | undefined;
  logoUrl: string | null | undefined;
  subtitle?: ReactNode;
  children?: ReactNode;
  compact?: boolean;
}) {
  return (
    <div className={`provider-cover${compact ? ' provider-cover--compact' : ''}`}>
      <div className="provider-cover__banner">
        {bannerUrl ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={bannerUrl} alt="" />
        ) : null}
      </div>
      <div className="provider-cover__body">
        {logoUrl ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={logoUrl} alt="" className="provider-cover__logo" />
        ) : (
          <span className="provider-cover__logo provider-cover__logo--initials" aria-hidden="true">
            {initials(name || '?')}
          </span>
        )}
        <div className="provider-cover__text">
          <h2 className="provider-cover__name">{name || 'Votre nom'}</h2>
          {subtitle ? <p className="provider-cover__subtitle">{subtitle}</p> : null}
          {children}
        </div>
      </div>
    </div>
  );
}
