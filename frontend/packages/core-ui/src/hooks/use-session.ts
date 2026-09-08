import { useEffect } from 'react';
import { useUser } from '@erp/core-api';

import { useAuthStore } from '../stores/auth-store';

// Syncs the `/user` session check into the Zustand store so screens outside this
// hook's own subscriber tree (e.g. the app shell) can read the current user directly.
export function useSession() {
  const storedUser = useAuthStore((state) => state.user);
  const setUser = useAuthStore((state) => state.setUser);
  const query = useUser({ query: { retry: false } });

  // Derive authentication directly from the successful session response. Waiting
  // for the effect below to copy that response into Zustand left one render where
  // `/user` had succeeded but `storedUser` was still null; ProtectedRoute used that
  // render to redirect a freshly reloaded page back to /login.
  const responseUser = query.data?.status === 200 ? query.data.data.data : null;

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
