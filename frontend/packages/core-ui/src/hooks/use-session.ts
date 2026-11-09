import { useEffect, useRef } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { getUserQueryKey, useUser } from '@erp/core-api';

import { clearPrivateQueryCache } from '../lib/session-cache';
import { useAuthStore } from '../stores/auth-store';

// Syncs the `/user` session check into the Zustand store so screens outside this
// hook's own subscriber tree (e.g. the app shell) can read the current user directly.
export function useSession() {
  const storedUser = useAuthStore((state) => state.user);
  const setUser = useAuthStore((state) => state.setUser);
  const query = useUser({ query: { retry: false } });
  const queryClient = useQueryClient();
  const lastIdentity = useRef<number | null>(null);

  // Derive authentication directly from the successful session response. Waiting
  // for the effect below to copy that response into Zustand left one render where
  // `/user` had succeeded but `storedUser` was still null; ProtectedRoute used that
  // render to redirect a freshly reloaded page back to /login.
  const responseUser = query.data?.status === 200 ? query.data.data.data : null;

  // The identity can change under a mounted app (the session expired, or another person signed in): whatever was cached
  // for the previous person must not be shown to the next one.
  const identity = responseUser?.id ?? null;
  useEffect(() => {
    if (lastIdentity.current !== null && lastIdentity.current !== identity) {
      clearPrivateQueryCache(queryClient, getUserQueryKey());
    }
    if (identity !== null || query.isError) lastIdentity.current = identity;
  }, [identity, query.isError, queryClient]);

  useEffect(() => {
    if (query.data?.status === 200) {
      setUser(query.data.data.data);
    } else if (query.isError) {
      setUser(null);
    }
  }, [query.data, query.isError, setUser]);

  return {
    user: responseUser ?? storedUser,
    // An errored query can be refetched when a protected route remounts after a
    // successful login. Do not redirect using the cached error while that fresh
    // request is still in flight.
    isLoading: query.isLoading || (query.isFetching && query.data === undefined),
    isAuthenticated: query.data?.status === 200,
  };
}
