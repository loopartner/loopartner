'use client';

import { useEffect, useState } from 'react';
import { api } from './api';
import { useSession } from './session';

const INTERVAL_MS = 60_000;

/**
 * Nombre de messages non lus (GET /me/conversations/unread, endpoint léger prévu pour
 * l'interrogation régulière). Interrogé chaque minute quand l'onglet est visible ; 0 sans session
 * ou sans email vérifié (route protégée par « verified »).
 */
export function useUnreadCount(): number {
  const { user } = useSession();
  const [count, setCount] = useState(0);
  const enabled = Boolean(user?.email_verified);

  useEffect(() => {
    if (!enabled) return;
    let active = true;
    const load = () => {
      if (document.visibilityState !== 'visible') return;
      api<{ data: { messages: number } }>('me/conversations/unread')
        .then((response) => active && setCount(response.data.messages))
        .catch(() => undefined);
    };
    load();
    const timer = window.setInterval(load, INTERVAL_MS);
    const onFocus = () => load();
    window.addEventListener('focus', onFocus);
    window.addEventListener('loopartner:messages-read', onFocus);
    return () => {
      active = false;
      window.clearInterval(timer);
      window.removeEventListener('focus', onFocus);
      window.removeEventListener('loopartner:messages-read', onFocus);
    };
  }, [enabled]);

  return enabled ? count : 0;
}
