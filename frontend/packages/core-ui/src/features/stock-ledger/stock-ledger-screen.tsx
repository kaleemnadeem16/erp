import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { SlidersHorizontal } from 'lucide-react';
import type { SortingState } from '@tanstack/react-table';
import { useItemsIndex, useStockMovesIndex, useWarehousesIndex, type StockMoveResource, type StockMovesIndexSort } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Combobox } from '../../components/ui/combobox';
import { DateField } from '../../components/ui/date-field';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { PageHeader } from '../../components/page/page-header';
import { CursorDataTable, toTanStackColumns, isColumnVisibleByDefault } from '../../components/data-table';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { apiSortToTableSorting, describeSort, tableSortingToApiSort, useCursorQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useListViewPreference } from '../../lib/use-view-preference';
import { stockMoveColumns } from './stock-moves-columns';

interface StockMoveFilters {
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
  const query = useCursorQueryState<StockMoveFilters>({ defaultSort: '-moved_at', defaultPerPage: 25 });
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    'stock-ledger',
    Object.fromEntries(stockMoveColumns.map((column) => [column.key, isColumnVisibleByDefault(column)]))
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

  const itemsQuery = useItemsIndex({ per_page: 100, sort: 'name' });
  const items = itemsQuery.data?.status === 200 ? itemsQuery.data.data.data : [];

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

  const tanStackColumns = useMemo(() => toTanStackColumns(stockMoveColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, stockMoveColumns);

  const activeFilterLines = describeFilters(query.filters, items, warehouses);
  const { contentRef: printRef, print } = useQuickPrint({ documentTitle: 'Stock Ledger' });
  const activeFilterCount = [
    query.filters.item_id,
    query.filters.warehouse_id,
    query.filters.source_type,
    query.filters.moved_from,
    query.filters.moved_to,
  ].filter(Boolean).length;

  return (
    <div className="space-y-4 p-4 md:p-6">
      <PageHeader
        title="Stock Ledger"
        description="Every posted stock movement, oldest cost layer to latest — read-only. Movements are posted by receipts, issues, transfers and QA, never entered here directly."
      />

      <div className="flex flex-wrap items-center gap-2">
        <Input
          placeholder="Search item…"
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          className="h-9 flex-1 md:w-56 md:flex-none"
        />
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          <StockMoveFilterControls items={items} warehouses={warehouses} query={query} />
          <div className="ml-auto flex items-center gap-2">
            <PrintTrigger onPrint={() => print()} />
          </div>
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
            <StockMoveFilterControls items={items} warehouses={warehouses} query={query} full />
            <PrintTrigger onPrint={() => print()} className="w-full justify-center" />
          </div>
          <SheetFooter>
            <Button
              variant="ghost"
              onClick={() => {
                query.setFilter('item_id', undefined);
                query.setFilter('warehouse_id', undefined);
                query.setFilter('source_type', undefined);
                query.setFilter('moved_from', undefined);
                query.setFilter('moved_to', undefined);
              }}
            >
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
          query.setSort(tableSortingToApiSort(next, stockMoveColumns));
        }}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        onRowClick={(move: StockMoveResource) => navigate(`/stock-moves/${move.id}`)}
        isLoading={movesQuery.isLoading}
        isError={movesQuery.isError}
        errorMessage="Couldn't load the stock ledger."
        emptyMessage="No stock movements match these filters."
        perPage={query.perPage}
        onPerPageChange={query.setPerPage}
        hasPrevious={query.canGoBack}
        hasNext={!!meta?.next_cursor}
        onPrevious={query.goBack}
        onNext={() => query.goToNextCursor(meta?.next_cursor)}
        view={view}
        onViewChange={setView}
        cardColumns={stockMoveColumns}
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
              ...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, stockMoveColumns) ?? query.sort }] : []),
            ]}
          >
            <PrintableTable columns={stockMoveColumns} rows={moves} getRowKey={(move) => move.id} />
          </PrintLayout>
        </div>
      </div>
    </div>
  );
}

interface StockMoveFilterControlsProps {
  items: { id: number; code: string; name: string }[];
  warehouses: { id: number; name: string }[];
  query: ReturnType<typeof useCursorQueryState<StockMoveFilters>>;
  full?: boolean;
}

/** Item/Warehouse/Source-type/date-range controls — shared by the desktop row and the mobile Filters sheet. */
function StockMoveFilterControls({ items, warehouses, query, full }: StockMoveFilterControlsProps) {
  return (
    <>
      <Combobox
        className={full ? 'h-9 w-full' : 'h-9 w-48'}
        aria-label="Item"
        value={query.filters.item_id ?? 'all'}
        onValueChange={(value) => query.setFilter('item_id', value === 'all' ? undefined : value)}
        placeholder="Item"
        searchPlaceholder="Search items…"
        emptyText="No items found."
        options={[
          { value: 'all', label: 'All items' },
          ...items.map((item) => ({ value: String(item.id), label: `${item.code} — ${item.name}` })),
        ]}
      />
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
      <Input
        placeholder="Source type…"
        value={query.filters.source_type ?? ''}
        onChange={(event) => query.setFilter('source_type', event.target.value || undefined)}
        className={full ? 'h-9 w-full' : 'h-9 w-40'}
      />
      <DateField
        aria-label="Moved from"
        value={query.filters.moved_from ?? ''}
        onChange={(value) => query.setFilter('moved_from', value || undefined)}
        className={full ? 'w-full' : undefined}
      />
      <DateField
        aria-label="Moved to"
        value={query.filters.moved_to ?? ''}
        onChange={(value) => query.setFilter('moved_to', value || undefined)}
        className={full ? 'w-full' : undefined}
      />
    </>
  );
}

function describeFilters(
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
