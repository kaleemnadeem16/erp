import { useEffect, useMemo, useState } from 'react';
import { History, SlidersHorizontal } from 'lucide-react';
import { Link, useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
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
import { inventoryOverviewColumns } from './inventory-overview-columns';

interface InventoryOverviewFilters {
  category_id: string;
  search: string;
  [key: string]: string;
}

function formatMoney(value: string): string {
  return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * Read-only: on-hand/available/QA-held/on-order/shortage per stocked item.
 * "View ledger" links to the Stock Ledger screen pre-filtered to the item —
 * that screen already has its own warehouse/location drill-down, so this
 * one doesn't duplicate it (see BuildInventoryOverviewQuery's docblock for
 * how each figure is derived).
 */
export function InventoryOverviewScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<InventoryOverviewFilters>({ defaultSort: 'name', defaultPerPage: 25 });
  const uomsQuery = useUomsIndex({ per_page: 100, sort: 'name' });
  const uomLabels = useMemo(
    () =>
      new Map(
        (uomsQuery.data?.status === 200 ? uomsQuery.data.data.data : []).map((uom) => [uom.id, uom.code]),
      ),
    [uomsQuery.data],
  );
  const overviewColumns = useMemo(() => inventoryOverviewColumns(uomLabels), [uomLabels]);
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    'inventory-overview-list',
    Object.fromEntries(overviewColumns.map((column) => [column.key, isColumnVisibleByDefault(column)]))
  );
  const [searchInput, setSearchInput] = useState(query.filters.search ?? '');
  const [filtersOpen, setFiltersOpen] = useState(false);
  const { view, setView } = useListViewPreference('inventory-overview-list');

  useEffect(() => {
    const handle = setTimeout(() => {
      if (searchInput !== (query.filters.search ?? '')) query.setFilter('search', searchInput || undefined);
    }, 300);

    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  const categoriesQuery = useCategoriesIndex({ per_page: 100, sort: 'name' });
  const categories = categoriesQuery.data?.status === 200 ? categoriesQuery.data.data.data : [];

  const overviewQuery = useInventoryOverviewIndex({
    'filter[category_id]': query.filters.category_id ? Number(query.filters.category_id) : undefined,
    'filter[search]': query.filters.search || undefined,
    sort: query.sort as InventoryOverviewIndexSort | undefined,
    include: 'category',
    per_page: query.perPage,
    page: query.page,
  });
  const rows = overviewQuery.data?.status === 200 ? overviewQuery.data.data.data : [];
  const meta = overviewQuery.data?.status === 200 ? overviewQuery.data.data.meta : undefined;

  // Same filters as the table, no pagination/sort — a rollup over the whole
  // filtered set, not just the current page.
  const summaryQuery = useInventoryOverviewSummary({
    'filter[category_id]': query.filters.category_id ? Number(query.filters.category_id) : undefined,
    'filter[search]': query.filters.search || undefined,
  });
  const summary = summaryQuery.data?.status === 200 ? summaryQuery.data.data.data : undefined;

  // overviewColumns now depends on uomLabels (async), unlike the static
  // array this used to memoize — the empty dep array this had before would
  // permanently bake in the first render's still-empty UoM map.
  const columns = useMemo(() => toTanStackColumns(overviewColumns), [overviewColumns]);
  const sorting: SortingState = apiSortToTableSorting(query.sort, overviewColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const { contentRef: printRef, print } = useQuickPrint({ documentTitle: 'Inventory Overview' });

  const activeCategory = categories.find((category) => String(category.id) === query.filters.category_id);
  const filterLines = [
    ...(activeCategory ? [`Category = ${activeCategory.name}`] : []),
    ...(query.filters.search ? [`Search = "${query.filters.search}"`] : []),
  ];
  const activeFilterCount = [query.filters.category_id].filter(Boolean).length;

  function renderRowActions(row: InventoryOverviewResource) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <Button variant="ghost" size="icon" className="h-8 w-8" aria-label="View ledger" asChild>
            <Link to={`/stock-moves?filter.item_id=${row.item_id}`}>
              <History className="h-4 w-4" />
            </Link>
          </Button>
        </TooltipTrigger>
        <TooltipContent>View ledger</TooltipContent>
      </Tooltip>
    );
  }

  return (
    // Extra bottom padding below `md` clears the fixed MobileFab — see
    // PageHeader's `primaryAction` note in docs/architecture/user-interface.md.
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Inventory Overview"
        description="On-hand, available, QA-held, on-order and shortage quantities per stocked item."
      />

      {/* 2-col grid below `md`: `flex-wrap` with `flex-1` cards let each one
          shrink below its money value's content width and ellipsize it —
          a fixed 2-up grid keeps every card wide enough to show the full
          figure instead. */}
      <div className="grid grid-cols-2 gap-3 md:flex md:flex-wrap">
        <StatCard
          label="On-hand value"
          value={summary ? formatMoney(summary.on_hand_value) : '—'}
          tone="info"
          isLoading={summaryQuery.isLoading}
        />
        <StatCard
          label="Available value"
          value={summary ? formatMoney(summary.available_value) : '—'}
          tone="success"
          isLoading={summaryQuery.isLoading}
        />
        <StatCard
          label="Items with shortage"
          value={summary?.shortage_item_count ?? '—'}
          tone="danger"
          zeroIsNeutral
          isLoading={summaryQuery.isLoading}
        />
        <StatCard
          label="Items QA-held"
          value={summary?.qa_held_item_count ?? '—'}
          tone="warning"
          zeroIsNeutral
          isLoading={summaryQuery.isLoading}
        />
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <Input
          placeholder="Search code or name…"
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          className="h-9 flex-1 md:w-56 md:flex-none"
        />

        <div className="hidden items-center gap-2 md:flex">
          <InventoryOverviewFilterControls categories={categories} query={query} />
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
            <InventoryOverviewFilterControls categories={categories} query={query} full />
            <PrintTrigger onPrint={() => print()} className="w-full justify-center" />
          </div>
          <SheetFooter>
            <Button variant="ghost" onClick={() => query.setFilters({ category_id: undefined })}>
              Clear filters
            </Button>
            <Button onClick={() => setFiltersOpen(false)}>Done</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>

      <DataTable
        columns={columns}
        data={rows}
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
          query.setSort(tableSortingToApiSort(next, overviewColumns));
        }}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        onRowClick={(row: InventoryOverviewResource) => navigate(`/items/${row.item_id}`)}
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

      <div className="hidden">
        <div ref={printRef}>
          <PrintLayout
            title="INVENTORY OVERVIEW"
            printedAt={new Date()}
            recordCount={rows.length}
            meta={[
              ...(filterLines.length ? [{ label: 'Filters', value: filterLines.join(', ') }] : []),
              ...(query.sort
                ? [{ label: 'Sort', value: describeSort(query.sort, overviewColumns) ?? query.sort }]
                : []),
            ]}
          >
            <PrintableTable columns={overviewColumns} rows={rows} getRowKey={(row) => row.item_id} />
          </PrintLayout>
        </div>
      </div>
    </div>
  );
}

interface InventoryOverviewFilterControlsProps {
  categories: { id: number; name: string }[];
  query: ReturnType<typeof useListQueryState<InventoryOverviewFilters>>;
  full?: boolean;
}

function InventoryOverviewFilterControls({ categories, query, full }: InventoryOverviewFilterControlsProps) {
  return (
    <Combobox
      className={full ? 'h-9 w-full' : 'h-9 w-40'}
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
  );
}
