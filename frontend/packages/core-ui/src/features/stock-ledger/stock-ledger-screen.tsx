import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import type { SortingState, VisibilityState } from '@tanstack/react-table';
import { useItemsIndex, useStockMovesIndex, useWarehousesIndex, type StockMoveResource, type StockMovesIndexSort } from '@erp/core-api';

import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { PageHeader } from '../../components/page/page-header';
import { CursorDataTable, toTanStackColumns, isColumnVisibleByDefault } from '../../components/data-table';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { apiSortToTableSorting, describeSort, tableSortingToApiSort, useCursorQueryState } from '../../lib/list-query-state';
import { stockMoveColumns } from './stock-moves-columns';

interface StockMoveFilters {
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
  const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(() =>
    Object.fromEntries(stockMoveColumns.map((column) => [column.key, isColumnVisibleByDefault(column)]))
  );

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

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="Stock Ledger"
        description="Every posted stock movement, oldest cost layer to latest — read-only. Movements are posted by receipts, issues, transfers and QA, never entered here directly."
      />

      <div className="flex flex-wrap items-center gap-2">
        <Select value={query.filters.item_id ?? 'all'} onValueChange={(value) => query.setFilter('item_id', value === 'all' ? undefined : value)}>
          <SelectTrigger className="h-9 w-48">
            <SelectValue placeholder="Item" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All items</SelectItem>
            {items.map((item) => (
              <SelectItem key={item.id} value={String(item.id)}>
                {item.code} — {item.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select
          value={query.filters.warehouse_id ?? 'all'}
          onValueChange={(value) => query.setFilter('warehouse_id', value === 'all' ? undefined : value)}
        >
          <SelectTrigger className="h-9 w-44">
            <SelectValue placeholder="Warehouse" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All warehouses</SelectItem>
            {warehouses.map((warehouse) => (
              <SelectItem key={warehouse.id} value={String(warehouse.id)}>
                {warehouse.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Input
          placeholder="Source type…"
          value={query.filters.source_type ?? ''}
          onChange={(event) => query.setFilter('source_type', event.target.value || undefined)}
          className="h-9 w-40"
        />
        <Input
          type="date"
          aria-label="Moved from"
          value={query.filters.moved_from ?? ''}
          onChange={(event) => query.setFilter('moved_from', event.target.value || undefined)}
          className="h-9 w-40"
        />
        <Input
          type="date"
          aria-label="Moved to"
          value={query.filters.moved_to ?? ''}
          onChange={(event) => query.setFilter('moved_to', event.target.value || undefined)}
          className="h-9 w-40"
        />

        <div className="ml-auto flex items-center gap-2">
          <PrintTrigger onPrint={() => print()} />
        </div>
      </div>

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

  return lines;
}
