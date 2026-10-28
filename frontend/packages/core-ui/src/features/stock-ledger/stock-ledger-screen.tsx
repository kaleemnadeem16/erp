import { useEffect, useMemo, useState } from 'react';
import { useItemsById } from '../../lib/use-records-by-id';
import { ItemCombobox } from '../../components/lookups/entity-comboboxes';
import { useNavigate } from 'react-router';
import { Search, SlidersHorizontal, X } from 'lucide-react';
import type { SortingState } from '@tanstack/react-table';
import {
  useStockMovesIndex,
  type ItemResource,
  useWarehousesIndex,
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
import { lookupState, type LookupState } from '../../lib/api-errors';
import { usePermission } from '../../hooks/use-permission';
import { cn } from '../../lib/utils';

export interface StockMoveFilters {
  search: string;
  item_id: string;
  warehouse_id: string;
  source_type: string;
  moved_from: string;
  moved_to: string;
  [key: string]: string;
}

export function StockLedgerScreen() {
  const navigate = useNavigate();
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
  const { view, setView } = useListViewPreference('stock-ledger');
  const [searchInput, setSearchInput] = useState(query.filters.search ?? '');

  useEffect(() => {
    const handle = setTimeout(() => {
      if (searchInput !== (query.filters.search ?? '')) query.setFilter('search', searchInput || undefined);
    }, 300);
    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  // Only the filtered item is needed (for the filter chip); it is resolved by id.
  const items = useItemsById<ItemResource>(query.filters.item_id ? [Number(query.filters.item_id)] : [], []);

  const warehousesQuery = useWarehousesIndex({ per_page: 100, sort: 'name' });
  const warehouses = warehousesQuery.data?.status === 200 ? warehousesQuery.data.data.data : [];

  const movesQuery = useStockMovesIndex({
    'filter[item_id]': query.filters.item_id ? Number(query.filters.item_id) : undefined,
    'filter[warehouse_id]': query.filters.warehouse_id ? Number(query.filters.warehouse_id) : undefined,
    'filter[source_type]': query.filters.source_type || undefined,
    'filter[moved_from]': query.filters.moved_from || undefined,
    'filter[moved_to]': query.filters.moved_to || undefined,
    'filter[search]': query.filters.search || undefined,
    sort: query.sort as StockMovesIndexSort | undefined,
    include: 'item,from_location,to_location',
    per_page: query.perPage,
    cursor: query.cursor,
  });

  const moves = movesQuery.data?.status === 200 ? movesQuery.data.data.data : [];
  const meta = movesQuery.data?.status === 200 ? movesQuery.data.data.meta : undefined;

  const tanStackColumns = useMemo(() => toTanStackColumns(columns), [columns]);
  const sorting: SortingState = apiSortToTableSorting(query.sort, columns);

  const activeFilterLines = describeFilters(query.filters, items, warehouses);
  const { contentRef: printRef, print } = useQuickPrint({ documentTitle: 'Stock Ledger' });

  const activeItem = items.find((i) => String(i.id) === query.filters.item_id);
  const activeWarehouse = warehouses.find((w) => String(w.id) === query.filters.warehouse_id);

  const activeFilterCount = [
    query.filters.item_id,
    query.filters.warehouse_id,
    query.filters.source_type,
    query.filters.moved_from,
    query.filters.moved_to,
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
    query.filters.source_type && {
      key: 'source_type',
      label: `Source: ${query.filters.source_type}`,
      onRemove: () => query.setFilter('source_type', undefined),
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
    for (const key of ['item_id', 'warehouse_id', 'source_type', 'moved_from', 'moved_to', 'search'] as const) {
      if (query.filters[key]) {
        query.setFilter(key, undefined);
      }
    }
  };

  return (
    <ScreenTemplate
      kind="list"
      className="erp-screen space-y-4 p-4 pb-24 md:p-6"
      header={
        <PageHeader
          title="Stock Ledger"
          description="Every posted stock movement, oldest cost layer to latest — read-only. Movements are posted by receipts, issues, transfers and QA, never entered here directly."
        />
      }
    >
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
              onClick={() => {
                setSearchInput('');
                query.setFilter('search', undefined);
              }}
              className="absolute right-3 top-1/2 -translate-y-1/2 cursor-pointer text-muted-foreground hover:text-foreground"
              aria-label="Clear search"
            >
              <X className="h-4 w-4" />
            </button>
          )}
        </div>

        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          <StockMoveFilterControls
            warehousesLookup={lookupState(warehousesQuery, 'warehouses')}
            warehouses={warehouses}
            query={query}
          />
          <div className="ml-auto flex items-center gap-2">
            <PrintTrigger onPrint={() => print()} />
          </div>
        </div>

        <Button
          variant={filtersOpen || activeFilterCount > 0 ? 'secondary' : 'outline'}
          size="sm"
          className={cn(
            'relative h-10 shrink-0 px-3 md:hidden',
            (filtersOpen || activeFilterCount > 0) && 'ui-btn-filters-active'
          )}
          onClick={() => setFiltersOpen(true)}
          aria-label="Open filter settings"
        >
          <SlidersHorizontal className="mr-1.5 h-4 w-4" />
          Filters
          {activeFilterCount > 0 && <Badge className="ml-1.5 ui-filter-badge">{activeFilterCount}</Badge>}
        </Button>
      </div>

      {/* Active Filter Chips */}
      {activeFilters.length > 0 && (
        <div className="flex flex-wrap items-center gap-1.5 pt-1">
          <span className="mr-1 text-xs text-muted-foreground">Active filters:</span>
          {activeFilters.map((chip) => (
            <span key={chip.key} className="ui-chip-active">
              <span>{chip.label}</span>
              <Tooltip>
                <TooltipTrigger asChild>
                  <button
                    type="button"
                    onClick={chip.onRemove}
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
            className="h-6 px-2 text-xs text-muted-foreground hover:text-foreground"
          >
            Clear all
          </Button>
        </div>
      )}

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
              <Label className="text-sm font-medium">Moved date range</Label>
              <div className="flex items-center gap-1.5">
                <DateField
                  aria-label="Moved from"
                  value={query.filters.moved_from ?? ''}
                  onChange={(value) => query.setFilter('moved_from', value || undefined)}
                  className="h-10 w-full"
                />
                <span className="text-xs text-muted-foreground">–</span>
                <DateField
                  aria-label="Moved to"
                  value={query.filters.moved_to ?? ''}
                  onChange={(value) => query.setFilter('moved_to', value || undefined)}
                  className="h-10 w-full"
                />
              </div>
            </div>

            <div className="pt-2">
              <PrintTrigger onPrint={() => print()} className="w-full justify-center" />
            </div>
          </div>
          <SheetFooter>
            <Button variant="ghost" onClick={clearAllFilters}>
              Clear filters
            </Button>
            <Button onClick={() => setFiltersOpen(false)}>Done</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>

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
        onRowClick={(move: StockMoveResource) => navigate(`/stock-moves/${move.id}`)}
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

      {/* Quick Print — a dedicated print-only layout, hidden until react-to-print clones it. Prints the currently loaded page only, per the cursor ledger's own pagination model. */}
      <div className="hidden">
        <div ref={printRef}>
          <PrintLayout
            title="STOCK LEDGER"
            printedAt={new Date()}
            recordCount={moves.length}
            meta={[
              ...(activeFilterLines.length > 0 ? [{ label: 'Filters', value: activeFilterLines.join(', ') }] : []),
              ...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, columns) ?? query.sort }] : []),
            ]}
          >
            <PrintableTable columns={columns} rows={moves} getRowKey={(move) => move.id} />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}

interface StockMoveFilterControlsProps {
  warehousesLookup: LookupState;
  warehouses: { id: number; name: string }[];
  query: ReturnType<typeof useCursorQueryState<StockMoveFilters>>;
}

/** Item/Warehouse/Source-type/date-range controls in the desktop toolbar row. */
function StockMoveFilterControls({ warehousesLookup, warehouses, query }: StockMoveFilterControlsProps) {
  return (
    <>
      <ItemCombobox
        label="Item"
        placeholder="Item"
        className="h-10 w-52"
        value={query.filters.item_id ?? 'all'}
        onValueChange={(value) => query.setFilter('item_id', value === 'all' ? undefined : value)}
        allLabel="All items"
      />
      <Combobox
        {...warehousesLookup}
        className="h-10 w-48"
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
      <Input
        placeholder="Source type…"
        aria-label="Source type"
        value={query.filters.source_type ?? ''}
        onChange={(event) => query.setFilter('source_type', event.target.value || undefined)}
        className="h-10 w-40"
      />
      <div className="flex items-center gap-1.5">
        <DateField
          aria-label="Moved from"
          value={query.filters.moved_from ?? ''}
          onChange={(value) => query.setFilter('moved_from', value || undefined)}
          className="h-10 w-36"
        />
        <span className="text-xs text-muted-foreground">–</span>
        <DateField
          aria-label="Moved to"
          value={query.filters.moved_to ?? ''}
          onChange={(value) => query.setFilter('moved_to', value || undefined)}
          className="h-10 w-36"
        />
      </div>
    </>
  );
}

export function describeFilters(
  filters: Partial<StockMoveFilters>,
  items: { id: number; code: string; name: string }[],
  warehouses: { id: number; name: string }[]
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
  if (filters.source_type) lines.push(`Source type = ${filters.source_type}`);
  if (filters.moved_from) lines.push(`From = ${filters.moved_from}`);
  if (filters.moved_to) lines.push(`To = ${filters.moved_to}`);
  if (filters.search) lines.push(`Search = "${filters.search}"`);

  return lines;
}
