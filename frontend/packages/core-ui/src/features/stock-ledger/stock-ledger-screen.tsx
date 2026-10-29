import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router';
import { useItemsById } from '../../lib/use-records-by-id';
import { ItemCombobox, LocationCombobox } from '../../components/lookups/entity-comboboxes';
import { Search, SlidersHorizontal, X, Printer, Download, Filter, ChevronUp } from 'lucide-react';
import type { RowSelectionState, SortingState } from '@tanstack/react-table';
import {
  useStockMovesIndex,
  type ItemResource,
  useWarehousesIndex,
  useLocationsShow,
  type StockMoveResource,
  type StockMovesIndexSort,
} from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Combobox } from '../../components/ui/combobox';
import { DateField } from '../../components/ui/date-field';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { Tooltip, TooltipContent, TooltipTrigger } from '../../components/ui/tooltip';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { CursorDataTable, toTanStackColumns, isColumnVisibleByDefault } from '../../components/data-table';
import { selectionColumn } from '../../components/data-table/columns';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import {
  apiSortToTableSorting,
  describeSort,
  tableSortingToApiSort,
  useCursorQueryState,
} from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useListViewPreference } from '../../lib/use-view-preference';
import { stockMoveColumns } from './stock-moves-columns';
import { StockMoveDetailScreen } from './stock-move-detail-screen';
import { lookupState, type LookupState } from '../../lib/api-errors';
import { usePermission } from '../../hooks/use-permission';
import { formatLocation } from '../../lib/format-location';
import { cn } from '../../lib/utils';

export interface StockMoveFilters {
  search: string;
  item_id: string;
  warehouse_id: string;
  location_id: string;
  batch_id: string;
  source_type: string;
  moved_from: string;
  moved_to: string;
  [key: string]: string;
}

