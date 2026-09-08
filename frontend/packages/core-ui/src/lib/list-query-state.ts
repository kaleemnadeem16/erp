import { useState } from 'react';
import { useSearchParams } from 'react-router';
import type { SortingState } from '@tanstack/react-table';

import type { DataColumn } from '../components/data-table/types';

/**
 * URL-persisted state for one list screen: page, per_page, sort and filters
 * (`filter.<key>`) — the exact shape docs/architecture/api-conventions.md
 * defines for every collection endpoint, so this hook is reusable across
 * every master-data list (Items, Warehouses, Categories, Item Suppliers, …),
 * not specific to one screen. Persisting to the URL means a filtered/sorted/
 * paginated view survives a refresh, a back-navigation and a shared link —
 * and it's the single source of truth Quick Print reads its "Filters:"/
 * "Sort:" line from.
 */
export interface ListQueryState<TFilters extends Record<string, string>> {
  page: number;
  perPage: number;
  sort?: string;
  filters: Partial<TFilters>;
  setPage: (page: number) => void;
  setPerPage: (perPage: number) => void;
  setSort: (sort: string | undefined) => void;
  setFilter: (key: keyof TFilters, value: string | undefined) => void;
  setFilters: (filters: Partial<TFilters>) => void;
  clearFilters: () => void;
}

export function useListQueryState<TFilters extends Record<string, string>>(options?: {
  defaultSort?: string;
  defaultPerPage?: number;
}): ListQueryState<TFilters> {
  const [searchParams, setSearchParams] = useSearchParams();

  const page = Math.max(1, Number(searchParams.get('page') ?? '1') || 1);
  const perPage = Number(searchParams.get('per_page') ?? options?.defaultPerPage ?? 25);
  const sort = searchParams.get('sort') ?? options?.defaultSort;
  const filters = {} as Partial<TFilters>;
  for (const [key, value] of searchParams.entries()) {
    if (key.startsWith('filter.')) {
      (filters as Record<string, string>)[key.slice('filter.'.length)] = value;
    }
  }

  function patch(next: {
    page?: number;
    perPage?: number;
    sort?: string | undefined;
    filters?: Partial<TFilters>;
    resetPage?: boolean;
  }) {
    setSearchParams(
      (prev) => {
        const params = new URLSearchParams(prev);
        if (next.perPage !== undefined) params.set('per_page', String(next.perPage));
        if (next.sort !== undefined) {
          if (next.sort) params.set('sort', next.sort);
          else params.delete('sort');
        }
        if (next.filters) {
          for (const [key, value] of Object.entries(next.filters)) {
            if (value === undefined || value === '') params.delete(`filter.${key}`);
            else params.set(`filter.${key}`, String(value));
          }
        }
        if (next.resetPage !== false && (next.perPage !== undefined || next.filters !== undefined)) {
          params.set('page', '1');
        } else if (next.page !== undefined) {
          params.set('page', String(next.page));
        }
        return params;
      },
      { replace: true }
    );
  }

  return {
    page,
    perPage,
    sort,
    filters,
    setPage: (value) => patch({ page: value, resetPage: false }),
    setPerPage: (value) => patch({ perPage: value }),
    setSort: (value) => patch({ sort: value, resetPage: false }),
    setFilter: (key, value) => patch({ filters: { [key]: value } as Partial<TFilters> }),
    setFilters: (value) => patch({ filters: value }),
    clearFilters: () => patch({ filters: Object.fromEntries(Object.keys(filters).map((key) => [key, undefined])) as Partial<TFilters> }),
  };
}

/**
 * The cursor-paginated sibling of `useListQueryState`, for lists too large or
 * too frequently appended to for `page`/`total` to make sense (the stock
 * ledger first — see docs/architecture/api-conventions.md#pagination). Filters,
 * sort and per_page are still URL-persisted the same way; the cursor itself
 * is not, since a cursor is an opaque, short-lived token that doesn't make a
 * meaningful bookmark. Instead this keeps a back-stack of visited cursors in
 * memory so Previous/Next can page both ways — changing any filter, sort or
 * per_page resets to the first page, matching `useListQueryState`.
 */
