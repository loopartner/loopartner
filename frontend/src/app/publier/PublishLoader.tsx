'use client';

import dynamic from 'next/dynamic';

/** Le parcours lit le brouillon de l'onglet (sessionStorage) : rendu côté navigateur seulement. */
export const PublishLoader = dynamic(() => import('./PublishWizard').then((module) => module.PublishWizard), {
  ssr: false,
  loading: () => <div className="wizard" />,
});
