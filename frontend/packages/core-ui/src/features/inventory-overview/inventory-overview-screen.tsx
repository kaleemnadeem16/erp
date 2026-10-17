import { useEffect, useMemo, useState } from 'react';
import { History, Package, Search, SlidersHorizontal, X } from 'lucide-react';
import { Link, useLocation, useSearchParams } from 'react-router';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import {
  useCategoriesIndex,
  useInventoryOverviewIndex,
  useInventoryOverviewSummary,
  useUomsIndex,
  type InventoryOverviewIndexSort,
  type InventoryOverviewResource,
} from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Combobox } from '../../components/ui/combobox';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { Tooltip, TooltipContent, TooltipTrigger } from '../../components/ui/tooltip';
import { StatCard } from '../../components/ui/stat-card';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import {
  DataTable,
  isColumnVisibleByDefault,
  selectionColumn,
  toTanStackColumns,
} from '../../components/data-table';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import {
  apiSortToTableSorting,
  describeSort,
  tableSortingToApiSort,
  useListQueryState,
} from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useListViewPreference } from '../../lib/use-view-preference';
import { inventoryOverviewColumns } from './inventory-overview-columns';
import { InventoryOverviewBulkBar } from './inventory-overview-bulk-actions';
import { InventoryOverviewDetailSheet } from './inventory-overview-detail-sheet';
import { lookupState, type LookupState } from '../../lib/api-errors';
import { usePermission } from '../../hooks/use-permission';
import { formatDecimal } from '../../lib/decimal';
import { cn } from '../../lib/utils';

export interface InventoryOverviewFilters {
  category_id: string;
  search: string;
  stock_status: string;
  type: string;
  valuation_method: string;
  tracking: string;
  [key: string]: string;
}

// Stored values carry up to four places; show them, never fewer than two, instead of rounding them to two (base currency places are not known here).
function formatMoney(value: string): string {
  return formatDecimal(value, { minFraction: 2, maxFraction: 4 });
}

const STOCK_STATUS_OPTIONS = [
  { value: 'all', label: 'All stock statuses' },
  { value: 'shortage', label: 'Shortage items' },
  { value: 'qa_held', label: 'QA-held items' },
  { value: 'on_order', label: 'Items on order' },
  { value: 'in_stock', label: 'In stock only' },
  { value: 'out_of_stock', label: 'Out of stock' },
  { value: 'available', label: 'Available stock' },
];

const ITEM_TYPE_OPTIONS = [
  { value: 'all', label: 'All types' },
  { value: 'RAW_MATERIAL', label: 'Raw Material' },
  { value: 'FINISHED_GOOD', label: 'Finished Good' },
  { value: 'COMPONENT', label: 'Component' },
  { value: 'PACKAGING', label: 'Packaging' },
  { value: 'CONSUMABLE', label: 'Consumable' },
];

const VALUATION_OPTIONS = [
  { value: 'all', label: 'All methods' },
  { value: 'AVCO', label: 'AVCO' },
  { value: 'FIFO', label: 'FIFO' },
  { value: 'SPECIFIC', label: 'Specific' },
];

/**
 * Operational inventory read model: on-hand, available, QA-held, on-order,
 * shortage per stocked item with interactive KPI summary cards, multi-faceted
 * filters, row selection, bulk print, and sidepanel detail sheet.
 */
