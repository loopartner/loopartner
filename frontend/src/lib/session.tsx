'use client';

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { api, ApiError, auth } from './api';
import type { Me, Role } from './types';

/**
 * Session de l'utilisateur côté navigateur : `GET /me` au chargement (401 = visiteur), puis
 * rechargée après une connexion, une inscription ou une déconnexion.
 */

interface SessionValue {
  user: Me | null;
  loading: boolean;
  refresh: () => Promise<Me | null>;
  logout: () => Promise<void>;
  hasRole: (...roles: Role[]) => boolean;
}

const SessionContext = createContext<SessionValue | null>(null);

export function SessionProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<Me | null>(null);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async (): Promise<Me | null> => {
    try {
      const response = await api<{ data: Me }>('me');
      setUser(response.data);
      return response.data;
    } catch (error) {
      // 401 : visiteur. Toute autre erreur (API injoignable) : on reste sans session.
      if (!(error instanceof ApiError) || error.status !== 403) setUser(null);
      return null;
    } finally {
      setLoading(false);
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await auth('logout');
    } finally {
      setUser(null);
    }
  }, []);

  useEffect(() => {
    let active = true;
    api<{ data: Me }>('me')
      .then((response) => {
        if (active) setUser(response.data);
      })
      .catch(() => undefined)
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, []);

  const value = useMemo<SessionValue>(
    () => ({
      user,
      loading,
      refresh,
      logout,
      hasRole: (...roles: Role[]) => user !== null && user.roles.some((role) => roles.includes(role)),
    }),
    [user, loading, refresh, logout],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionValue {
  const value = useContext(SessionContext);
  if (value === null) throw new Error('useSession doit être utilisé dans <SessionProvider>.');
  return value;
}
