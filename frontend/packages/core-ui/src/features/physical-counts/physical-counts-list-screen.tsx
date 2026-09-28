import { useEffect, useMemo, useState } from 'react';
import { SlidersHorizontal } from 'lucide-react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import { useWarehousesIndex, usePhysicalCountsIndex, type PhysicalCountResource, type PhysicalCountsIndexSort } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Combobox } from '../../components/ui/combobox';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns, isColumnVisibleByDefault } from '../../components/data-table';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useListViewPreference } from '../../lib/use-view-preference';
import { physicalCountColumns } from './physical-count-columns';

interface PhysicalCountFilters {
  search: string;
  warehouse_id: string;
  status: string;
  [key: string]: string;
}

const STATUSES = ['DRAFT', 'POSTED', 'CANCELLED'] as const;

export function PhysicalCountsListScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<PhysicalCountFilters>({ defaultSort: '-created_at', defaultPerPage: 25 });
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    'physical-counts-list',
    Object.fromEntries(physicalCountColumns.map((column) => [column.key, isColumnVisibleByDefault(column)]))
  );
  const [filtersOpen, setFiltersOpen] = useState(false);
  const { view, setView } = useListViewPreference('physical-counts-list');
  const [searchInput, setSearchInput] = useState(query.filters.search ?? '');

  useEffect(() => {
    const handle = setTimeout(() => {
      if (searchInput !== (query.filters.search ?? '')) query.setFilter('search', searchInput || undefined);
    }, 300);
    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  const warehousesQuery = useWarehousesIndex({ per_page: 100, sort: 'name' });
  const warehouses = warehousesQuery.data?.status === 200 ? warehousesQuery.data.data.data : [];

  const countsQuery = usePhysicalCountsIndex({
    'filter[warehouse_id]': query.filters.warehouse_id ? Number(query.filters.warehouse_id) : undefined,
    'filter[status]': (query.filters.status as 'DRAFT' | 'POSTED' | 'CANCELLED' | undefined) || undefined,
    'filter[search]': query.filters.search || undefined,
    sort: query.sort as PhysicalCountsIndexSort | undefined,
    include: 'warehouse',
    per_page: query.perPage,
    page: query.page,
  });

  const counts = countsQuery.data?.status === 200 ? countsQuery.data.data.data : [];
  const meta = countsQuery.data?.status === 200 ? countsQuery.data.data.meta : undefined;

  const tanStackColumns = useMemo(() => toTanStackColumns(physicalCountColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, physicalCountColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const activeFilterCount = [query.filters.warehouse_id, query.filters.status].filter(Boolean).length;

  return (
    // Extra bottom padding below `md` clears the fixed MobileFab — see
    // PageHeader's `primaryAction` note in docs/architecture/user-interface.md.
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Physical Counts"
        description="Reconcile recorded stock against what's physically on hand, and post the difference as an auditable correction."
        primaryAction={{ label: 'New count', onClick: () => navigate('/physical-counts/new') }}
      />

      <div className="flex flex-wrap items-center gap-2">
        <Input
          placeholder="Search reference…"
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          className="h-9 flex-1 md:w-56 md:flex-none"
        />
        <div className="hidden items-center gap-2 md:flex">
          <PhysicalCountFilterControls warehouses={warehouses} query={query} />
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
            <PhysicalCountFilterControls warehouses={warehouses} query={query} full />
          </div>
          <SheetFooter>
            <Button variant="ghost" onClick={() => query.setFilters({ warehouse_id: undefined, status: undefined })}>
              Clear filters
            </Button>
            <Button onClick={() => setFiltersOpen(false)}>Done</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>

      <DataTable
        columns={tanStackColumns}
        data={counts}
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
          query.setSort(tableSortingToApiSort(next, physicalCountColumns));
        }}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        onRowClick={(count: PhysicalCountResource) => navigate(`/physical-counts/${count.id}`)}
        isLoading={countsQuery.isLoading}
        isError={countsQuery.isError}
        error={countsQuery.error}
        onRetry={() => void countsQuery.refetch()}
        errorMessage="Couldn't load physical counts."
        emptyMessage="No physical counts match these filters."
        view={view}
        onViewChange={setView}
        cardColumns={physicalCountColumns}
      />
    </div>
  );
}

interface PhysicalCountFilterControlsProps {
  warehouses: { id: number; name: string }[];
  query: ReturnType<typeof useListQueryState<PhysicalCountFilters>>;
  full?: boolean;
}

/** Warehouse/Status selects — shared by the always-visible desktop row and the mobile Filters sheet. */
function PhysicalCountFilterControls({ warehouses, query, full }: PhysicalCountFilterControlsProps) {
  return (
    <>
      <Combobox
        className={full ? 'h-9 w-full' : 'h-9 w-44'}
        aria-label="Warehouse"
        value={query.filters.warehouse_id ?? 'all'}
        onValueChange={(value) => query.setFilter('warehouse_id', value === 'all' ? undefined : value)}
        placeholder="Warehouse"
        searchPlaceholder="Search warehouses…"
        emptyText="No warehouses found."
        options={[
          { value: 'all', label: 'All warehouses' },
          ...warehouses.map((warehouse) => ({ value: String(warehouse.id), label: warehouse.name })),
        ]}
      />
      <Select value={query.filters.status ?? 'all'} onValueChange={(value) => query.setFilter('status', value === 'all' ? undefined : value)}>
        <SelectTrigger className={full ? 'h-9 w-full' : 'h-9 w-36'} aria-label="Status">
          <SelectValue placeholder="Status" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All statuses</SelectItem>
          {STATUSES.map((status) => (
            <SelectItem key={status} value={status}>
              {status}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </>
  );
}
