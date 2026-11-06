import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import {
  usePhysicalCountsIndex,
  useWarehousesIndex,
  type PhysicalCountResource,
  type PhysicalCountsIndexSort,
} from '@erp/core-api';

import { Combobox } from '../../components/ui/combobox';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import {
  ActiveFilterChips,
  BulkActionBar,
  DataTable,
  FilterField,
  FilterSelect,
  ListFiltersSheet,
  ListSearchInput,
  humanizeCode,
  isColumnVisibleByDefault,
  selectionColumn,
  toTanStackColumns,
  type FilterChip,
} from '../../components/data-table';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { usePermission } from '../../hooks/use-permission';
import { lookupState } from '../../lib/api-errors';
import { apiSortToTableSorting, describeSort, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useDebouncedSearch } from '../../lib/use-debounced-search';
import { useListViewPreference } from '../../lib/use-view-preference';
import { physicalCountColumns } from './physical-count-columns';

interface PhysicalCountFilters {
  search: string;
  warehouse_id: string;
  status: string;
  [key: string]: string;
}

const STATUSES = ['DRAFT', 'POSTED', 'CANCELLED'] as const;

/**
 * Reconciling recorded stock against what is on hand. On the screen standard (`erp-blue-design-standard.md` §List). Bulk: only *Print selected* — posting a count is a supervised, per-count decision with its own variance review, so no common bulk transition is offered (screen-delivery-contract.md §Row actions and selection).
 */
