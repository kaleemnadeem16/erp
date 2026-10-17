import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Building2, CheckCircle2, MapPin, Search, SlidersHorizontal, X } from 'lucide-react';
import { useLocation, useNavigate } from 'react-router';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import { useWarehousesIndex, type WarehouseResource, type WarehousesIndexSort } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { Tooltip, TooltipContent, TooltipTrigger } from '../../components/ui/tooltip';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { DataTable, isColumnVisibleByDefault, selectionColumn, toTanStackColumns } from '../../components/data-table';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { cn } from '../../lib/utils';
import {
  apiSortToTableSorting,
  describeSort,
  tableSortingToApiSort,
  useListQueryState,
} from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useDebouncedSearch } from '../../lib/use-debounced-search';
import { useListViewPreference } from '../../lib/use-view-preference';
import { warehouseColumns } from './warehouses-columns';
import { WarehouseBulkBar } from './warehouse-bulk-actions';
import { usePermission } from '../../hooks/use-permission';

interface WarehouseFilters {
  active: string;
  is_default: string;
  has_locations: string;
  search: string;
  [key: string]: string;
}

export function WarehousesListScreen() {
  const navigate = useNavigate();
  const { search: listSearch } = useLocation();
  const mayManage = usePermission('inventory.warehouse.manage');
  const query = useListQueryState<WarehouseFilters>({
    defaultSort: 'name',
    defaultPerPage: 25,
  });

  const { input: searchInput, searchProps } = useDebouncedSearch(
    query.filters.search,
    (value) => query.setFilter('search', value),
  );

  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    'warehouses-list',
    Object.fromEntries(warehouseColumns.map((column) => [column.key, isColumnVisibleByDefault(column)]))
  );
  const [filtersOpen, setFiltersOpen] = useState(false);
  const { view, setView } = useListViewPreference('warehouses-list');
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});

  const warehousesQuery = useWarehousesIndex({
    'filter[active]':
      query.filters.active === 'true'
        ? true
        : query.filters.active === 'false'
        ? false
        : undefined,
    'filter[is_default]':
      query.filters.is_default === 'true'
        ? true
        : query.filters.is_default === 'false'
        ? false
        : undefined,
    'filter[has_locations]':
      query.filters.has_locations === 'true'
        ? true
        : query.filters.has_locations === 'false'
        ? false
        : undefined,
    'filter[search]': query.filters.search || undefined,
    include: 'locations',
    sort: query.sort as WarehousesIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const warehouses = warehousesQuery.data?.status === 200 ? warehousesQuery.data.data.data : [];
  const meta = warehousesQuery.data?.status === 200 ? warehousesQuery.data.data.meta : undefined;

  const selectedRows = useMemo(
    () => warehouses.filter((w) => rowSelection[String(w.id)]),
    [warehouses, rowSelection],
  );

  // Clear row selection when search or filters change (tracked via URL query string)
  useEffect(() => {
    setRowSelection({});
  }, [listSearch]);

  const columns = useMemo(() => toTanStackColumns(warehouseColumns), []);
  const tanStackColumns = useMemo(
    () => [selectionColumn<WarehouseResource>(), ...columns],
    [columns],
  );

  const sorting: SortingState = apiSortToTableSorting(query.sort, warehouseColumns);
  const pagination: PaginationState = {
    pageIndex: query.page - 1,
    pageSize: query.perPage,
  };

  const { contentRef: printRef, print } = useQuickPrint({
    documentTitle: 'Warehouse List',
  });
  const { contentRef: selectedPrintRef, print: printSelected } = useQuickPrint({
    documentTitle: 'Warehouse List (Selected)',
  });

  const activeFilterCount = [
    query.filters.active,
    query.filters.is_default,
    query.filters.has_locations,
  ].filter(Boolean).length;

  const activeChips = useMemo(() => {
    const chips: { id: string; icon: ReactNode; label: string; onRemove: () => void }[] = [];
    if (query.filters.search) {
      chips.push({
        id: 'search',
        icon: <Search className="h-3.5 w-3.5" />,
        label: `Search: "${query.filters.search}"`,
        onRemove: () => query.setFilter('search', undefined),
      });
    }
    if (query.filters.active) {
      chips.push({
        id: 'active',
        icon: <CheckCircle2 className="h-3.5 w-3.5" />,
        label: `Status: ${query.filters.active === 'true' ? 'Active' : 'Inactive'}`,
        onRemove: () => query.setFilter('active', undefined),
      });
    }
    if (query.filters.is_default) {
      chips.push({
        id: 'is_default',
        icon: <Building2 className="h-3.5 w-3.5" />,
        label: `Role: ${query.filters.is_default === 'true' ? 'Default only' : 'Non-default'}`,
        onRemove: () => query.setFilter('is_default', undefined),
      });
    }
    if (query.filters.has_locations) {
      chips.push({
        id: 'has_locations',
        icon: <MapPin className="h-3.5 w-3.5" />,
        label: `Locations: ${query.filters.has_locations === 'true' ? 'With locations' : 'No locations'}`,
        onRemove: () => query.setFilter('has_locations', undefined),
      });
    }
    return chips;
  }, [query.filters, query]);

  const clearAllFilters = () => {
    query.setFilters({
      active: undefined,
      is_default: undefined,
      has_locations: undefined,
      search: undefined,
    });
  };

  const filtersForPrint = [
    ...(query.filters.active ? [`Status = ${query.filters.active === 'true' ? 'Active' : 'Inactive'}`] : []),
    ...(query.filters.is_default ? [`Role = ${query.filters.is_default === 'true' ? 'Default only' : 'Non-default'}`] : []),
    ...(query.filters.has_locations ? [`Locations = ${query.filters.has_locations === 'true' ? 'With locations' : 'No locations'}`] : []),
    ...(query.filters.search ? [`Search = "${query.filters.search}"`] : []),
  ];

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Warehouses"
        description="Places where the company receives, stores and dispatches stock."
        primaryAction={
          mayManage
            ? {
                label: 'New warehouse',
                onClick: () => navigate({ pathname: '/warehouses/new', search: listSearch }),
              }
            : undefined
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <div className="relative flex-1 md:w-64 md:flex-none">
          <Search
            aria-hidden="true"
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
          />
          <Input
            className="h-10 pl-9 pr-9"
            placeholder="Search code or name…"
            aria-label="Search warehouses"
            {...searchProps}
          />
          {searchInput && (
            <button
              type="button"
              onClick={() => query.setFilter('search', undefined)}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer"
              aria-label="Clear search"
            >
              <X className="h-4 w-4" />
            </button>
          )}
        </div>
        <div className="hidden items-center gap-2 md:flex">
          <WarehouseFilterControls query={query} />
          <PrintTrigger onPrint={() => print()} />
        </div>
        <Button
          variant={filtersOpen || activeFilterCount > 0 ? 'secondary' : 'outline'}
          size="sm"
          className={cn(
            'relative h-10 px-3 shrink-0 md:hidden',
            (filtersOpen || activeFilterCount > 0) && 'ui-btn-filters-active',
          )}
          onClick={() => setFiltersOpen(true)}
          aria-label="Open filter settings"
        >
          <SlidersHorizontal className="h-4 w-4 mr-1.5" />
          Filters
          {activeFilterCount > 0 && <Badge className="ml-1.5 ui-filter-badge">{activeFilterCount}</Badge>}
        </Button>
      </div>

      <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
        <SheetContent side="bottom" className="md:hidden">
          <SheetHeader>
            <SheetTitle>Filters</SheetTitle>
          </SheetHeader>
          <div className="flex flex-col gap-3 py-4">
            <WarehouseFilterControls query={query} full />
            <PrintTrigger onPrint={() => print()} className="w-full justify-center h-10" />
          </div>
          <SheetFooter className="gap-2 sm:space-x-0">
            <Button
              variant="outline"
              onClick={() => {
                clearAllFilters();
                setFiltersOpen(false);
              }}
            >
              Reset filters
            </Button>
            <Button onClick={() => setFiltersOpen(false)}>Done</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>

      {/* Active Filter Chips */}
      {activeChips.length > 0 && (
        <div className="flex flex-wrap items-center gap-1.5 pt-1">
          <span className="text-xs font-medium text-muted-foreground mr-1">Active filters:</span>
          {activeChips.map((chip) => (
            <span
              key={chip.id}
              className="ui-chip-active inline-flex items-center gap-1.5 rounded-full border border-border bg-accent/50 px-2.5 py-1 text-xs text-foreground transition-colors hover:bg-accent"
            >
              <span className="text-muted-foreground">{chip.icon}</span>
              <span>{chip.label}</span>
              <Tooltip>
                <TooltipTrigger asChild>
                  <button
                    type="button"
                    onClick={chip.onRemove}
                    className="ml-0.5 rounded-full p-0.5 text-muted-foreground hover:bg-background hover:text-foreground cursor-pointer focus:outline-none focus:ring-1 focus:ring-ring"
                    aria-label={`Remove filter: ${chip.label}`}
                  >
                    <X className="h-3 w-3" />
                  </button>
                </TooltipTrigger>
                <TooltipContent side="top">Remove filter</TooltipContent>
              </Tooltip>
            </span>
          ))}
          <Button
            variant="ghost"
            size="sm"
            onClick={clearAllFilters}
            className="h-6 px-2 text-xs text-muted-foreground hover:text-foreground ml-1"
          >
            Clear all
          </Button>
        </div>
      )}

      {/* Bulk action bar */}
      <WarehouseBulkBar
        selected={selectedRows}
        mayManage={mayManage}
        onPrintSelected={printSelected}
        onClear={() => setRowSelection({})}
        onChanged={() => {
          setRowSelection({});
          void warehousesQuery.refetch();
        }}
      />

      <DataTable
        columns={tanStackColumns}
        data={warehouses}
        rowCount={meta?.total ?? 0}
        getRowId={(row) => String(row.id)}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
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
        onRowClick={(warehouse: WarehouseResource) =>
          navigate({ pathname: `/warehouses/${warehouse.id}`, search: listSearch })
        }
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
              ...(filtersForPrint.length ? [{ label: 'Filters', value: filtersForPrint.join(', ') }] : []),
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
        <div ref={selectedPrintRef}>
          <PrintLayout
            title="WAREHOUSE LIST (SELECTED)"
            printedAt={new Date()}
            recordCount={selectedRows.length}
            meta={[{ label: 'Selection', value: `${selectedRows.length} warehouses selected` }]}
          >
            <PrintableTable columns={warehouseColumns} rows={selectedRows} getRowKey={(warehouse) => warehouse.id} />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}

interface WarehouseFilterControlsProps {
  query: ReturnType<typeof useListQueryState<WarehouseFilters>>;
  full?: boolean;
}

/** Status, default and locations select controls — shared by desktop row and mobile Filters sheet. */
function WarehouseFilterControls({ query, full }: WarehouseFilterControlsProps) {
  return (
    <div className={cn('flex flex-wrap items-center gap-2', full && 'flex-col items-stretch')}>
      <Select
        value={query.filters.active ?? 'all'}
        onValueChange={(value) => query.setFilter('active', value === 'all' ? undefined : value)}
      >
        <SelectTrigger className={full ? 'h-10 w-full' : 'h-10 w-36'} aria-label="Status">
          <SelectValue placeholder="Status" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All statuses</SelectItem>
          <SelectItem value="true">Active</SelectItem>
          <SelectItem value="false">Inactive</SelectItem>
        </SelectContent>
      </Select>

      <Select
        value={query.filters.is_default ?? 'all'}
        onValueChange={(value) => query.setFilter('is_default', value === 'all' ? undefined : value)}
      >
        <SelectTrigger className={full ? 'h-10 w-full' : 'h-10 w-40'} aria-label="Default role">
          <SelectValue placeholder="Role" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All roles</SelectItem>
          <SelectItem value="true">Default only</SelectItem>
          <SelectItem value="false">Non-default</SelectItem>
        </SelectContent>
      </Select>

      <Select
        value={query.filters.has_locations ?? 'all'}
        onValueChange={(value) => query.setFilter('has_locations', value === 'all' ? undefined : value)}
      >
        <SelectTrigger className={full ? 'h-10 w-full' : 'h-10 w-44'} aria-label="Locations">
          <SelectValue placeholder="Locations" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All locations</SelectItem>
          <SelectItem value="true">With locations</SelectItem>
          <SelectItem value="false">No locations</SelectItem>
        </SelectContent>
      </Select>
    </div>
  );
}
