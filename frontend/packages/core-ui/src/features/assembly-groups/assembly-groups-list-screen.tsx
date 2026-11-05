import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import { useAssemblyGroupsIndex, type AssemblyGroupResource, type AssemblyGroupsIndexSort } from '@erp/core-api';

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
import { assemblyGroupColumns } from './assembly-groups-columns';

interface Filters {
  search: string;
  [key: string]: string;
}

/**
 * Company-defined classifications of assemblies. On the screen standard (`erp-blue-design-standard.md` §List): URL-held search, chips, selection with Print selected, phone cards and a Filters
 * sheet, error vs empty states. Search is the only filter its endpoint validates, so there is nothing more to expose. Bulk: only *Print selected* — a change to
 * assembly groups is made in the record's own form. Its create and edit forms are still pages (listed in the screens-completion log as remaining work).
 */
export function AssemblyGroupsListScreen() {
  const mayManage = usePermission('estimating.assembly-group.manage');
  const navigate = useNavigate();
  const { search: listSearch } = useLocation();
  const query = useListQueryState<Filters>({ defaultSort: 'code' });
  const searchBox = useDebouncedSearch(query.filters.search, (value) => query.setFilter('search', value));
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference('assembly-groups-list', Object.fromEntries(assemblyGroupColumns.map((column) => [column.key, isColumnVisibleByDefault(column)])));
  const { view, setView } = useListViewPreference('assembly-groups-list');
  const result = useAssemblyGroupsIndex({
    'filter[search]': query.filters.search || undefined,
    sort: query.sort as AssemblyGroupsIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const tanStackColumns = useMemo(() => [selectionColumn<AssemblyGroupResource>(), ...toTanStackColumns(assemblyGroupColumns)], []);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  useEffect(() => setRowSelection({}), [listSearch]);
  const selected = useMemo(() => rows.filter((row) => rowSelection[String(row.id)]), [rows, rowSelection]);
  const sorting: SortingState = apiSortToTableSorting(query.sort, assemblyGroupColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const { contentRef, print } = useQuickPrint({ documentTitle: 'Assembly groups' });
  const { contentRef: selectedRef, print: printSelected } = useQuickPrint({ documentTitle: 'Assembly groups (Selected)' });

  const chips: FilterChip[] = [];
  if (query.filters.search) chips.push({ id: 'search', label: `Search: "${query.filters.search}"`, onRemove: () => query.setFilter('search', undefined) });
  const clearAll = () => query.setFilters({ search: undefined });

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Assembly groups"
        description="Company-defined classifications of assemblies, used to share overhead-policy configuration."
        primaryAction={mayManage ? { label: 'New group', onClick: () => navigate({ pathname: '/assembly-groups/new', search: listSearch }) } : undefined}
      />

      <div className="flex flex-wrap items-center gap-2">
        <ListSearchInput input={searchBox.input} searchProps={searchBox.searchProps} onClear={() => query.setFilter('search', undefined)} placeholder="Search code or name…" ariaLabel="Search assembly groups" />
        <div className="hidden items-center gap-2 md:flex">
          <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
        </div>
        <ListFiltersSheet activeCount={0} onClear={clearAll}>
          <PrintTrigger onPrint={() => print()} className="h-11 w-full justify-center" />
        </ListFiltersSheet>
      </div>

      <ActiveFilterChips chips={chips} onClearAll={clearAll} />

      <BulkActionBar count={selected.length} noun="group" onClear={() => setRowSelection({})}>
        <PrintTrigger onPrint={() => printSelected()} label="Print selected" className="h-9 md:h-8" />
      </BulkActionBar>

      <DataTable
        columns={tanStackColumns}
        data={rows}
        rowCount={total}
        recordNoun="assembly groups"
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next = typeof updater === 'function' ? updater(pagination) : updater;
          if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(updater) => query.setSort(tableSortingToApiSort(typeof updater === 'function' ? updater(sorting) : updater, assemblyGroupColumns))}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        getRowId={(row) => String(row.id)}
        onRowClick={mayManage ? (row: AssemblyGroupResource) => navigate({ pathname: `/assembly-groups/${row.id}/edit`, search: listSearch }) : undefined}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load assembly groups."
        emptyMessage={chips.length > 0 ? 'No assembly groups match this search.' : 'No assembly groups yet.'}
        view={view}
        onViewChange={setView}
        cardColumns={assemblyGroupColumns}
      />

      <div className="hidden">
        <div ref={contentRef}>
          <PrintLayout
            title="ASSEMBLY GROUPS"
            printedAt={new Date()}
            recordCount={rows.length}
            meta={[...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, assemblyGroupColumns) ?? query.sort }] : []), ...chips.map((chip) => ({ label: 'Filter', value: chip.label }))]}
          >
            <PrintableTable columns={assemblyGroupColumns} rows={rows} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
      <div className="hidden">
        <div ref={selectedRef}>
          <PrintLayout title="ASSEMBLY GROUPS (SELECTED)" printedAt={new Date()} recordCount={selected.length} meta={[{ label: 'Selection', value: `${selected.length} selected assembly groups` }]}>
            <PrintableTable columns={assemblyGroupColumns} rows={selected} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}
