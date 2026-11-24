'use client';

import { useState } from 'react';
import { DEMO_ACCOUNTS, DEMO_MODE, DEMO_PASSWORD } from '@/lib/demo';

/** Bandeau visible sur chaque page en mode démonstration, avec les comptes de test fictifs. */
export function DemoBanner() {
  const [open, setOpen] = useState(false);
  if (!DEMO_MODE) return null;

  return (
    <div className="demo-banner" role="note">
      <strong>Mode démonstration</strong> : projets, articles, événements et comptes fictifs. Rien n’est enregistré sur le serveur ni envoyé par email.{' '}
      <button type="button" className="demo-banner__toggle" onClick={() => setOpen((value) => !value)} aria-expanded={open}>
        {open ? 'Masquer les comptes de test' : 'Voir les comptes de test'}
      </button>
      {open && (
        <ul className="demo-banner__accounts">
          {Object.entries(DEMO_ACCOUNTS).map(([email, account]) => (
            <li key={email}>
              {account.label} : <code>{email}</code> / <code>{DEMO_PASSWORD}</code>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
