import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import {
  useInventoryCutoversIndex,
  type InventoryCutoverResource,
  type InventoryCutoversIndexSort,
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
import { useListViewPreference } from '../../lib/use-view-preference';
import { inventoryCutoverColumns } from './inventory-cutover-columns';

interface InventoryCutoverFilters {
  status: string;
  [key: string]: string;
}

const STATUSES = ['DRAFT', 'VALIDATED', 'APPROVED', 'FINALISED', 'FAILED'] as const;

/**
 * Controlled opening-balance imports. On the screen standard (`erp-blue-design-standard.md` §List). Bulk: only *Print selected* — staging, approving and committing a cutover are one-at-a-time, irreversible steps in the cutover record's own workspace, so no common bulk transition is offered (screen-delivery-contract.md §Row actions and selection).
 */
export function InventoryCutoversListScreen() {
  const navigate = useNavigate();
  const { search: listSearch } = useLocation();
  const mayCreate = usePermission('inventory.cutover.create');
  const query = useListQueryState<InventoryCutoverFilters>({ defaultSort: '-created_at', defaultPerPage: 25 });
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    'inventory-cutovers-list',
    Object.fromEntries(inventoryCutoverColumns.map((column) => [column.key, isColumnVisibleByDefault(column)])),
  );
  const { view, setView } = useListViewPreference('inventory-cutovers-list');

  const result = useInventoryCutoversIndex({
    'filter[status]': (query.filters.status as (typeof STATUSES)[number] | undefined) || undefined,
    sort: query.sort as InventoryCutoversIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const meta = result.data?.status === 200 ? result.data.data.meta : undefined;

  const tanStackColumns = useMemo(() => [selectionColumn<InventoryCutoverResource>(), ...toTanStackColumns(inventoryCutoverColumns)], []);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  // A selection belongs to the rows it was made on: any change of page, sort, size or filter (all in the URL) clears it.
  useEffect(() => setRowSelection({}), [listSearch]);
  const selected = useMemo(() => rows.filter((row) => rowSelection[String(row.id)]), [rows, rowSelection]);
  const sorting: SortingState = apiSortToTableSorting(query.sort, inventoryCutoverColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const { contentRef, print } = useQuickPrint({ documentTitle: 'Inventory Cutovers' });
  const { contentRef: selectedRef, print: printSelected } = useQuickPrint({ documentTitle: 'Inventory Cutovers (Selected)' });

  const chips: FilterChip[] = [];
  if (query.filters.status) chips.push({ id: 'status', label: `Status: ${humanizeCode(query.filters.status)}`, onRemove: () => query.setFilter('status', undefined) });
  const filterCount = chips.filter((chip) => chip.id !== 'search').length;
  const clearAll = () => query.setFilters({ status: undefined });

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Inventory Cutovers"
        description="Bring in a predecessor system's opening stock balances under a controlled, auditable workflow."
        primaryAction={mayCreate ? { label: 'New cutover', onClick: () => navigate({ pathname: '/inventory-cutovers/new', search: listSearch }) } : undefined}
      />

      <div className="flex flex-wrap items-center gap-2">
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          <FilterSelect label="Status" allLabel="All statuses" value={query.filters.status} options={STATUSES} onChange={(value) => query.setFilter('status', value)} className="md:w-44" />
          <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
        </div>
        <ListFiltersSheet activeCount={filterCount} onClear={clearAll}>
          <FilterField label="Status">
            <FilterSelect label="Status (filters sheet)" allLabel="All statuses" value={query.filters.status} options={STATUSES} onChange={(value) => query.setFilter('status', value)} />
          </FilterField>
          <PrintTrigger onPrint={() => print()} className="h-11 w-full justify-center" />
        </ListFiltersSheet>
      </div>

      <ActiveFilterChips chips={chips} onClearAll={clearAll} />

      <BulkActionBar count={selected.length} noun="cutover" onClear={() => setRowSelection({})}>
        <PrintTrigger onPrint={() => printSelected()} label="Print selected" className="h-9 md:h-8" />
      </BulkActionBar>

      <DataTable
        columns={tanStackColumns}
        data={rows}
        rowCount={meta?.total ?? 0}
        recordNoun="cutovers"
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next = typeof updater === 'function' ? updater(pagination) : updater;
          if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(updater) => query.setSort(tableSortingToApiSort(typeof updater === 'function' ? updater(sorting) : updater, inventoryCutoverColumns))}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        getRowId={(row) => String(row.id)}
        onRowClick={(row: InventoryCutoverResource) => navigate({ pathname: `/inventory-cutovers/${row.id}`, search: listSearch })}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load inventory cutovers."
        emptyMessage={chips.length > 0 ? 'No inventory cutovers match these filters.' : 'No inventory cutovers yet.'}
        view={view}
        onViewChange={setView}
        cardColumns={inventoryCutoverColumns}
      />

      <div className="hidden">
        <div ref={contentRef}>
          <PrintLayout
            title="INVENTORY CUTOVERS"
            printedAt={new Date()}
            recordCount={rows.length}
            meta={[...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, inventoryCutoverColumns) ?? query.sort }] : []), ...chips.map((chip) => ({ label: 'Filter', value: chip.label }))]}
          >
            <PrintableTable columns={inventoryCutoverColumns} rows={rows} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
      <div className="hidden">
        <div ref={selectedRef}>
          <PrintLayout title="INVENTORY CUTOVERS (SELECTED)" printedAt={new Date()} recordCount={selected.length} meta={[{ label: 'Selection', value: `${selected.length} selected cutovers` }]}>
            <PrintableTable columns={inventoryCutoverColumns} rows={selected} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}
