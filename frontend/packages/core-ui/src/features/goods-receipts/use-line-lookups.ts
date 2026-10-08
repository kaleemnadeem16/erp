import { useQueries } from '@tanstack/react-query';
import { getItemsShowQueryOptions, getLocationsShowQueryOptions, getUomsShowQueryOptions } from '@erp/core-api';

/**
 * A receipt's lines refer to items, units and locations by id. A page of a list is not a lookup table: past the page size the referenced record is simply
 * absent. These fetch exactly the records the lines name (cached by id), and are merged over whatever list the screen already holds.
 */
function useByIds<T extends { id: number }>(
  ids: number[],
  options: (id: number) => object,
  known: T[],
): T[] {
  const missing = [...new Set(ids)].filter((id) => !known.some((record) => record.id === id));
  const results = useQueries({ queries: missing.map((id) => ({ ...(options(id) as object), retry: false }) as never) }) as Array<{ data?: unknown }>;
  const fetched = results
    .map((result) => (result.data as { status: number; data: { data: T } } | undefined))
    .filter((response): response is { status: number; data: { data: T } } => response?.status === 200)
    .map((response) => response.data.data);

  return [...known, ...fetched];
}

export const useItemsById = <T extends { id: number }>(ids: number[], known: T[]) => useByIds<T>(ids, (id) => getItemsShowQueryOptions(id), known);
export const useUomsById = <T extends { id: number }>(ids: number[], known: T[]) => useByIds<T>(ids, (id) => getUomsShowQueryOptions(id), known);
export const useLocationsById = <T extends { id: number }>(ids: number[], known: T[]) => useByIds<T>(ids, (id) => getLocationsShowQueryOptions(id), known);