export function PhysicalCountsListScreen() {
  const navigate = useNavigate();
  const { search: listSearch } = useLocation();
  const mayCreate = usePermission('inventory.physical_count.create');
  const query = useListQueryState<PhysicalCountFilters>({ defaultSort: '-created_at', defaultPerPage: 25 });
  const searchBox = useDebouncedSearch(query.filters.search, (value) => query.setFilter('search', value));
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    'physical-counts-list',
    Object.fromEntries(physicalCountColumns.map((column) => [column.key, isColumnVisibleByDefault(column)])),
  );
  const { view, setView } = useListViewPreference('physical-counts-list');

  const warehousesQuery = useWarehousesIndex({ per_page: 100, sort: 'name' });
  const warehouses = warehousesQuery.data?.status === 200 ? warehousesQuery.data.data.data : [];

  const result = usePhysicalCountsIndex({
    'filter[warehouse_id]': query.filters.warehouse_id ? Number(query.filters.warehouse_id) : undefined,
    'filter[status]': (query.filters.status as (typeof STATUSES)[number] | undefined) || undefined,
    'filter[search]': query.filters.search || undefined,
    include: 'warehouse',
    sort: query.sort as PhysicalCountsIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const meta = result.data?.status === 200 ? result.data.data.meta : undefined;

  const tanStackColumns = useMemo(() => [selectionColumn<PhysicalCountResource>(), ...toTanStackColumns(physicalCountColumns)], []);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  // A selection belongs to the rows it was made on: any change of page, sort, size or filter (all in the URL) clears it.
  useEffect(() => setRowSelection({}), [listSearch]);
  const selected = useMemo(() => rows.filter((row) => rowSelection[String(row.id)]), [rows, rowSelection]);
  const sorting: SortingState = apiSortToTableSorting(query.sort, physicalCountColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const { contentRef, print } = useQuickPrint({ documentTitle: 'Physical Counts' });
  const { contentRef: selectedRef, print: printSelected } = useQuickPrint({ documentTitle: 'Physical Counts (Selected)' });

  const chips: FilterChip[] = [];
  if (query.filters.search) chips.push({ id: 'search', label: `Search: "${query.filters.search}"`, onRemove: () => query.setFilter('search', undefined) });
  if (query.filters.warehouse_id) chips.push({ id: 'warehouse_id', label: `Warehouse: ${warehouses.find((warehouse) => String(warehouse.id) === query.filters.warehouse_id)?.name ?? `#${query.filters.warehouse_id}`}`, onRemove: () => query.setFilter('warehouse_id', undefined) });
  if (query.filters.status) chips.push({ id: 'status', label: `Status: ${humanizeCode(query.filters.status)}`, onRemove: () => query.setFilter('status', undefined) });
  const filterCount = chips.filter((chip) => chip.id !== 'search').length;
  const clearAll = () => query.setFilters({ search: undefined, warehouse_id: undefined, status: undefined });

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Physical Counts"
        description="Reconcile recorded stock against what's physically on hand, and post the difference as an auditable correction."
        primaryAction={mayCreate ? { label: 'New count', onClick: () => navigate({ pathname: '/physical-counts/new', search: listSearch }) } : undefined}
      />

      <div className="flex flex-wrap items-center gap-2">
        <ListSearchInput input={searchBox.input} searchProps={searchBox.searchProps} onClear={() => query.setFilter('search', undefined)} placeholder="Search reference…" ariaLabel="Search counts by reference" />
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          <Combobox
        {...lookupState(warehousesQuery, 'warehouses')}
        className="h-10 w-48"
        aria-label="Warehouse"
        value={query.filters.warehouse_id ?? 'all'}
        onValueChange={(value) => query.setFilter('warehouse_id', value === 'all' ? undefined : value)}
        placeholder="Warehouse"
        searchPlaceholder="Search warehouses…"
        emptyText="No warehouses found."
        options={[{ value: 'all', label: 'All warehouses' }, ...warehouses.map((warehouse) => ({ value: String(warehouse.id), label: warehouse.name }))]}
      />
          <FilterSelect label="Status" allLabel="All statuses" value={query.filters.status} options={STATUSES} onChange={(value) => query.setFilter('status', value)} className="md:w-40" />
          <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
        </div>
        <ListFiltersSheet activeCount={filterCount} onClear={clearAll}>
          <FilterField label="Warehouse">
            <Combobox
        {...lookupState(warehousesQuery, 'warehouses')}
        className="h-11 w-full"
        aria-label="Warehouse (filters sheet)"
        value={query.filters.warehouse_id ?? 'all'}
        onValueChange={(value) => query.setFilter('warehouse_id', value === 'all' ? undefined : value)}
        placeholder="Warehouse"
        searchPlaceholder="Search warehouses…"
        emptyText="No warehouses found."
        options={[{ value: 'all', label: 'All warehouses' }, ...warehouses.map((warehouse) => ({ value: String(warehouse.id), label: warehouse.name }))]}
      />
          </FilterField>
          <FilterField label="Status">
            <FilterSelect label="Status (filters sheet)" allLabel="All statuses" value={query.filters.status} options={STATUSES} onChange={(value) => query.setFilter('status', value)} />
          </FilterField>
          <PrintTrigger onPrint={() => print()} className="h-11 w-full justify-center" />
        </ListFiltersSheet>
      </div>

      <ActiveFilterChips chips={chips} onClearAll={clearAll} />

      <BulkActionBar count={selected.length} noun="count" onClear={() => setRowSelection({})}>
        <PrintTrigger onPrint={() => printSelected()} label="Print selected" className="h-9 md:h-8" />
      </BulkActionBar>

      <DataTable
        columns={tanStackColumns}
        data={rows}
        rowCount={meta?.total ?? 0}
        recordNoun="counts"
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next = typeof updater === 'function' ? updater(pagination) : updater;
          if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(updater) => query.setSort(tableSortingToApiSort(typeof updater === 'function' ? updater(sorting) : updater, physicalCountColumns))}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        getRowId={(row) => String(row.id)}
        onRowClick={(row: PhysicalCountResource) => navigate({ pathname: `/physical-counts/${row.id}`, search: listSearch })}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load physical counts."
        emptyMessage={chips.length > 0 ? 'No physical counts match these filters.' : 'No physical counts yet.'}
        view={view}
        onViewChange={setView}
        cardColumns={physicalCountColumns}
      />

      <div className="hidden">
        <div ref={contentRef}>
          <PrintLayout
            title="PHYSICAL COUNTS"
            printedAt={new Date()}
            recordCount={rows.length}
            meta={[...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, physicalCountColumns) ?? query.sort }] : []), ...chips.map((chip) => ({ label: 'Filter', value: chip.label }))]}
          >
            <PrintableTable columns={physicalCountColumns} rows={rows} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
      <div className="hidden">
        <div ref={selectedRef}>
          <PrintLayout title="PHYSICAL COUNTS (SELECTED)" printedAt={new Date()} recordCount={selected.length} meta={[{ label: 'Selection', value: `${selected.length} selected counts` }]}>
            <PrintableTable columns={physicalCountColumns} rows={selected} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}
