import { useEffect, useMemo, useState } from 'react';
import { SlidersHorizontal } from 'lucide-react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import {
  useRecalculationRequestsIndex,
  type RecalculationRequestResource,
  type RecalculationRequestsIndexSort,
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
import { recalculationRequestColumns } from './recalculation-request-columns';

interface RecalculationRequestFilters {
  search: string;
  movement_kind: string;
  [key: string]: string;
}

const MOVEMENT_KINDS = ['RECEIPT', 'ISSUE', 'TRANSFER'] as const;

export function RecalculationRequestsListScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<RecalculationRequestFilters>({ defaultSort: '-requested_at', defaultPerPage: 25 });
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    'recalculation-requests-list',
    Object.fromEntries(recalculationRequestColumns.map((column) => [column.key, isColumnVisibleByDefault(column)]))
  );
  const [filtersOpen, setFiltersOpen] = useState(false);
  const { view, setView } = useListViewPreference('recalculation-requests-list');
  const [searchInput, setSearchInput] = useState(query.filters.search ?? '');

  useEffect(() => {
    const handle = setTimeout(() => {
      if (searchInput !== (query.filters.search ?? '')) query.setFilter('search', searchInput || undefined);
    }, 300);
    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  const requestsQuery = useRecalculationRequestsIndex({
    'filter[movement_kind]': (query.filters.movement_kind as (typeof MOVEMENT_KINDS)[number] | undefined) || undefined,
    'filter[search]': query.filters.search || undefined,
    sort: query.sort as RecalculationRequestsIndexSort | undefined,
    include: 'item',
    per_page: query.perPage,
    page: query.page,
  });

  const requests = requestsQuery.data?.status === 200 ? requestsQuery.data.data.data : [];
  const meta = requestsQuery.data?.status === 200 ? requestsQuery.data.data.meta : undefined;

  const tanStackColumns = useMemo(() => toTanStackColumns(recalculationRequestColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, recalculationRequestColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const activeFilterCount = [query.filters.movement_kind].filter(Boolean).length;

  return (
    // Extra bottom padding below `md` clears the fixed MobileFab — see
    // PageHeader's `primaryAction` note in docs/architecture/user-interface.md.
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Stock Recalculation"
        description="ADR 0040's backdated correction workflow — request, decide, and execute a correction for a receipt, issue or transfer that conflicts with later valuation history."
        primaryAction={{ label: 'New request', onClick: () => navigate('/recalculation-requests/new') }}
      />

      <div className="flex flex-wrap items-center gap-2">
        <Input
          placeholder="Search item…"
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          className="h-9 flex-1 md:w-56 md:flex-none"
        />
        <div className="hidden items-center gap-2 md:flex">
          <MovementKindFilterControls query={query} />
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
            <MovementKindFilterControls query={query} full />
          </div>
          <SheetFooter>
            <Button variant="ghost" onClick={() => query.setFilters({ movement_kind: undefined })}>
              Clear filters
            </Button>
            <Button onClick={() => setFiltersOpen(false)}>Done</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>

      <DataTable
        columns={tanStackColumns}
        data={requests}
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
          query.setSort(tableSortingToApiSort(next, recalculationRequestColumns));
        }}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        onRowClick={(request: RecalculationRequestResource) => navigate(`/recalculation-requests/${request.id}`)}
        isLoading={requestsQuery.isLoading}
        isError={requestsQuery.isError}
        error={requestsQuery.error}
        onRetry={() => void requestsQuery.refetch()}
        errorMessage="Couldn't load stock recalculation requests."
        emptyMessage="No stock recalculation requests match these filters."
        view={view}
        onViewChange={setView}
        cardColumns={recalculationRequestColumns}
      />
    </div>
  );
}

interface MovementKindFilterControlsProps {
  query: ReturnType<typeof useListQueryState<RecalculationRequestFilters>>;
  full?: boolean;
}

/** Movement kind select — shared by the always-visible desktop row and the mobile Filters sheet. */
function MovementKindFilterControls({ query, full }: MovementKindFilterControlsProps) {
  return (
    <Select
      value={query.filters.movement_kind ?? 'all'}
      onValueChange={(value) => query.setFilter('movement_kind', value === 'all' ? undefined : value)}
    >
      <SelectTrigger className={full ? 'h-9 w-full' : 'h-9 w-44'} aria-label="Movement kind">
        <SelectValue placeholder="Movement kind" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="all">All movement kinds</SelectItem>
        {MOVEMENT_KINDS.map((kind) => (
          <SelectItem key={kind} value={kind}>
            {kind}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
