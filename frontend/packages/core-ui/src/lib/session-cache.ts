import type { QueryClient, QueryKey } from '@tanstack/react-query';

/**
 * Forgets everything the app fetched for the person who was signed in. Called at every session boundary (sign out,
 * sign in, the session ending, a different person appearing) so the next person on the same browser tab never
 * sees the previous person's records, permissions or identity from the cache — not even for the moment before a
 * refetch answers. `keep` spares one query (the session's own identity check, which is still mounted).
 */
export function clearPrivateQueryCache(queryClient: QueryClient, keep?: QueryKey): void {
  const keptKey = keep ? JSON.stringify(keep) : null;
  const predicate = (query: { queryKey: QueryKey }) => JSON.stringify(query.queryKey) !== keptKey;
  void queryClient.cancelQueries({ predicate });
  queryClient.removeQueries({ predicate });
}
