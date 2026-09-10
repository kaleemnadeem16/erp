import { useEffect, useMemo, useState } from 'react';
import { SlidersHorizontal } from 'lucide-react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import {
  useNegativeStockOverrideRequestsIndex,
  type NegativeStockOverrideRequestResource,
  type NegativeStockOverrideRequestsIndexSort,
} from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns, isColumnVisibleByDefault } from '../../components/data-table';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useListViewPreference } from '../../lib/use-view-preference';
import { negativeStockOverrideColumns } from './negative-stock-override-columns';

interface NegativeStockOverrideFilters {
  search: string;
  movement_class: string;
  authorisation_mode: string;
  [key: string]: string;
}

const MOVEMENT_CLASSES = ['SALES_DESPATCH', 'MATERIAL_CONSUMPTION', 'SUPPLIER_RETURN', 'SCRAP_WRITEOFF'] as const;
const AUTHORISATION_MODES = ['PRIOR_APPROVAL', 'IMMEDIATE_WITH_REVIEW'] as const;

export function NegativeStockOverridesListScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<NegativeStockOverrideFilters>({ defaultSort: '-requested_at', defaultPerPage: 25 });
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    'negative-stock-overrides-list',
    Object.fromEntries(negativeStockOverrideColumns.map((column) => [column.key, isColumnVisibleByDefault(column)]))
  );
  const [filtersOpen, setFiltersOpen] = useState(false);
  const { view, setView } = useListViewPreference('negative-stock-overrides-list');
  const [searchInput, setSearchInput] = useState(query.filters.search ?? '');

  useEffect(() => {
    const handle = setTimeout(() => {
      if (searchInput !== (query.filters.search ?? '')) query.setFilter('search', searchInput || undefined);
    }, 300);
    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  const overridesQuery = useNegativeStockOverrideRequestsIndex({
    'filter[movement_class]': (query.filters.movement_class as (typeof MOVEMENT_CLASSES)[number] | undefined) || undefined,
    'filter[authorisation_mode]': (query.filters.authorisation_mode as (typeof AUTHORISATION_MODES)[number] | undefined) || undefined,
    'filter[search]': query.filters.search || undefined,
    sort: query.sort as NegativeStockOverrideRequestsIndexSort | undefined,
    include: 'item',
    per_page: query.perPage,
    page: query.page,
  });

  const overrides = overridesQuery.data?.status === 200 ? overridesQuery.data.data.data : [];
  const meta = overridesQuery.data?.status === 200 ? overridesQuery.data.data.meta : undefined;

  const tanStackColumns = useMemo(() => toTanStackColumns(negativeStockOverrideColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, negativeStockOverrideColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const activeFilterCount = [query.filters.movement_class, query.filters.authorisation_mode].filter(Boolean).length;

  return (
    // Extra bottom padding below `md` clears the fixed MobileFab — see
    // PageHeader's `primaryAction` note in docs/architecture/user-interface.md.
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Negative-Stock Overrides"
        description="Authorisation queue for a genuine shortage — requested, then decided or reviewed depending on company policy."
        primaryAction={{ label: 'New request', onClick: () => navigate('/negative-stock-overrides/new') }}
      />

      <div className="flex flex-wrap items-center gap-2">
        <Input
          placeholder="Search item…"
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          className="h-9 flex-1 md:w-56 md:flex-none"
        />
        <div className="hidden items-center gap-2 md:flex">
          <OverrideFilterControls query={query} />
        </div>
        <Button
          variant="outline"
          size="sm"
          className="h-9 shrink-0 md:hidden"
          onClick={() => setFiltersOpen(true)}
        >
          <SlidersHorizontal className="h-4 w-4" />
          Filters
          {activeFilterCount > 0 && <Badge className="ml-1">{activeFilterCount}</Badge>}
        </Button>
      </div>

      <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
        <SheetContent side="bottom" className="md:hidden">
          <SheetHeader>
            <SheetTitle>Filters</SheetTitle>
          </SheetHeader>
          <div className="flex flex-col gap-3 py-4">
            <OverrideFilterControls query={query} full />
          </div>
          <SheetFooter>
            <Button
              variant="ghost"
              onClick={() => query.setFilters({ movement_class: undefined, authorisation_mode: undefined })}
            >
              Clear filters
            </Button>
            <Button onClick={() => setFiltersOpen(false)}>Done</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>

      <DataTable
        columns={tanStackColumns}
        data={overrides}
        rowCount={meta?.total ?? 0}
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next = typeof updater === 'function' ? updater(pagination) : updater;
          if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(updater) => {
          const next = typeof updater === 'function' ? updater(sorting) : updater;
          query.setSort(tableSortingToApiSort(next, negativeStockOverrideColumns));
        }}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        onRowClick={(override: NegativeStockOverrideRequestResource) => navigate(`/negative-stock-overrides/${override.id}`)}
        isLoading={overridesQuery.isLoading}
        isError={overridesQuery.isError}
        errorMessage="Couldn't load negative-stock override requests."
        emptyMessage="No negative-stock override requests match these filters."
        view={view}
        onViewChange={setView}
        cardColumns={negativeStockOverrideColumns}
      />
    </div>
  );
}

interface OverrideFilterControlsProps {
  query: ReturnType<typeof useListQueryState<NegativeStockOverrideFilters>>;
  full?: boolean;
}

/** Movement class/Authorisation mode selects — shared by the desktop row and the mobile Filters sheet. */
function OverrideFilterControls({ query, full }: OverrideFilterControlsProps) {
  return (
    <>
      <Select
        value={query.filters.movement_class ?? 'all'}
        onValueChange={(value) => query.setFilter('movement_class', value === 'all' ? undefined : value)}
      >
        <SelectTrigger className={full ? 'h-9 w-full' : 'h-9 w-52'}>
          <SelectValue placeholder="Movement class" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All movement classes</SelectItem>
          {MOVEMENT_CLASSES.map((movementClass) => (
            <SelectItem key={movementClass} value={movementClass}>
              {movementClass}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      <Select
        value={query.filters.authorisation_mode ?? 'all'}
        onValueChange={(value) => query.setFilter('authorisation_mode', value === 'all' ? undefined : value)}
      >
        <SelectTrigger className={full ? 'h-9 w-full' : 'h-9 w-48'}>
          <SelectValue placeholder="Authorisation mode" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All modes</SelectItem>
          {AUTHORISATION_MODES.map((mode) => (
            <SelectItem key={mode} value={mode}>
              {mode}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </>
  );
}