export function InventoryOverviewScreen() {
  const [searchParams, setSearchParams] = useSearchParams();
  const { search: listSearch } = useLocation();
  const mayViewValuation = usePermission('inventory.valuation.view');

  const query = useListQueryState<InventoryOverviewFilters>({ defaultSort: 'name', defaultPerPage: 25 });
  const uomsQuery = useUomsIndex({ per_page: 100, sort: 'name' });
  const uomLabels = useMemo(
    () =>
      new Map(
        (uomsQuery.data?.status === 200 ? uomsQuery.data.data.data : []).map((uom) => [uom.id, uom.code]),
      ),
    [uomsQuery.data],
  );

  const overviewColumns = useMemo(
    () =>
      inventoryOverviewColumns(uomLabels).filter(
        (column) => mayViewValuation || (column.key !== 'on_hand_value' && column.key !== 'available_value'),
      ),
    [uomLabels, mayViewValuation],
  );

  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    'inventory-overview-list',
    Object.fromEntries(overviewColumns.map((column) => [column.key, isColumnVisibleByDefault(column)])),
  );

  const [searchInput, setSearchInput] = useState(query.filters.search ?? '');
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  const [activeItem, setActiveItem] = useState<InventoryOverviewResource | null>(null);

  const { view, setView } = useListViewPreference('inventory-overview-list');

  useEffect(() => {
    const handle = setTimeout(() => {
      if (searchInput !== (query.filters.search ?? '')) query.setFilter('search', searchInput || undefined);
    }, 300);

    return () => clearTimeout(handle);
  }, [searchInput]);

  const categoriesQuery = useCategoriesIndex({ per_page: 100, sort: 'name' });
  const categories = categoriesQuery.data?.status === 200 ? categoriesQuery.data.data.data : [];

  const overviewQuery = useInventoryOverviewIndex({
    'filter[category_id]': query.filters.category_id ? Number(query.filters.category_id) : undefined,
    'filter[search]': query.filters.search || undefined,
    'filter[stock_status]': query.filters.stock_status as any,
    'filter[type]': query.filters.type as any,
    'filter[valuation_method]': query.filters.valuation_method as any,
    sort: query.sort as InventoryOverviewIndexSort | undefined,
    include: 'category',
    per_page: query.perPage,
    page: query.page,
  });

  const rows = overviewQuery.data?.status === 200 ? overviewQuery.data.data.data : [];
  const meta = overviewQuery.data?.status === 200 ? overviewQuery.data.data.meta : undefined;

  // Rollup over the whole filtered set
  const summaryQuery = useInventoryOverviewSummary({
    'filter[category_id]': query.filters.category_id ? Number(query.filters.category_id) : undefined,
    'filter[search]': query.filters.search || undefined,
    'filter[stock_status]': query.filters.stock_status as any,
    'filter[type]': query.filters.type as any,
    'filter[valuation_method]': query.filters.valuation_method as any,
  });
  const summary = summaryQuery.data?.status === 200 ? summaryQuery.data.data.data : undefined;

  // Clear row selection when filters or pagination change
  useEffect(() => {
    setRowSelection({});
  }, [listSearch, query.page, query.perPage]);

  // Handle selected item from URL param
  const selectedItemId = searchParams.get('item_id');
  const selectedItem = useMemo(() => {
    if (activeItem && (!selectedItemId || String(activeItem.item_id) === selectedItemId)) {
      return activeItem;
    }
    if (selectedItemId) {
      return rows.find((r) => String(r.item_id) === selectedItemId) ?? null;
    }
    return null;
  }, [activeItem, selectedItemId, rows]);

  const handleSelectRow = (row: InventoryOverviewResource) => {
    setActiveItem(row);
    const next = new URLSearchParams(searchParams);
    next.set('item_id', String(row.item_id));
    setSearchParams(next, { replace: true });
  };

  const handleCloseDetail = () => {
    setActiveItem(null);
    const next = new URLSearchParams(searchParams);
    next.delete('item_id');
    setSearchParams(next, { replace: true });
  };

  const tanStackColumns = useMemo(
    () => [selectionColumn<InventoryOverviewResource>(), ...toTanStackColumns(overviewColumns)],
    [overviewColumns],
  );

  const selectedRows = useMemo(
    () =>
      Object.keys(rowSelection)
        .filter((id) => rowSelection[id])
        .map((id) => rows.find((row) => String(row.item_id) === id))
        .filter((row): row is InventoryOverviewResource => Boolean(row)),
    [rowSelection, rows],
  );

  const sorting: SortingState = apiSortToTableSorting(query.sort, overviewColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  const rowsToPrint = selectedRows.length > 0 ? selectedRows : rows;
  const printTitle =
    selectedRows.length > 0 ? 'INVENTORY OVERVIEW (SELECTED ITEMS)' : 'INVENTORY OVERVIEW';
  const { contentRef: printRef, print } = useQuickPrint({ documentTitle: printTitle });

  const activeCategory = categories.find((c) => String(c.id) === query.filters.category_id);
  const activeStockStatus = STOCK_STATUS_OPTIONS.find((s) => s.value === query.filters.stock_status);
  const activeType = ITEM_TYPE_OPTIONS.find((t) => t.value === query.filters.type);
  const activeValuation = VALUATION_OPTIONS.find((v) => v.value === query.filters.valuation_method);

  const filterLines = [
    ...(activeCategory ? [`Category = ${activeCategory.name}`] : []),
    ...(query.filters.search ? [`Search = "${query.filters.search}"`] : []),
    ...(activeStockStatus && activeStockStatus.value !== 'all'
      ? [`Stock Status = ${activeStockStatus.label}`]
      : []),
    ...(activeType && activeType.value !== 'all' ? [`Type = ${activeType.label}`] : []),
    ...(activeValuation && activeValuation.value !== 'all'
      ? [`Valuation = ${activeValuation.label}`]
      : []),
  ];

  const activeFilters = [
    query.filters.category_id && {
      key: 'category_id',
      label: `Category: ${activeCategory?.name ?? query.filters.category_id}`,
      onRemove: () => query.setFilter('category_id', undefined),
    },
    query.filters.stock_status && {
      key: 'stock_status',
      label: `Status: ${activeStockStatus?.label ?? query.filters.stock_status}`,
      onRemove: () => query.setFilter('stock_status', undefined),
    },
    query.filters.type && {
      key: 'type',
      label: `Type: ${activeType?.label ?? query.filters.type}`,
      onRemove: () => query.setFilter('type', undefined),
    },
    query.filters.valuation_method && {
      key: 'valuation_method',
      label: `Valuation: ${activeValuation?.label ?? query.filters.valuation_method}`,
      onRemove: () => query.setFilter('valuation_method', undefined),
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
    query.setFilters({
      category_id: undefined,
      search: undefined,
      stock_status: undefined,
      type: undefined,
      valuation_method: undefined,
    });
  };

  const activeFilterCount = [
    query.filters.category_id,
    query.filters.stock_status,
    query.filters.type,
    query.filters.valuation_method,
  ].filter(Boolean).length;

  function renderRowActions(row: InventoryOverviewResource) {
    return (
      <div className="flex items-center gap-1">
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8"
              aria-label="View ledger"
              asChild
            >
              <Link to={`/stock-moves?filter.item_id=${row.item_id}`}>
                <History className="h-4 w-4" />
              </Link>
            </Button>
          </TooltipTrigger>
          <TooltipContent>View ledger</TooltipContent>
        </Tooltip>

        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8"
              aria-label="View item master"
              asChild
            >
              <Link to={`/items/${row.item_id}`}>
                <Package className="h-4 w-4" />
              </Link>
            </Button>
          </TooltipTrigger>
          <TooltipContent>View item master</TooltipContent>
        </Tooltip>
      </div>
    );
  }

  return (
    <ScreenTemplate
      kind="list"
      className="erp-screen space-y-4 p-4 pb-24 md:p-6"
      header={
        <PageHeader
          title="Inventory Overview"
          description="On-hand, available, QA-held, on-order and shortage quantities per stocked item."
        />
      }
    >
      {/* Interactive KPI Summary Cards */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <StatCard
          label="Total items"
          value={summary?.item_count ?? '—'}
          tone="default"
          isLoading={summaryQuery.isLoading}
          active={!query.filters.stock_status}
          onClick={() => query.setFilter('stock_status', undefined)}
          hint="Click to clear status filter"
        />

        {mayViewValuation && (
          <StatCard
            label="On-hand value"
            value={summary?.on_hand_value != null ? formatMoney(summary.on_hand_value) : '—'}
            tone="info"
            isLoading={summaryQuery.isLoading}
          />
        )}

        {mayViewValuation && (
          <StatCard
            label="Available value"
            value={summary?.available_value != null ? formatMoney(summary.available_value) : '—'}
            tone="success"
            isLoading={summaryQuery.isLoading}
          />
        )}

        <StatCard
          label="Items with shortage"
          value={summary?.shortage_item_count ?? '—'}
          tone="danger"
          zeroIsNeutral
          isLoading={summaryQuery.isLoading}
          active={query.filters.stock_status === 'shortage'}
          onClick={() =>
            query.setFilter(
              'stock_status',
              query.filters.stock_status === 'shortage' ? undefined : 'shortage',
            )
          }
          hint="Filter shortage"
        />

        <StatCard
          label="Items QA-held"
          value={summary?.qa_held_item_count ?? '—'}
          tone="warning"
          zeroIsNeutral
          isLoading={summaryQuery.isLoading}
          active={query.filters.stock_status === 'qa_held'}
          onClick={() =>
            query.setFilter(
              'stock_status',
              query.filters.stock_status === 'qa_held' ? undefined : 'qa_held',
            )
          }
          hint="Filter QA-held"
        />

        <StatCard
          label="Items on order"
          value={(summary as { on_order_item_count?: number })?.on_order_item_count ?? '—'}
          tone="info"
          zeroIsNeutral
          isLoading={summaryQuery.isLoading}
          active={query.filters.stock_status === 'on_order'}
          onClick={() =>
            query.setFilter(
              'stock_status',
              query.filters.stock_status === 'on_order' ? undefined : 'on_order',
            )
          }
          hint="Filter on order"
        />
      </div>

      {/* Filter and Action Bar */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative flex-1 md:w-64 md:flex-none">
          <Search
            aria-hidden="true"
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
          />
          <Input
            className="h-10 pl-9 pr-9"
            placeholder="Search code or name…"
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

        <div className="hidden items-center gap-2 md:flex">
          <InventoryOverviewFilterControls
            categoriesLookup={lookupState(categoriesQuery, 'categories')}
            categories={categories}
            query={query}
          />
          <PrintTrigger onPrint={() => print()} />
        </div>

        <Button
          variant={filtersOpen || activeFilterCount > 0 ? 'secondary' : 'outline'}
          size="sm"
          className={cn(
            'relative h-10 shrink-0 px-3 md:hidden',
            (filtersOpen || activeFilterCount > 0) && 'ui-btn-filters-active',
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
          <span className="text-xs text-muted-foreground mr-1">Active filters:</span>
          {activeFilters.map((chip) => (
            <span
              key={chip.key}
              className="ui-chip-active inline-flex items-center gap-1 rounded-md border border-border bg-secondary/50 px-2 py-0.5 text-xs text-foreground"
            >
              <span>{chip.label}</span>
              <Tooltip>
                <TooltipTrigger asChild>
                  <button
                    type="button"
                    onClick={chip.onRemove}
                    className="ml-0.5 cursor-pointer rounded-full p-0.5 text-muted-foreground hover:bg-background hover:text-foreground focus:outline-none focus:ring-1 focus:ring-ring"
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
            className="ml-1 h-6 px-2 text-xs text-muted-foreground hover:text-foreground"
          >
            Clear all
          </Button>
        </div>
      )}

      {/* Mobile Filter Sheet */}
      <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
        <SheetContent side="bottom" className="md:hidden">
          <SheetHeader>
            <SheetTitle>Filters</SheetTitle>
          </SheetHeader>
          <div className="flex flex-col gap-3 py-4">
            <InventoryOverviewFilterControls
              categoriesLookup={lookupState(categoriesQuery, 'categories')}
              categories={categories}
              query={query}
              full
            />
            <PrintTrigger onPrint={() => print()} className="w-full justify-center" />
          </div>
          <SheetFooter>
            <Button variant="ghost" onClick={clearAllFilters}>
              Clear filters
            </Button>
            <Button onClick={() => setFiltersOpen(false)}>Done</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>

      {/* Bulk action toolbar */}
      <InventoryOverviewBulkBar
        selectedRows={selectedRows}
        onClearSelection={() => setRowSelection({})}
        onPrintSelected={() => print()}
      />

      {/* Data Table */}
      <DataTable
        columns={tanStackColumns}
        data={rows}
        rowCount={meta?.total ?? 0}
        getRowId={(row) => String(row.item_id)}
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
          query.setSort(tableSortingToApiSort(next, overviewColumns));
        }}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        onRowClick={(row: InventoryOverviewResource) => handleSelectRow(row)}
        isLoading={overviewQuery.isLoading}
        isError={overviewQuery.isError}
        error={overviewQuery.error}
        onRetry={() => void overviewQuery.refetch()}
        errorMessage="Couldn't load the inventory overview."
        emptyMessage="No stocked items match these filters."
        view={view}
        onViewChange={setView}
        cardColumns={overviewColumns}
        rowActions={renderRowActions}
      />

      {/* Detail RecordSheet */}
      <InventoryOverviewDetailSheet
        item={selectedItem}
        open={Boolean(selectedItem)}
        onClose={handleCloseDetail}
        uomLabel={selectedItem ? uomLabels.get(selectedItem.stock_uom_id) : undefined}
        mayViewValuation={mayViewValuation}
      />

      {/* Hidden Print Container */}
      <div className="hidden">
        <div ref={printRef}>
          <PrintLayout
            title={printTitle}
            printedAt={new Date()}
            recordCount={rowsToPrint.length}
            meta={[
              ...(filterLines.length ? [{ label: 'Filters', value: filterLines.join(', ') }] : []),
              ...(query.sort
                ? [{ label: 'Sort', value: describeSort(query.sort, overviewColumns) ?? query.sort }]
                : []),
            ]}
          >
            <PrintableTable columns={overviewColumns} rows={rowsToPrint} getRowKey={(row) => row.item_id} />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}

interface InventoryOverviewFilterControlsProps {
  categoriesLookup: LookupState;
  categories: { id: number; name: string }[];
  query: ReturnType<typeof useListQueryState<InventoryOverviewFilters>>;
  full?: boolean;
}

function InventoryOverviewFilterControls({
  categoriesLookup,
  categories,
  query,
  full,
}: InventoryOverviewFilterControlsProps) {
  const widthClass = full ? 'h-9 w-full' : 'h-9 w-36';

  return (
    <div className={cn('flex flex-wrap items-center gap-2', full && 'flex-col items-stretch')}>
      <Combobox
        {...categoriesLookup}
        className={widthClass}
        aria-label="Category"
        value={query.filters.category_id ?? 'all'}
        onValueChange={(value) => query.setFilter('category_id', value === 'all' ? undefined : value)}
        placeholder="Category"
        searchPlaceholder="Search categories…"
        emptyText="No categories found."
        options={[
          { value: 'all', label: 'All categories' },
          ...categories.map((category) => ({ value: String(category.id), label: category.name })),
        ]}
      />

      <Combobox
        className={widthClass}
        aria-label="Stock status"
        value={query.filters.stock_status ?? 'all'}
        onValueChange={(value) => query.setFilter('stock_status', value === 'all' ? undefined : value)}
        placeholder="Stock status"
        options={STOCK_STATUS_OPTIONS}
      />

      <Combobox
        className={widthClass}
        aria-label="Item type"
        value={query.filters.type ?? 'all'}
        onValueChange={(value) => query.setFilter('type', value === 'all' ? undefined : value)}
        placeholder="Item type"
        options={ITEM_TYPE_OPTIONS}
      />

      <Combobox
        className={widthClass}
        aria-label="Valuation method"
        value={query.filters.valuation_method ?? 'all'}
        onValueChange={(value) => query.setFilter('valuation_method', value === 'all' ? undefined : value)}
        placeholder="Valuation"
        options={VALUATION_OPTIONS}
      />
    </div>
  );
}
