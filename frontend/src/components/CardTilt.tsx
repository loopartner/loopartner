'use client';

import { useEffect } from 'react';

/** Cartes qui pivotent au survol : projets, événements, offres et prestataires. */
const SELECTOR = '.ticket, .offer-card, .provider-card';
/** Inclinaison maximale, en degrés. */
const MAX_TILT = 5;

/**
 * Incline légèrement une carte vers le pointeur (perspective 3D), par délégation d'événements :
 * aucune carte n'a besoin d'être un composant client. Coupé sur écran tactile et si
 * l'utilisateur demande moins d'animations.
 */
export function CardTilt() {
  useEffect(() => {
    if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    let current: HTMLElement | null = null;
    let frame = 0;

    const reset = (card: HTMLElement) => {
      card.style.removeProperty('--tilt-x');
      card.style.removeProperty('--tilt-y');
      card.classList.remove('is-tilting');
    };

    const onMove = (event: PointerEvent) => {
      const card = (event.target as Element | null)?.closest<HTMLElement>(SELECTOR) ?? null;
      if (current && current !== card) reset(current);
      current = card;
      if (!card) return;
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(() => {
        const rect = card.getBoundingClientRect();
        const x = (event.clientX - rect.left) / rect.width - 0.5;
        const y = (event.clientY - rect.top) / rect.height - 0.5;
        card.style.setProperty('--tilt-x', `${(-y * MAX_TILT * 2).toFixed(2)}deg`);
        card.style.setProperty('--tilt-y', `${(x * MAX_TILT * 2).toFixed(2)}deg`);
        card.classList.add('is-tilting');
      });
    };

    const onLeave = () => {
      if (current) reset(current);
      current = null;
    };

    document.addEventListener('pointermove', onMove, { passive: true });
    document.documentElement.addEventListener('pointerleave', onLeave);
    return () => {
      cancelAnimationFrame(frame);
      document.removeEventListener('pointermove', onMove);
      document.documentElement.removeEventListener('pointerleave', onLeave);
    };
  }, []);

  return null;
}