export interface CursorQueryState<TFilters extends Record<string, string>> {
  cursor?: string;
  perPage: number;
  sort?: string;
  filters: Partial<TFilters>;
  canGoBack: boolean;
  setPerPage: (perPage: number) => void;
  setSort: (sort: string | undefined) => void;
  setFilter: (key: keyof TFilters, value: string | undefined) => void;
  goToNextCursor: (nextCursor: string | null | undefined) => void;
  goBack: () => void;
}

export function useCursorQueryState<TFilters extends Record<string, string>>(options?: {
  defaultSort?: string;
  defaultPerPage?: number;
}): CursorQueryState<TFilters> {
  const [searchParams, setSearchParams] = useSearchParams();
  const [cursorStack, setCursorStack] = useState<string[]>([]);

  const perPage = Number(searchParams.get('per_page') ?? options?.defaultPerPage ?? 25);
  const sort = searchParams.get('sort') ?? options?.defaultSort;
  const filters = {} as Partial<TFilters>;
  for (const [key, value] of searchParams.entries()) {
    if (key.startsWith('filter.')) {
      (filters as Record<string, string>)[key.slice('filter.'.length)] = value;
    }
  }

  function patch(next: { perPage?: number; sort?: string | undefined; filters?: Partial<TFilters> }) {
    setCursorStack([]);
    setSearchParams(
      (prev) => {
        const params = new URLSearchParams(prev);
        if (next.perPage !== undefined) params.set('per_page', String(next.perPage));
        if (next.sort !== undefined) {
          if (next.sort) params.set('sort', next.sort);
          else params.delete('sort');
        }
        if (next.filters) {
          for (const [key, value] of Object.entries(next.filters)) {
            if (value === undefined || value === '') params.delete(`filter.${key}`);
            else params.set(`filter.${key}`, String(value));
          }
        }
        return params;
      },
      { replace: true }
    );
  }

  return {
    cursor: cursorStack.at(-1),
    perPage,
    sort,
    filters,
    canGoBack: cursorStack.length > 0,
    setPerPage: (value) => patch({ perPage: value }),
    setSort: (value) => patch({ sort: value }),
    setFilter: (key, value) => patch({ filters: { [key]: value } as Partial<TFilters> }),
    goToNextCursor: (nextCursor) => {
      if (nextCursor) setCursorStack((stack) => [...stack, nextCursor]);
    },
    goBack: () => setCursorStack((stack) => stack.slice(0, -1)),
  };
}

/** `sort=-created_at` ⇄ TanStack's `SortingState`, translated through each column's `sortKey`. */
export function apiSortToTableSorting<TRow>(sort: string | undefined, columns: DataColumn<TRow>[]): SortingState {
  if (!sort) return [];
  const desc = sort.startsWith('-');
  const field = desc ? sort.slice(1) : sort;
  const column = columns.find((c) => (c.sortKey ?? c.key) === field);
  return column ? [{ id: column.key, desc }] : [];
}

export function tableSortingToApiSort<TRow>(sorting: SortingState, columns: DataColumn<TRow>[]): string | undefined {
  const [first] = sorting;
  if (!first) return undefined;
  const column = columns.find((c) => c.key === first.id);
  const field = column?.sortKey ?? first.id;
  return first.desc ? `-${field}` : field;
}

/** A human-readable "Sort: Name ASC" line for Quick Print's meta block. */
export function describeSort<TRow>(sort: string | undefined, columns: DataColumn<TRow>[]): string | undefined {
  if (!sort) return undefined;
  const desc = sort.startsWith('-');
  const field = desc ? sort.slice(1) : sort;
  const column = columns.find((c) => (c.sortKey ?? c.key) === field);
  return `${column?.header ?? field} ${desc ? 'DESC' : 'ASC'}`;
}
