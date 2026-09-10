import { useMemo, useState } from 'react';
import { SlidersHorizontal } from 'lucide-react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import { useInventoryCutoversIndex, type InventoryCutoverResource, type InventoryCutoversIndexSort } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns, isColumnVisibleByDefault } from '../../components/data-table';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useListViewPreference } from '../../lib/use-view-preference';
import { inventoryCutoverColumns } from './inventory-cutover-columns';

interface InventoryCutoverFilters {
  status: string;
  [key: string]: string;
}

const STATUSES = ['DRAFT', 'VALIDATED', 'APPROVED', 'FINALISED', 'FAILED'] as const;

export function InventoryCutoversListScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<InventoryCutoverFilters>({ defaultSort: '-created_at', defaultPerPage: 25 });
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    'inventory-cutovers-list',
    Object.fromEntries(inventoryCutoverColumns.map((column) => [column.key, isColumnVisibleByDefault(column)]))
  );
  const [filtersOpen, setFiltersOpen] = useState(false);
  const { view, setView } = useListViewPreference('inventory-cutovers-list');

  const cutoversQuery = useInventoryCutoversIndex({
    'filter[status]': (query.filters.status as 'DRAFT' | 'VALIDATED' | 'APPROVED' | 'FINALISED' | 'FAILED' | undefined) || undefined,
    sort: query.sort as InventoryCutoversIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });

  const cutovers = cutoversQuery.data?.status === 200 ? cutoversQuery.data.data.data : [];
  const meta = cutoversQuery.data?.status === 200 ? cutoversQuery.data.data.meta : undefined;

  const tanStackColumns = useMemo(() => toTanStackColumns(inventoryCutoverColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, inventoryCutoverColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const activeFilterCount = [query.filters.status].filter(Boolean).length;

  return (
    // Extra bottom padding below `md` clears the fixed MobileFab — see
    // PageHeader's `primaryAction` note in docs/architecture/user-interface.md.
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Inventory Cutovers"
        description="Bring in a predecessor system's opening stock balances under a controlled, auditable workflow."
        primaryAction={{ label: 'New cutover', onClick: () => navigate('/inventory-cutovers/new') }}
      />

      <div className="flex flex-wrap items-center gap-2">
        <div className="hidden items-center gap-2 md:flex">
          <CutoverFilterControls query={query} />
        </div>
        <Button
          variant="outline"
          size="sm"
          className="ml-auto h-9 shrink-0 md:hidden"
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
            <CutoverFilterControls query={query} full />
          </div>
          <SheetFooter>
            <Button variant="ghost" onClick={() => query.setFilters({ status: undefined })}>
              Clear filters
            </Button>
            <Button onClick={() => setFiltersOpen(false)}>Done</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>

      <DataTable
        columns={tanStackColumns}
        data={cutovers}
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
          query.setSort(tableSortingToApiSort(next, inventoryCutoverColumns));
        }}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        onRowClick={(cutover: InventoryCutoverResource) => navigate(`/inventory-cutovers/${cutover.id}`)}
        isLoading={cutoversQuery.isLoading}
        isError={cutoversQuery.isError}
        errorMessage="Couldn't load inventory cutovers."
        emptyMessage="No inventory cutovers match these filters."
        view={view}
        onViewChange={setView}
        cardColumns={inventoryCutoverColumns}
      />
    </div>
  );
}

interface CutoverFilterControlsProps {
  query: ReturnType<typeof useListQueryState<InventoryCutoverFilters>>;
  full?: boolean;
}

/** Status select — shared by the always-visible desktop row and the mobile Filters sheet. */
function CutoverFilterControls({ query, full }: CutoverFilterControlsProps) {
  return (
    <Select value={query.filters.status ?? 'all'} onValueChange={(value) => query.setFilter('status', value === 'all' ? undefined : value)}>
      <SelectTrigger className={full ? 'h-9 w-full' : 'h-9 w-40'}>
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
  );
}
