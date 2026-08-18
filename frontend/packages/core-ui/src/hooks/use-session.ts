import { useEffect } from 'react';
import { useUser } from '@erp/core-api';

import { useAuthStore } from '../stores/auth-store';

// Syncs the `/user` session check into the Zustand store so screens outside this
// hook's own subscriber tree (e.g. the app shell) can read the current user directly.
export function useSession() {
  const user = useAuthStore((state) => state.user);
  const setUser = useAuthStore((state) => state.setUser);
  const query = useUser({ query: { retry: false } });

  useEffect(() => {
    if (query.data?.status === 200) {
      setUser(query.data.data.data);
    } else if (query.isError) {
      setUser(null);
    }
  }, [query.data, query.isError, setUser]);

  return {
    user,
    isLoading: query.isLoading,
    isAuthenticated: !query.isError && user !== null,
  };
}
