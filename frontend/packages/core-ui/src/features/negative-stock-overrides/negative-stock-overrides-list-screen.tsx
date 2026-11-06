import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import {
  useNegativeStockOverrideRequestsIndex,
  type NegativeStockOverrideRequestResource,
  type NegativeStockOverrideRequestsIndexSort,
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
import { negativeStockOverrideColumns } from './negative-stock-override-columns';

interface NegativeStockOverrideFilters {
  search: string;
  movement_class: string;
  authorisation_mode: string;
  [key: string]: string;
}

const MOVEMENT_CLASSES = ['SALES_DESPATCH', 'MATERIAL_CONSUMPTION', 'SUPPLIER_RETURN', 'SCRAP_WRITEOFF'] as const;
const AUTHORISATION_MODES = ['PRIOR_APPROVAL', 'IMMEDIATE_WITH_REVIEW'] as const;

/**
 * The authorisation queue for negative-stock overrides, on the screen standard (`erp-blue-design-standard.md` §List). Bulk: only *Print selected* — a decision or a
 * review is a controlled, per-request judgement made in the request's own record, so no common bulk transition is offered (screen-delivery-contract.md §Row actions).
 */
export function NegativeStockOverridesListScreen() {
  const navigate = useNavigate();
  const { search: listSearch } = useLocation();
  // The same two duties the API accepts for creating a request (StoreNegativeStockOverrideRequestRequest).
  const mayRequestOrdinarily = usePermission('inventory.negative-stock.request');
  const mayOverride = usePermission('inventory.negative-stock.override');
  const mayRequest = mayRequestOrdinarily || mayOverride;
  const query = useListQueryState<NegativeStockOverrideFilters>({ defaultSort: '-requested_at', defaultPerPage: 25 });
  const searchBox = useDebouncedSearch(query.filters.search, (value) => query.setFilter('search', value));
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    'negative-stock-overrides-list',
    Object.fromEntries(negativeStockOverrideColumns.map((column) => [column.key, isColumnVisibleByDefault(column)])),
  );
  const { view, setView } = useListViewPreference('negative-stock-overrides-list');

  const overridesQuery = useNegativeStockOverrideRequestsIndex({
    'filter[movement_class]': (query.filters.movement_class as (typeof MOVEMENT_CLASSES)[number] | undefined) || undefined,
    'filter[authorisation_mode]': (query.filters.authorisation_mode as (typeof AUTHORISATION_MODES)[number] | undefined) || undefined,
    'filter[search]': query.filters.search || undefined,
    sort: query.sort as NegativeStockOverrideRequestsIndexSort | undefined,
    // approval/review are what the status column derives its label from (see overrideStatus): without
    // them every request reads as pending, whatever has actually been decided or reviewed.
    include: 'item,approval,review',
    per_page: query.perPage,
    page: query.page,
  });
  const overrides = overridesQuery.data?.status === 200 ? overridesQuery.data.data.data : [];
  const meta = overridesQuery.data?.status === 200 ? overridesQuery.data.data.meta : undefined;

  const tanStackColumns = useMemo(() => [selectionColumn<NegativeStockOverrideRequestResource>(), ...toTanStackColumns(negativeStockOverrideColumns)], []);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  // A selection belongs to the rows it was made on: any change of page, sort, size or filter (all in the URL) clears it.
  useEffect(() => setRowSelection({}), [listSearch]);
  const selected = useMemo(() => overrides.filter((override) => rowSelection[String(override.id)]), [overrides, rowSelection]);
  const sorting: SortingState = apiSortToTableSorting(query.sort, negativeStockOverrideColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const { contentRef, print } = useQuickPrint({ documentTitle: 'Negative-Stock Overrides' });
  const { contentRef: selectedRef, print: printSelected } = useQuickPrint({ documentTitle: 'Negative-Stock Overrides (Selected)' });

  const chips: FilterChip[] = [];
  if (query.filters.search) chips.push({ id: 'search', label: `Search: "${query.filters.search}"`, onRemove: () => query.setFilter('search', undefined) });
  if (query.filters.movement_class) chips.push({ id: 'movement_class', label: `Movement: ${humanizeCode(query.filters.movement_class)}`, onRemove: () => query.setFilter('movement_class', undefined) });
  if (query.filters.authorisation_mode) chips.push({ id: 'authorisation_mode', label: `Mode: ${humanizeCode(query.filters.authorisation_mode)}`, onRemove: () => query.setFilter('authorisation_mode', undefined) });
  const filterCount = chips.filter((chip) => chip.id !== 'search').length;
  const clearAll = () => query.setFilters({ search: undefined, movement_class: undefined, authorisation_mode: undefined });

  const controls = (
    <>
      <FilterSelect label="Movement class" allLabel="All movement classes" value={query.filters.movement_class} options={MOVEMENT_CLASSES} onChange={(value) => query.setFilter('movement_class', value)} className="md:w-56" />
      <FilterSelect label="Authorisation mode" allLabel="All modes" value={query.filters.authorisation_mode} options={AUTHORISATION_MODES} onChange={(value) => query.setFilter('authorisation_mode', value)} className="md:w-52" />
    </>
  );

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Negative-Stock Overrides"
        description="Authorisation queue for a genuine shortage — requested, then decided or reviewed depending on company policy."
        primaryAction={mayRequest ? { label: 'New request', onClick: () => navigate({ pathname: '/negative-stock-overrides/new', search: listSearch }) } : undefined}
      />

      <div className="flex flex-wrap items-center gap-2">
        <ListSearchInput input={searchBox.input} searchProps={searchBox.searchProps} onClear={() => query.setFilter('search', undefined)} placeholder="Search item…" ariaLabel="Search overrides by item" />
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          {controls}
          <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
        </div>
        <ListFiltersSheet activeCount={filterCount} onClear={clearAll}>
          <FilterField label="Movement class">
            <FilterSelect label="Movement class (filters sheet)" allLabel="All movement classes" value={query.filters.movement_class} options={MOVEMENT_CLASSES} onChange={(value) => query.setFilter('movement_class', value)} />
          </FilterField>
          <FilterField label="Authorisation mode">
            <FilterSelect label="Authorisation mode (filters sheet)" allLabel="All modes" value={query.filters.authorisation_mode} options={AUTHORISATION_MODES} onChange={(value) => query.setFilter('authorisation_mode', value)} />
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
        data={overrides}
        rowCount={meta?.total ?? 0}
        recordNoun="requests"
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next = typeof updater === 'function' ? updater(pagination) : updater;
          if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(updater) => query.setSort(tableSortingToApiSort(typeof updater === 'function' ? updater(sorting) : updater, negativeStockOverrideColumns))}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        getRowId={(override) => String(override.id)}
        onRowClick={(override: NegativeStockOverrideRequestResource) => navigate({ pathname: `/negative-stock-overrides/${override.id}`, search: listSearch })}
        isLoading={overridesQuery.isLoading}
        isError={overridesQuery.isError}
        error={overridesQuery.error}
        onRetry={() => void overridesQuery.refetch()}
        errorMessage="Couldn't load negative-stock override requests."
        emptyMessage={chips.length > 0 ? 'No negative-stock override requests match these filters.' : 'No negative-stock override requests yet.'}
        view={view}
        onViewChange={setView}
        cardColumns={negativeStockOverrideColumns}
      />

      <div className="hidden">
        <div ref={contentRef}>
          <PrintLayout
            title="NEGATIVE-STOCK OVERRIDES"
            printedAt={new Date()}
            recordCount={overrides.length}
            meta={[...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, negativeStockOverrideColumns) ?? query.sort }] : []), ...chips.map((chip) => ({ label: 'Filter', value: chip.label }))]}
          >
            <PrintableTable columns={negativeStockOverrideColumns} rows={overrides} getRowKey={(override) => override.id} />
          </PrintLayout>
        </div>
      </div>
      <div className="hidden">
        <div ref={selectedRef}>
          <PrintLayout title="NEGATIVE-STOCK OVERRIDES (SELECTED)" printedAt={new Date()} recordCount={selected.length} meta={[{ label: 'Selection', value: `${selected.length} selected requests` }]}>
            <PrintableTable columns={negativeStockOverrideColumns} rows={selected} getRowKey={(override) => override.id} />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}
