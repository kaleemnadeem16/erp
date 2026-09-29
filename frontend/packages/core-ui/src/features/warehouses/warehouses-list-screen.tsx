import { useEffect, useMemo, useState } from 'react';
import { SlidersHorizontal } from 'lucide-react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import { useWarehousesIndex, type WarehouseResource, type WarehousesIndexSort } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, isColumnVisibleByDefault, toTanStackColumns } from '../../components/data-table';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import {
  apiSortToTableSorting,
  describeSort,
  tableSortingToApiSort,
  useListQueryState,
} from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useListViewPreference } from '../../lib/use-view-preference';
import { warehouseColumns } from './warehouses-columns';
import { usePermission } from '../../hooks/use-permission';

interface WarehouseFilters {
  active: string;
  search: string;
  [key: string]: string;
}

export function WarehousesListScreen() {
  const navigate = useNavigate();
  const mayManage = usePermission('inventory.warehouse.manage');
  const query = useListQueryState<WarehouseFilters>({
    defaultSort: 'name',
    defaultPerPage: 25,
  });
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    'warehouses-list',
    Object.fromEntries(warehouseColumns.map((column) => [column.key, isColumnVisibleByDefault(column)]))
  );
  const [searchInput, setSearchInput] = useState(query.filters.search ?? '');
  const [filtersOpen, setFiltersOpen] = useState(false);
  const { view, setView } = useListViewPreference('warehouses-list');

  useEffect(() => {
    const handle = setTimeout(() => {
      if (searchInput !== (query.filters.search ?? '')) query.setFilter('search', searchInput || undefined);
    }, 300);

    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  const warehousesQuery = useWarehousesIndex({
    'filter[active]': query.filters.active ? query.filters.active === 'true' : undefined,
    'filter[search]': query.filters.search || undefined,
    sort: query.sort as WarehousesIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const warehouses = warehousesQuery.data?.status === 200 ? warehousesQuery.data.data.data : [];
  const meta = warehousesQuery.data?.status === 200 ? warehousesQuery.data.data.meta : undefined;

  const columns = useMemo(() => toTanStackColumns(warehouseColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, warehouseColumns);
  const pagination: PaginationState = {
    pageIndex: query.page - 1,
    pageSize: query.perPage,
  };
  const { contentRef: printRef, print } = useQuickPrint({
    documentTitle: 'Warehouse List',
  });
  const filters = [
    ...(query.filters.active ? [`Status = ${query.filters.active === 'true' ? 'Active' : 'Inactive'}`] : []),
    ...(query.filters.search ? [`Search = "${query.filters.search}"`] : []),
  ];
  const activeFilterCount = [query.filters.active].filter(Boolean).length;

  return (
    // Extra bottom padding below `md` clears the fixed MobileFab — see
    // PageHeader's `primaryAction` note in docs/architecture/user-interface.md.
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Warehouses"
        description="Places where the company receives, stores and dispatches stock."
        primaryAction={mayManage ? { label: 'New warehouse', onClick: () => navigate('/warehouses/new') } : undefined}
      />

      <div className="flex flex-wrap items-center gap-2">
        <Input
          placeholder="Search code or name…"
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          className="h-9 flex-1 md:w-56 md:flex-none"
        />
        <div className="hidden items-center gap-2 md:flex">
          <WarehouseFilterControls query={query} />
          <PrintTrigger onPrint={() => print()} />
        </div>
        <Button variant="outline" size="sm" className="h-9 shrink-0 md:hidden" onClick={() => setFiltersOpen(true)}>
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
            <WarehouseFilterControls query={query} full />
            <PrintTrigger onPrint={() => print()} className="w-full justify-center" />
          </div>
          <SheetFooter>
            <Button variant="ghost" onClick={() => query.setFilters({ active: undefined })}>
              Clear filters
            </Button>
            <Button onClick={() => setFiltersOpen(false)}>Done</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>

      <DataTable
        columns={columns}
        data={warehouses}
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
          query.setSort(tableSortingToApiSort(next, warehouseColumns));
        }}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        onRowClick={(warehouse: WarehouseResource) => navigate(`/warehouses/${warehouse.id}`)}
        isLoading={warehousesQuery.isLoading}
        isError={warehousesQuery.isError}
        error={warehousesQuery.error}
        onRetry={() => void warehousesQuery.refetch()}
        errorMessage="Couldn't load warehouses."
        emptyMessage="No warehouses match these filters."
        view={view}
        onViewChange={setView}
        cardColumns={warehouseColumns}
      />

      <div className="hidden">
        <div ref={printRef}>
          <PrintLayout
            title="WAREHOUSE LIST"
            printedAt={new Date()}
            recordCount={warehouses.length}
            meta={[
              ...(filters.length ? [{ label: 'Filters', value: filters.join(', ') }] : []),
              ...(query.sort
                ? [
                    {
                      label: 'Sort',
                      value: describeSort(query.sort, warehouseColumns) ?? query.sort,
                    },
                  ]
                : []),
            ]}
          >
            <PrintableTable columns={warehouseColumns} rows={warehouses} getRowKey={(warehouse) => warehouse.id} />
          </PrintLayout>
        </div>
      </div>
    </div>
  );
}

interface WarehouseFilterControlsProps {
  query: ReturnType<typeof useListQueryState<WarehouseFilters>>;
  full?: boolean;
}

/** Status select — shared by the always-visible desktop row and the mobile Filters sheet. */
function WarehouseFilterControls({ query, full }: WarehouseFilterControlsProps) {
  return (
    <Select
      value={query.filters.active ?? 'all'}
      onValueChange={(value) => query.setFilter('active', value === 'all' ? undefined : value)}
    >
      <SelectTrigger className={full ? 'h-9 w-full' : 'h-9 w-36'} aria-label="Status">
        <SelectValue placeholder="Status" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="all">All statuses</SelectItem>
        <SelectItem value="true">Active</SelectItem>
        <SelectItem value="false">Inactive</SelectItem>
      </SelectContent>
    </Select>
  );
}
