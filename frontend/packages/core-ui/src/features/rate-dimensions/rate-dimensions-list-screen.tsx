import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import { useRateDimensionsIndex, type RateDimensionResource, type RateDimensionsIndexSort } from '@erp/core-api';

import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import {
  ActiveFilterChips,
  BulkActionBar,
  DataTable,
  ListFiltersSheet,
  ListSearchInput,
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
import { rateDimensionColumns } from './rate-dimensions-columns';

interface Filters {
  search: string;
  [key: string]: string;
}

/**
 * The company-defined axes application rates vary by. On the screen standard (`erp-blue-design-standard.md` §List): URL-held search, chips, selection with Print selected, phone cards and a Filters
 * sheet, error vs empty states. Search is the only filter its endpoint validates, so there is nothing more to expose. Bulk: only *Print selected* — a change to
 * rate dimensions is made in the record's own form. Its create and edit forms are still pages (listed in the screens-completion log as remaining work).
 */
export function RateDimensionsListScreen() {
  const mayManage = usePermission('estimating.rate-dimension.manage');
  const navigate = useNavigate();
  const { search: listSearch } = useLocation();
  const query = useListQueryState<Filters>({ defaultSort: 'sort_order' });
  const searchBox = useDebouncedSearch(query.filters.search, (value) => query.setFilter('search', value));
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference('rate-dimensions-list', Object.fromEntries(rateDimensionColumns.map((column) => [column.key, isColumnVisibleByDefault(column)])));
  const { view, setView } = useListViewPreference('rate-dimensions-list');
  const result = useRateDimensionsIndex({
    'filter[search]': query.filters.search || undefined,
    include: 'values',
    sort: query.sort as RateDimensionsIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const tanStackColumns = useMemo(() => [selectionColumn<RateDimensionResource>(), ...toTanStackColumns(rateDimensionColumns)], []);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  useEffect(() => setRowSelection({}), [listSearch]);
  const selected = useMemo(() => rows.filter((row) => rowSelection[String(row.id)]), [rows, rowSelection]);
  const sorting: SortingState = apiSortToTableSorting(query.sort, rateDimensionColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const { contentRef, print } = useQuickPrint({ documentTitle: 'Rate dimensions' });
  const { contentRef: selectedRef, print: printSelected } = useQuickPrint({ documentTitle: 'Rate dimensions (Selected)' });

  const chips: FilterChip[] = [];
  if (query.filters.search) chips.push({ id: 'search', label: `Search: "${query.filters.search}"`, onRemove: () => query.setFilter('search', undefined) });
  const clearAll = () => query.setFilters({ search: undefined });

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Rate dimensions"
        description="Company-defined axes application rates can vary by — e.g. Surface Type, Thickness."
        primaryAction={mayManage ? { label: 'New dimension', onClick: () => navigate({ pathname: '/rate-dimensions/new', search: listSearch }) } : undefined}
      />

      <div className="flex flex-wrap items-center gap-2">
        <ListSearchInput input={searchBox.input} searchProps={searchBox.searchProps} onClear={() => query.setFilter('search', undefined)} placeholder="Search code or name…" ariaLabel="Search rate dimensions" />
        <div className="hidden items-center gap-2 md:flex">
          <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
        </div>
        <ListFiltersSheet activeCount={0} onClear={clearAll}>
          <PrintTrigger onPrint={() => print()} className="h-11 w-full justify-center" />
        </ListFiltersSheet>
      </div>

      <ActiveFilterChips chips={chips} onClearAll={clearAll} />

      <BulkActionBar count={selected.length} noun="dimension" onClear={() => setRowSelection({})}>
        <PrintTrigger onPrint={() => printSelected()} label="Print selected" className="h-9 md:h-8" />
      </BulkActionBar>

      <DataTable
        columns={tanStackColumns}
        data={rows}
        rowCount={total}
        recordNoun="rate dimensions"
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next = typeof updater === 'function' ? updater(pagination) : updater;
          if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(updater) => query.setSort(tableSortingToApiSort(typeof updater === 'function' ? updater(sorting) : updater, rateDimensionColumns))}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        getRowId={(row) => String(row.id)}
        onRowClick={mayManage ? (row: RateDimensionResource) => navigate({ pathname: `/rate-dimensions/${row.id}/edit`, search: listSearch }) : undefined}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load rate dimensions."
        emptyMessage={chips.length > 0 ? 'No rate dimensions match this search.' : 'No rate dimensions yet.'}
        view={view}
        onViewChange={setView}
        cardColumns={rateDimensionColumns}
      />

      <div className="hidden">
        <div ref={contentRef}>
          <PrintLayout
            title="RATE DIMENSIONS"
            printedAt={new Date()}
            recordCount={rows.length}
            meta={[...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, rateDimensionColumns) ?? query.sort }] : []), ...chips.map((chip) => ({ label: 'Filter', value: chip.label }))]}
          >
            <PrintableTable columns={rateDimensionColumns} rows={rows} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
      <div className="hidden">
        <div ref={selectedRef}>
          <PrintLayout title="RATE DIMENSIONS (SELECTED)" printedAt={new Date()} recordCount={selected.length} meta={[{ label: 'Selection', value: `${selected.length} selected rate dimensions` }]}>
            <PrintableTable columns={rateDimensionColumns} rows={selected} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}
