'use client';

import { useEffect, useLayoutEffect, useRef, useState } from 'react';

/**
 * Mot du titre de l'accueil qui change toutes les 2 secondes (« les bons juristes »,
 * « les bons avocats »…), dans le surlignage noir incliné. Sans dépendance : transitions CSS.
 * Les lecteurs d'écran lisent un titre fixe ; le mouvement est coupé si l'utilisateur le demande.
 */
export function RotatingWord({ words, interval = 2000 }: { words: string[]; interval?: number }) {
  const [index, setIndex] = useState(0);
  const [width, setWidth] = useState<number | null>(null);
  const [fontsReady, setFontsReady] = useState(0);
  const refs = useRef<(HTMLSpanElement | null)[]>([]);

  useEffect(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const timer = window.setInterval(() => setIndex((current) => (current + 1) % words.length), interval);
    return () => window.clearInterval(timer);
  }, [words.length, interval]);

  // Les largeurs changent quand la police Outfit finit de charger : on mesure de nouveau.
  useEffect(() => {
    let cancelled = false;
    void document.fonts?.ready.then(() => {
      if (!cancelled) setFontsReady((count) => count + 1);
    });
    return () => {
      cancelled = true;
    };
  }, []);

  useLayoutEffect(() => {
    const element = refs.current[index];
    if (element) setWidth(element.offsetWidth + 2);
  }, [index, fontsReady]);

  return (
    <span className="highlight rotating-word" style={width ? { width } : undefined}>
      <span className="visually-hidden">{words[0]}.</span>
      <span aria-hidden="true" className="rotating-word__track">
        {words.map((word, position) => (
          <span
            key={word}
            ref={(element) => {
              refs.current[position] = element;
            }}
            className={`rotating-word__item${position === index ? ' is-active' : position < index ? ' is-before' : ''}`}
          >
            {word}.
          </span>
        ))}
      </span>
    </span>
  );
}
