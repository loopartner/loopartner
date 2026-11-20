import type { ReactNode } from 'react';
import { SiteFooter } from './SiteFooter';
import { SiteHeader } from './SiteHeader';

/**
 * Gabarit des pages publiques : bandeau bleu avec en-tête et titre, puis feuille crème arrondie.
 * `title` peut contenir un mot surligné (<Highlight>).
 */
export function PageShell({
  title,
  subtitle,
  eyebrow,
  children,
  bandExtra,
}: {
  title: ReactNode;
  subtitle?: ReactNode;
  eyebrow?: ReactNode;
  children: ReactNode;
  bandExtra?: ReactNode;
}) {
  return (
    <>
      <a href="#contenu" className="skip-link">
        Aller au contenu
      </a>
      <div className="hero-band hero-band--page">
        <SiteHeader />
        <div className="container">
          {eyebrow ? <div style={{ marginTop: 40, display: 'flex', gap: 12, alignItems: 'center' }}>{eyebrow}</div> : null}
          <h1 className="page-title" style={eyebrow ? { marginTop: 16 } : undefined}>
            {title}
          </h1>
          {subtitle ? <p className="page-subtitle">{subtitle}</p> : null}
          {bandExtra}
        </div>
      </div>
      <main id="contenu" className="sheet">
        <div className="container">{children}</div>
      </main>
      <SiteFooter />
    </>
  );
}

export function Highlight({ children }: { children: ReactNode }) {
  return <span className="highlight">{children}</span>;
}