export function StockLedgerScreen() {
  const mayViewValuation = usePermission('inventory.valuation.view');
  const columns = useMemo(
    () => stockMoveColumns.filter((column) => mayViewValuation || column.key !== 'unit_cost'),
    [mayViewValuation]
  );
  const query = useCursorQueryState<StockMoveFilters>({ defaultSort: '-moved_at', defaultPerPage: 25 });
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    'stock-ledger',
    Object.fromEntries(columns.map((column) => [column.key, isColumnVisibleByDefault(column)]))
  );
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const { view, setView } = useListViewPreference('stock-ledger');
  const [searchInput, setSearchInput] = useState(query.filters.search ?? '');

  // Desktop side panel for movement detail
  const [selectedMoveId, setSelectedMoveId] = useState<number | null>(null);

  // Row selection
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});

  useEffect(() => {
    const handle = setTimeout(() => {
      if (searchInput !== (query.filters.search ?? '')) query.setFilter('search', searchInput || undefined);
    }, 300);
    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  const [searchParams] = useSearchParams();

  // Clear row selection when filters or pagination change
  const filterKey = `${query.cursor ?? ''}:${searchParams.toString()}`;
  useEffect(() => {
    setRowSelection((prev) => (Object.keys(prev).length > 0 ? {} : prev));
  }, [filterKey]);

  // Only the filtered item is needed (for the filter chip); it is resolved by id.
  const items = useItemsById<ItemResource>(query.filters.item_id ? [Number(query.filters.item_id)] : [], []);

  const warehousesQuery = useWarehousesIndex({ per_page: 100, sort: 'name' });
  const warehouses = warehousesQuery.data?.status === 200 ? warehousesQuery.data.data.data : [];

  // Resolve location name for active filter chip if filtered
  const locationId = query.filters.location_id ? Number(query.filters.location_id) : undefined;
  const locationQuery = useLocationsShow(
    locationId ?? 0,
    { include: 'warehouse' },
    { query: { enabled: !!locationId && locationId > 0 } }
  );
  const activeLocation = locationQuery.data?.status === 200 ? locationQuery.data.data.data : undefined;

  const movesQuery = useStockMovesIndex({
    'filter[item_id]': query.filters.item_id ? Number(query.filters.item_id) : undefined,
    'filter[warehouse_id]': query.filters.warehouse_id ? Number(query.filters.warehouse_id) : undefined,
    'filter[location_id]': query.filters.location_id ? Number(query.filters.location_id) : undefined,
    'filter[batch_id]': query.filters.batch_id ? Number(query.filters.batch_id) : undefined,
    'filter[source_type]': query.filters.source_type || undefined,
    'filter[moved_from]': query.filters.moved_from || undefined,
    'filter[moved_to]': query.filters.moved_to || undefined,
    'filter[search]': query.filters.search || undefined,
    sort: query.sort as StockMovesIndexSort | undefined,
    include: 'item,from_location,to_location,batch,valuation_entries',
    per_page: query.perPage,
    cursor: query.cursor,
  });

  const moves = movesQuery.data?.status === 200 ? movesQuery.data.data.data : [];
  const meta = movesQuery.data?.status === 200 ? movesQuery.data.data.meta : undefined;

  const selectedMoves = useMemo(
    () => moves.filter((move) => rowSelection[String(move.id)]),
    [moves, rowSelection]
  );

  const tanStackColumns = useMemo(
    () => [selectionColumn<StockMoveResource>(), ...toTanStackColumns(columns)],
    [columns]
  );
  const sorting: SortingState = apiSortToTableSorting(query.sort, columns);

  const activeFilterLines = describeFilters(
    query.filters,
    items,
    warehouses,
    activeLocation ? [activeLocation] : []
  );

  const { contentRef: printRef, print } = useQuickPrint({ documentTitle: 'Stock Ledger' });
  const { contentRef: printSelectedRef, print: printSelected } = useQuickPrint({
    documentTitle: 'Stock Ledger (Selected)',
  });

  const activeItem = items.find((i) => String(i.id) === query.filters.item_id);
  const activeWarehouse = warehouses.find((w) => String(w.id) === query.filters.warehouse_id);

  const activeFilterCount = [
    query.filters.item_id,
    query.filters.warehouse_id,
    query.filters.location_id,
    query.filters.source_type,
    query.filters.batch_id,
    query.filters.moved_from,
    query.filters.moved_to,
  ].filter(Boolean).length;

  const advancedFilterCount = [
    query.filters.location_id,
    query.filters.source_type,
    query.filters.batch_id,
  ].filter(Boolean).length;

  const activeFilters = [
    query.filters.item_id && {
      key: 'item_id',
      label: `Item: ${activeItem ? `${activeItem.code} — ${activeItem.name}` : query.filters.item_id}`,
      onRemove: () => query.setFilter('item_id', undefined),
    },
    query.filters.warehouse_id && {
      key: 'warehouse_id',
      label: `Warehouse: ${activeWarehouse?.name ?? query.filters.warehouse_id}`,
      onRemove: () => query.setFilter('warehouse_id', undefined),
    },
    query.filters.location_id && {
      key: 'location_id',
      label: `Location: ${activeLocation ? formatLocation(activeLocation) : query.filters.location_id}`,
      onRemove: () => query.setFilter('location_id', undefined),
    },
    query.filters.source_type && {
      key: 'source_type',
      label: `Source: ${query.filters.source_type}`,
      onRemove: () => query.setFilter('source_type', undefined),
    },
    query.filters.batch_id && {
      key: 'batch_id',
      label: `Batch: #${query.filters.batch_id}`,
      onRemove: () => query.setFilter('batch_id', undefined),
    },
    (query.filters.moved_from || query.filters.moved_to) && {
      key: 'moved_date',
      label: `Moved: ${query.filters.moved_from || '…'} – ${query.filters.moved_to || '…'}`,
      onRemove: () => {
        query.setFilter('moved_from', undefined);
        query.setFilter('moved_to', undefined);
      },
    },
    query.filters.search && {
      key: 'search',
      label: `Search: "${query.filters.search}"`,
      onRemove: () => {
        setSearchInput('');
        query.setFilter('search', undefined);
      },
    },
  ].filter(Boolean) as { key: string; label: string; onRemove: () => void }[];

  const clearAllFilters = () => {
    setSearchInput('');
    query.setFilter('search', undefined);
    query.setFilter('item_id', undefined);
    query.setFilter('warehouse_id', undefined);
    query.setFilter('location_id', undefined);
    query.setFilter('batch_id', undefined);
    query.setFilter('source_type', undefined);
    query.setFilter('moved_from', undefined);
    query.setFilter('moved_to', undefined);
  };

  const clearAdvancedFilters = () => {
    query.setFilter('location_id', undefined);
    query.setFilter('source_type', undefined);
    query.setFilter('batch_id', undefined);
  };

  function exportSelectedCsv() {
    if (selectedMoves.length === 0) return;
    const headers = ['ID', 'Moved At', 'Item Code', 'Item Name', 'From Location', 'To Location', 'Quantity'];
    if (mayViewValuation) {
      headers.push('Unit Cost');
    }
    headers.push('Source Type', 'Source ID', 'Batch', 'Serial');

    const csvRows = selectedMoves.map((m) => {
      const row = [
        m.id,
        m.moved_at,
        m.item?.code ?? '',
        `"${(m.item?.name ?? '').replace(/"/g, '""')}"`,
        m.from_location ? `${m.from_location.warehouse?.name ?? ''} / ${m.from_location.code}` : '',
        m.to_location ? `${m.to_location.warehouse?.name ?? ''} / ${m.to_location.code}` : '',
        m.qty,
      ];
      if (mayViewValuation) {
        row.push(m.unit_cost ?? '');
      }
      row.push(m.source_type, m.source_id, m.batch?.code ?? m.batch_id ?? '', m.serial_no ?? '');
      return row.join(',');
    });

    const csvContent = [headers.join(','), ...csvRows].join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.setAttribute('href', url);
    link.setAttribute('download', `stock-movements-${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  }

  return (
    <ScreenTemplate kind="list" className="erp-screen space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Stock Ledger"
        description="Every posted stock movement, oldest cost layer to latest — read-only. Movements are posted by receipts, issues, transfers and QA, never entered here directly."
      />

      {/* Primary Toolbar */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative flex-1 md:w-64 md:flex-none">
          <Search
            aria-hidden="true"
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
          />
          <Input
            className="h-10 pl-9 pr-9"
            placeholder="Search item…"
            aria-label="Search items"
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
          />
          {searchInput && (
            <button
              type="button"
              aria-label="Clear search"
              onClick={() => {
                setSearchInput('');
                query.setFilter('search', undefined);
              }}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
            >
              <X className="h-4 w-4" />
            </button>
          )}
        </div>

        {/* Desktop inline controls */}
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          <ItemCombobox
            label="Item"
            placeholder="All items"
            className="h-10 w-52"
            value={query.filters.item_id ?? 'all'}
            onValueChange={(value) => query.setFilter('item_id', value === 'all' ? undefined : value)}
            allLabel="All items"
          />
          <Combobox
            {...lookupState(warehousesQuery, 'warehouses')}
            className="h-10 w-44"
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
          <div className="flex items-center gap-1.5">
            <DateField
              aria-label="Moved from"
              value={query.filters.moved_from ?? ''}
              onChange={(value) => query.setFilter('moved_from', value || undefined)}
              className="h-10 w-36"
            />
            <span className="text-xs text-muted-foreground" aria-hidden="true">–</span>
            <DateField
              aria-label="Moved to"
              value={query.filters.moved_to ?? ''}
              onChange={(value) => query.setFilter('moved_to', value || undefined)}
              className="h-10 w-36"
            />
          </div>

          <Button
            type="button"
            variant={advancedOpen || advancedFilterCount > 0 ? 'secondary' : 'outline'}
            size="sm"
            className={cn('h-10 gap-1.5', (advancedOpen || advancedFilterCount > 0) && 'ui-btn-filters-active')}
            onClick={() => setAdvancedOpen(!advancedOpen)}
            aria-expanded={advancedOpen}
            aria-label="More filters"
          >
            <Filter className="h-4 w-4" />
            More filters
            {advancedFilterCount > 0 && (
              <Badge className="ui-filter-badge ml-1 px-1.5 text-xs">{advancedFilterCount}</Badge>
            )}
          </Button>

          <div className="ml-auto flex items-center gap-2">
            <PrintTrigger onPrint={() => print()} className="h-10" />
          </div>
        </div>

        {/* Mobile filter button */}
        <Button
          variant="outline"
          size="sm"
          className={cn('h-10 shrink-0 gap-1.5 px-3 md:hidden', activeFilterCount > 0 && 'ui-btn-filters-active')}
          onClick={() => setFiltersOpen(true)}
          aria-label={`Open filters sheet${activeFilterCount > 0 ? `, ${activeFilterCount} active` : ''}`}
        >
          <SlidersHorizontal className="h-4 w-4" />
          Filters
          {activeFilterCount > 0 && (
            <Badge className="ui-filter-badge ml-1 px-1.5 text-xs">{activeFilterCount}</Badge>
          )}
        </Button>
      </div>

      {/* Collapsible Advanced Filters Drawer (Desktop) */}
      <div
        className={cn(
          'hidden md:grid transition-[grid-template-rows,opacity,transform] duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] motion-reduce:transition-none',
          advancedOpen
            ? 'grid-rows-[1fr] opacity-100 translate-y-0'
            : 'grid-rows-[0fr] opacity-0 -translate-y-2 pointer-events-none'
        )}
        aria-hidden={!advancedOpen}
        inert={!advancedOpen}
      >
        <div className="overflow-hidden">
          <div
            role="region"
            aria-label="Advanced filters"
            className="rounded-lg border border-border bg-card p-4 text-card-foreground shadow-xs ui-filter-panel"
          >
            <div className="mb-4 flex items-center justify-between">
              <span className="flex items-center gap-2 text-sm font-semibold">
                <SlidersHorizontal aria-hidden="true" className="h-4 w-4" />
                Advanced filters
              </span>
              <div className="flex items-center gap-2">
                {advancedFilterCount > 0 && (
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 text-xs text-muted-foreground hover:text-foreground"
                    onClick={clearAdvancedFilters}
                  >
                    Reset filters
                  </Button>
                )}
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 gap-1 px-2 text-xs"
                  onClick={() => setAdvancedOpen(false)}
                  aria-label="Close filters"
                >
                  Hide filters
                  <ChevronUp aria-hidden="true" className="h-4 w-4" />
                </Button>
              </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3">
              <div className="space-y-1.5">
                <Label className="text-sm font-medium">Location</Label>
                <LocationCombobox
                  label="Location"
                  placeholder="All locations"
                  className="h-10 w-full"
                  warehouseId={query.filters.warehouse_id ? Number(query.filters.warehouse_id) : undefined}
                  value={query.filters.location_id ?? 'all'}
                  onValueChange={(value) => query.setFilter('location_id', value === 'all' ? undefined : value)}
                  allLabel="All locations"
                />
              </div>

              <div className="space-y-1.5">
                <Label className="text-sm font-medium">Source type</Label>
                <Input
                  placeholder="Source type (e.g. goods_receipt)…"
                  aria-label="Source type"
                  value={query.filters.source_type ?? ''}
                  onChange={(event) => query.setFilter('source_type', event.target.value || undefined)}
                  className="h-10 w-full"
                />
              </div>

              <div className="space-y-1.5">
                <Label className="text-sm font-medium">Batch ID</Label>
                <Input
                  type="number"
                  placeholder="Batch ID…"
                  aria-label="Batch ID"
                  value={query.filters.batch_id ?? ''}
                  onChange={(event) => query.setFilter('batch_id', event.target.value || undefined)}
                  className="h-10 w-full"
                />
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Active filter chips */}
      {activeFilters.length > 0 && (
        <div className="flex flex-wrap items-center gap-2" role="region" aria-label="Active filters">
          <span className="text-xs font-medium text-muted-foreground">Filters:</span>
          {activeFilters.map((chip) => (
            <Badge
              key={chip.key}
              variant="secondary"
              className="ui-chip-active inline-flex items-center gap-1.5 py-1 pl-2.5 pr-1.5 text-xs font-normal"
            >
              <span>{chip.label}</span>
              <Tooltip>
                <TooltipTrigger asChild>
                  <button
                    type="button"
                    onClick={chip.onRemove}
                    aria-label={`Remove filter: ${chip.label}`}
                    className="inline-flex h-4 w-4 items-center justify-center rounded-full hover:bg-muted"
                  >
                    <X className="h-3 w-3" />
                  </button>
                </TooltipTrigger>
                <TooltipContent>Remove filter</TooltipContent>
              </Tooltip>
            </Badge>
          ))}
          <button
            type="button"
            className="ui-link-action ml-1 shrink-0 cursor-pointer text-xs"
            onClick={clearAllFilters}
          >
            Clear all
          </button>
        </div>
      )}

      {/* Mobile Filter Sheet */}
      <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
        <SheetContent side="bottom" className="max-h-[85vh] overflow-y-auto md:hidden">
          <SheetHeader>
            <SheetTitle>Filters</SheetTitle>
          </SheetHeader>
          <div className="flex flex-col gap-4 py-4">
            <div className="space-y-1.5">
              <Label className="text-sm font-medium">Item</Label>
              <ItemCombobox
                label="Item"
                placeholder="All items"
                className="h-10 w-full"
                value={query.filters.item_id ?? 'all'}
                onValueChange={(value) => query.setFilter('item_id', value === 'all' ? undefined : value)}
                allLabel="All items"
              />
            </div>

            <div className="space-y-1.5">
              <Label className="text-sm font-medium">Warehouse</Label>
              <Combobox
                {...lookupState(warehousesQuery, 'warehouses')}
                className="h-10 w-full"
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
            </div>

            <div className="space-y-1.5">
              <Label className="text-sm font-medium">Location</Label>
              <LocationCombobox
                label="Location"
                placeholder="All locations"
                className="h-10 w-full"
                warehouseId={query.filters.warehouse_id ? Number(query.filters.warehouse_id) : undefined}
                value={query.filters.location_id ?? 'all'}
                onValueChange={(value) => query.setFilter('location_id', value === 'all' ? undefined : value)}
                allLabel="All locations"
              />
            </div>

            <div className="space-y-1.5">
              <Label className="text-sm font-medium">Source type</Label>
              <Input
                placeholder="Source type…"
                aria-label="Source type"
                value={query.filters.source_type ?? ''}
                onChange={(event) => query.setFilter('source_type', event.target.value || undefined)}
                className="h-10 w-full"
              />
            </div>

            <div className="space-y-1.5">
              <Label className="text-sm font-medium">Batch ID</Label>
              <Input
                type="number"
                placeholder="Batch ID…"
                aria-label="Batch ID"
                value={query.filters.batch_id ?? ''}
                onChange={(event) => query.setFilter('batch_id', event.target.value || undefined)}
                className="h-10 w-full"
              />
            </div>

            <div className="space-y-1.5">
              <Label className="text-sm font-medium">Moved date range</Label>
              <div className="grid grid-cols-2 gap-2">
                <DateField
                  aria-label="Moved from"
                  value={query.filters.moved_from ?? ''}
                  onChange={(value) => query.setFilter('moved_from', value || undefined)}
                  className="w-full"
                />
                <DateField
                  aria-label="Moved to"
                  value={query.filters.moved_to ?? ''}
                  onChange={(value) => query.setFilter('moved_to', value || undefined)}
                  className="w-full"
                />
              </div>
            </div>

            <PrintTrigger onPrint={() => print()} className="h-10 w-full justify-center" />
          </div>
          <SheetFooter className="flex-row justify-between gap-2 border-t pt-4">
            <Button variant="ghost" onClick={clearAllFilters}>
              Clear filters
            </Button>
            <Button onClick={() => setFiltersOpen(false)}>Done</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>

      {/* Data Table */}
      <CursorDataTable
        columns={tanStackColumns}
        data={moves}
        sorting={sorting}
        onSortingChange={(updater) => {
          const next = typeof updater === 'function' ? updater(sorting) : updater;
          query.setSort(tableSortingToApiSort(next, columns));
        }}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        getRowId={(move) => String(move.id)}
        onRowClick={(move: StockMoveResource) => setSelectedMoveId(move.id)}
        isLoading={movesQuery.isLoading}
        isError={movesQuery.isError}
        error={movesQuery.error}
        onRetry={() => void movesQuery.refetch()}
        errorMessage="Couldn't load the stock ledger."
        emptyMessage={
          activeFilterCount > 0 || query.filters.search
            ? 'No stock movements match these filters.'
            : 'No stock movements recorded yet.'
        }
        perPage={query.perPage}
        onPerPageChange={query.setPerPage}
        hasPrevious={query.canGoBack}
        hasNext={!!meta?.next_cursor}
        onPrevious={query.goBack}
        onNext={() => query.goToNextCursor(meta?.next_cursor)}
        view={view}
        onViewChange={setView}
        cardColumns={columns}
      />

      {/* Floating Bulk Action Bar */}
      {selectedMoves.length > 0 && (
        <div
          role="region"
          aria-label="Bulk actions"
          className="ui-bulk-bar fixed bottom-4 left-1/2 z-30 flex -translate-x-1/2 items-center gap-3 rounded-lg border bg-card px-4 py-2 text-card-foreground shadow-lg"
          data-testid="stock-ledger-bulk-bar"
        >
          <span className="text-sm font-medium" data-testid="bulk-count">
            {selectedMoves.length} selected movement{selectedMoves.length === 1 ? '' : 's'}
          </span>
          <Button
            size="sm"
            variant="outline"
            className="h-8 gap-1.5"
            onClick={() => printSelected()}
          >
            <Printer className="h-3.5 w-3.5" />
            Print selected
          </Button>
          <Button
            size="sm"
            variant="outline"
            className="h-8 gap-1.5"
            onClick={() => exportSelectedCsv()}
          >
            <Download className="h-3.5 w-3.5" />
            Export CSV
          </Button>
          <Button
            size="sm"
            variant="ghost"
            className="h-8"
            onClick={() => setRowSelection({})}
          >
            Clear selection
          </Button>
        </div>
      )}

      {/* Desktop Side Panel / Sheet Detail View */}
      {selectedMoveId != null && selectedMoveId > 0 && (
        <StockMoveDetailScreen
          moveId={selectedMoveId}
          presentation="sheet"
          onClose={() => setSelectedMoveId(null)}
        />
      )}

      {/* Hidden Quick Print Layout for Entire Loaded Page */}
      {/* Hidden Quick Print Layout for Current View */}
      <div className="hidden">
        <div ref={printRef}>
          <PrintLayout
            title="STOCK LEDGER"
            printedAt={new Date()}
            recordCount={moves.length}
            meta={[
              ...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, columns) ?? query.sort }] : []),
              ...activeFilterLines.map((line) => ({ label: 'Filter', value: line })),
            ]}
          >
            <PrintableTable columns={columns} rows={moves} getRowKey={(move) => move.id} />
          </PrintLayout>
        </div>
      </div>

      {/* Hidden Quick Print Layout for Selected Rows */}
      {selectedMoves.length > 0 && (
        <div style={{ display: 'none' }}>
          <div ref={printSelectedRef}>
            <PrintLayout
              title="STOCK LEDGER (SELECTED)"
              printedAt={new Date()}
              recordCount={selectedMoves.length}
              meta={[
                ...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, columns) ?? query.sort }] : []),
                ...activeFilterLines.map((line) => ({ label: 'Filter', value: line })),
              ]}
            >
              <PrintableTable columns={columns} rows={selectedMoves} getRowKey={(move) => move.id} />
            </PrintLayout>
          </div>
        </div>
      )}
    </ScreenTemplate>
  );
}

export function describeFilters(
  filters: Partial<StockMoveFilters>,
  items: { id: number; code: string; name: string }[],
  warehouses: { id: number; name: string }[],
  locations?: { id: number; code: string; name?: string; warehouse?: { code: string } | null }[]
): string[] {
  const lines: string[] = [];

  if (filters.item_id) {
    const item = items.find((i) => String(i.id) === filters.item_id);
    lines.push(`Item = ${item ? `${item.code} — ${item.name}` : filters.item_id}`);
  }

  if (filters.warehouse_id) {
    const warehouse = warehouses.find((w) => String(w.id) === filters.warehouse_id);
    lines.push(`Warehouse = ${warehouse?.name ?? filters.warehouse_id}`);
  }

  if (filters.location_id) {
    const location = locations?.find((l) => String(l.id) === filters.location_id);
    lines.push(`Location = ${location ? formatLocation(location) : filters.location_id}`);
  }

  if (filters.source_type) lines.push(`Source type = ${filters.source_type}`);
  if (filters.batch_id) lines.push(`Batch = #${filters.batch_id}`);
  if (filters.moved_from) lines.push(`From = ${filters.moved_from}`);
  if (filters.moved_to) lines.push(`To = ${filters.moved_to}`);
  if (filters.search) lines.push(`Search = "${filters.search}"`);

  return lines;
}
