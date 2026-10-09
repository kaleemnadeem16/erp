import { useRef } from 'react';
import { useQueries } from '@tanstack/react-query';
import { getItemsShowQueryOptions, getLocationsShowQueryOptions, getSupplierAccountsShowQueryOptions, getUomsShowQueryOptions, getWarehousesShowQueryOptions } from '@erp/core-api';

/**
 * A record a screen already knows by id (a line's item, a document's supplier, a filter's chosen location) must be resolved by that id. A page of a list is
 * not a lookup table: past the page size the record is simply absent, and `list.find(...)` silently returns nothing. These fetch exactly the records named
 * (cached per id by react-query) and merge them over whatever list the screen already holds, so existing `find` code keeps working and is now complete.
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
  const combined = [...known, ...fetched];

  // The same records must give the same array. Callers use the result in effect dependencies (an effect that writes form values on every new array would
  // re-run on every render), so keep the previous array while its records are the very same objects.
  const previous = useRef<T[]>(combined);
  const same = previous.current.length === combined.length && previous.current.every((record, index) => record === combined[index]);
  if (!same) previous.current = combined;

  return previous.current;
}

export const useItemsById = <T extends { id: number }>(ids: number[], known: T[]) => useByIds<T>(ids, (id) => getItemsShowQueryOptions(id), known);
export const useUomsById = <T extends { id: number }>(ids: number[], known: T[]) => useByIds<T>(ids, (id) => getUomsShowQueryOptions(id), known);
export const useLocationsById = <T extends { id: number }>(ids: number[], known: T[]) => useByIds<T>(ids, (id) => getLocationsShowQueryOptions(id, { include: 'warehouse' }), known);
export const useSuppliersById = <T extends { id: number }>(ids: number[], known: T[]) => useByIds<T>(ids, (id) => getSupplierAccountsShowQueryOptions(id, { include: 'party' }), known);
export const useWarehousesById = <T extends { id: number }>(ids: number[], known: T[]) => useByIds<T>(ids, (id) => getWarehousesShowQueryOptions(id), known);
