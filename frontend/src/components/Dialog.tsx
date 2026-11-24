'use client';

import { useEffect, useId, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { Icon } from './Icon';

/**
 * Fenêtre modale accessible : titre annoncé, fermeture par Échap ou par le bouton, focus placé
 * dans la fenêtre à l'ouverture puis rendu à l'élément d'origine à la fermeture. Rendue à la
 * racine du document (portail) pour ne pas hériter des styles ni de l'empilement de la page.
 */
export function Dialog({ title, onClose, children, wide = false }: { title: string; onClose: () => void; children: ReactNode; wide?: boolean }) {
  const titleId = useId();
  const panel = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null;
    panel.current?.focus();
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      window.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
      previous?.focus();
    };
  }, [onClose]);

  return createPortal(
    <div className="dialog" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <div ref={panel} className={`dialog__panel${wide ? ' dialog__panel--wide' : ''}`} role="dialog" aria-modal="true" aria-labelledby={titleId} tabIndex={-1}>
        <div className="dialog__header">
          <h2 id={titleId}>{title}</h2>
          <button type="button" className="dialog__close" onClick={onClose} aria-label="Fermer">
            <Icon name="close" />
          </button>
        </div>
        {children}
      </div>
    </div>,
    document.body,
  );
}
