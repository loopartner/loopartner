'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Icon } from '@/components/Icon';

/** Champ « Quel est votre projet ? » : ouvre la publication avec le résumé déjà rempli. */
export function HomeSearch() {
  const router = useRouter();
  const [value, setValue] = useState('');

  return (
    <form
      className="home-search"
      role="search"
      onSubmit={(event) => {
        event.preventDefault();
        const title = value.trim();
        router.push(title ? `/publier?titre=${encodeURIComponent(title.slice(0, 150))}` : '/publier');
      }}
    >
      <label htmlFor="home-project" className="visually-hidden">
        Quel est votre projet ?
      </label>
      <input id="home-project" placeholder="Quel est votre projet ?" value={value} onChange={(event) => setValue(event.target.value)} maxLength={150} />
      <button type="submit" className="btn btn--lime">
        Recevoir des devis <Icon name="arrowRight" size={16} />
      </button>
    </form>
  );
}
