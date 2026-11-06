import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import {
  useRecalculationRequestsIndex,
  type RecalculationRequestResource,
  type RecalculationRequestsIndexSort,
} from '@erp/core-api';

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
import { apiSortToTableSorting, describeSort, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useDebouncedSearch } from '../../lib/use-debounced-search';
import { useListViewPreference } from '../../lib/use-view-preference';
import { recalculationRequestColumns } from './recalculation-request-columns';

interface RecalculationRequestFilters {
  search: string;
  movement_kind: string;
  [key: string]: string;
}

const MOVEMENT_KINDS = ['RECEIPT', 'ISSUE', 'TRANSFER'] as const;

/**
 * The backdated-correction queue. On the screen standard (`erp-blue-design-standard.md` §List). Bulk: only *Print selected* — a recalculation changes recorded cost history, so each request is decided and executed on its own record, never in bulk (screen-delivery-contract.md §Row actions and selection).
 */
export function RecalculationRequestsListScreen() {
  const navigate = useNavigate();
  const { search: listSearch } = useLocation();
  const mayCreate = usePermission('inventory.recalculation.request');
  const query = useListQueryState<RecalculationRequestFilters>({ defaultSort: '-requested_at', defaultPerPage: 25 });
  const searchBox = useDebouncedSearch(query.filters.search, (value) => query.setFilter('search', value));
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    'recalculation-requests-list',
    Object.fromEntries(recalculationRequestColumns.map((column) => [column.key, isColumnVisibleByDefault(column)])),
  );
  const { view, setView } = useListViewPreference('recalculation-requests-list');

  const result = useRecalculationRequestsIndex({
    'filter[movement_kind]': (query.filters.movement_kind as (typeof MOVEMENT_KINDS)[number] | undefined) || undefined,
    'filter[search]': query.filters.search || undefined,
    include: 'item',
    sort: query.sort as RecalculationRequestsIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const meta = result.data?.status === 200 ? result.data.data.meta : undefined;

  const tanStackColumns = useMemo(() => [selectionColumn<RecalculationRequestResource>(), ...toTanStackColumns(recalculationRequestColumns)], []);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  // A selection belongs to the rows it was made on: any change of page, sort, size or filter (all in the URL) clears it.
  useEffect(() => setRowSelection({}), [listSearch]);
  const selected = useMemo(() => rows.filter((row) => rowSelection[String(row.id)]), [rows, rowSelection]);
  const sorting: SortingState = apiSortToTableSorting(query.sort, recalculationRequestColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const { contentRef, print } = useQuickPrint({ documentTitle: 'Stock Recalculation' });
  const { contentRef: selectedRef, print: printSelected } = useQuickPrint({ documentTitle: 'Stock Recalculation (Selected)' });

  const chips: FilterChip[] = [];
  if (query.filters.search) chips.push({ id: 'search', label: `Search: "${query.filters.search}"`, onRemove: () => query.setFilter('search', undefined) });
  if (query.filters.movement_kind) chips.push({ id: 'movement_kind', label: `Movement: ${humanizeCode(query.filters.movement_kind)}`, onRemove: () => query.setFilter('movement_kind', undefined) });
  const filterCount = chips.filter((chip) => chip.id !== 'search').length;
  const clearAll = () => query.setFilters({ search: undefined, movement_kind: undefined });

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Stock Recalculation"
        description="ADR 0040's backdated correction workflow — request, decide, and execute a correction for a receipt, issue or transfer that conflicts with later valuation history."
        primaryAction={mayCreate ? { label: 'New request', onClick: () => navigate({ pathname: '/recalculation-requests/new', search: listSearch }) } : undefined}
      />

      <div className="flex flex-wrap items-center gap-2">
        <ListSearchInput input={searchBox.input} searchProps={searchBox.searchProps} onClear={() => query.setFilter('search', undefined)} placeholder="Search item…" ariaLabel="Search recalculation requests by item" />
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          <FilterSelect label="Movement kind" allLabel="All movement kinds" value={query.filters.movement_kind} options={MOVEMENT_KINDS} onChange={(value) => query.setFilter('movement_kind', value)} className="md:w-52" />
          <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
        </div>
        <ListFiltersSheet activeCount={filterCount} onClear={clearAll}>
          <FilterField label="Movement kind">
            <FilterSelect label="Movement kind (filters sheet)" allLabel="All movement kinds" value={query.filters.movement_kind} options={MOVEMENT_KINDS} onChange={(value) => query.setFilter('movement_kind', value)} />
          </FilterField>
          <PrintTrigger onPrint={() => print()} className="h-11 w-full justify-center" />
        </ListFiltersSheet>
      </div>

      <ActiveFilterChips chips={chips} onClearAll={clearAll} />

      <BulkActionBar count={selected.length} noun="request" onClear={() => setRowSelection({})}>
        <PrintTrigger onPrint={() => printSelected()} label="Print selected" className="h-9 md:h-8" />
      </BulkActionBar>

      <DataTable
        columns={tanStackColumns}
        data={rows}
        rowCount={meta?.total ?? 0}
        recordNoun="requests"
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next = typeof updater === 'function' ? updater(pagination) : updater;
          if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(updater) => query.setSort(tableSortingToApiSort(typeof updater === 'function' ? updater(sorting) : updater, recalculationRequestColumns))}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        getRowId={(row) => String(row.id)}
        onRowClick={(row: RecalculationRequestResource) => navigate({ pathname: `/recalculation-requests/${row.id}`, search: listSearch })}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load stock recalculation requests."
        emptyMessage={chips.length > 0 ? 'No stock recalculation requests match these filters.' : 'No stock recalculation requests yet.'}
        view={view}
        onViewChange={setView}
        cardColumns={recalculationRequestColumns}
      />

      <div className="hidden">
        <div ref={contentRef}>
          <PrintLayout
            title="STOCK RECALCULATION"
            printedAt={new Date()}
            recordCount={rows.length}
            meta={[...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, recalculationRequestColumns) ?? query.sort }] : []), ...chips.map((chip) => ({ label: 'Filter', value: chip.label }))]}
          >
            <PrintableTable columns={recalculationRequestColumns} rows={rows} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
      <div className="hidden">
        <div ref={selectedRef}>
          <PrintLayout title="STOCK RECALCULATION (SELECTED)" printedAt={new Date()} recordCount={selected.length} meta={[{ label: 'Selection', value: `${selected.length} selected requests` }]}>
            <PrintableTable columns={recalculationRequestColumns} rows={selected} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}
