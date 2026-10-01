import { MutationCache, QueryClient } from '@tanstack/react-query';

import { shouldRetryQuery } from './api-errors';

/**
 * Mutations that only *compute* an answer (a POST used as a question) and change nothing stored.
 * They must not refresh the app's data: nothing changed, and a refresh would only add load.
 * Keys are the generated hooks' mutation keys.
 */
const READ_ONLY_MUTATIONS = new Set(['assembliesMaterialPreview']);

/**
 * The app's one QueryClient, with the rule that keeps every screen honest: after any change is saved, whatever is on
 * screen is refetched. Without it a list kept mounted behind a side sheet (the purchase-order list behind an order)
 * kept showing the old status after the order was issued, cancelled or edited — and so would every similar pair
 * (a list behind its detail, a dashboard, a lookup). Active queries refetch now; others are marked stale and refetch
 * the next time they are shown. The mutation stays pending until that refetch finishes, so whatever the caller does
 * next (close the sheet, navigate) already sees fresh data.
 */
export function createAppQueryClient(): QueryClient {
  const queryClient: QueryClient = new QueryClient({
    defaultOptions: { queries: { retry: shouldRetryQuery } },
    mutationCache: new MutationCache({
      onSuccess: (_data, _variables, _context, mutation) => {
        const key = mutation.options.mutationKey?.[0];
        if (typeof key === 'string' && READ_ONLY_MUTATIONS.has(key)) return;
        return queryClient.invalidateQueries();
      },
    }),
  });
  return queryClient;
}
