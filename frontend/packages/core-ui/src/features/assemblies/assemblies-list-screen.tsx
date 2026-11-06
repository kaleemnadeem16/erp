import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import { useAssembliesIndex, useAssemblyGroupsShow, type AssemblyResource, type AssembliesIndexFilterStatus, type AssembliesIndexSort } from '@erp/core-api';

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
import { AssemblyGroupCombobox } from '../../components/lookups/entity-comboboxes';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { usePermission } from '../../hooks/use-permission';
import { apiSortToTableSorting, describeSort, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useDebouncedSearch } from '../../lib/use-debounced-search';
import { useListViewPreference } from '../../lib/use-view-preference';
import { RECIPE_STATUSES } from '../estimating/estimating-status';
import { assemblyColumns } from './assemblies-columns';

interface Filters {
  search: string;
  status: string;
  assembly_group_id: string;
  [key: string]: string;
}

/**
 * Sellable units of work. On the screen standard (`erp-blue-design-standard.md` §List): URL-held search and filters (status, group), chips, selection with
 * *Print selected*, phone cards and a Filters sheet. Bulk is print only: archiving an assembly takes it out of use and the API has no way back, so it is a
 * decision made on the record, not across a selection.
 */
export function AssembliesListScreen() {
  const mayManage = usePermission('estimating.assembly.manage');
  const navigate = useNavigate();
  const { search: listSearch } = useLocation();
  const query = useListQueryState<Filters>({ defaultSort: 'code' });
  const searchBox = useDebouncedSearch(query.filters.search, (value) => query.setFilter('search', value));
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference('assemblies-list', Object.fromEntries(assemblyColumns.map((column) => [column.key, isColumnVisibleByDefault(column)])));
  const { view, setView } = useListViewPreference('assemblies-list');

  // The chosen group is named for its chip by its own id, not by whatever first page the picker happened to load.
  const groupId = Number(query.filters.assembly_group_id) || 0;
  const groupQuery = useAssemblyGroupsShow(groupId, undefined, { query: { enabled: groupId > 0 } });
  const group = groupQuery.data?.status === 200 ? groupQuery.data.data.data : undefined;

  const result = useAssembliesIndex({
    'filter[search]': query.filters.search || undefined,
    'filter[assembly_group_id]': groupId > 0 ? groupId : undefined,
    'filter[status]': (query.filters.status as AssembliesIndexFilterStatus | undefined) || undefined,
    include: 'assemblyGroup',
    sort: query.sort as AssembliesIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const tanStackColumns = useMemo(() => [selectionColumn<AssemblyResource>(), ...toTanStackColumns(assemblyColumns)], []);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  // A selection belongs to the rows it was made on: any change of page, sort, size or filter (all in the URL) clears it.
  useEffect(() => setRowSelection({}), [listSearch]);
  const selected = useMemo(() => rows.filter((row) => rowSelection[String(row.id)]), [rows, rowSelection]);
  const sorting: SortingState = apiSortToTableSorting(query.sort, assemblyColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const { contentRef, print } = useQuickPrint({ documentTitle: 'Assemblies' });
  const { contentRef: selectedRef, print: printSelected } = useQuickPrint({ documentTitle: 'Assemblies (Selected)' });

  const filterCount = [query.filters.status, groupId > 0].filter(Boolean).length;
  const chips: FilterChip[] = [];
  if (query.filters.search) chips.push({ id: 'search', label: `Search: "${query.filters.search}"`, onRemove: () => query.setFilter('search', undefined) });
  if (query.filters.status) chips.push({ id: 'status', label: `Status: ${humanizeCode(query.filters.status)}`, onRemove: () => query.setFilter('status', undefined) });
  if (groupId > 0) chips.push({ id: 'group', label: `Group: ${group ? `${group.code} — ${group.name}` : `#${groupId}`}`, onRemove: () => query.setFilter('assembly_group_id', undefined) });
  const clearAll = () => query.setFilters({ search: undefined, status: undefined, assembly_group_id: undefined });

  const groupSelect = (id: string, className: string) => (
    <AssemblyGroupCombobox
      id={id}
      label="Assembly group"
      allLabel="All groups"
      placeholder="All groups"
      className={className}
      value={query.filters.assembly_group_id ?? 'all'}
      onValueChange={(value) => query.setFilter('assembly_group_id', value === 'all' ? undefined : value)}
    />
  );
  const statusSelect = (className?: string) => (
    <FilterSelect label="Status" allLabel="All statuses" value={query.filters.status} options={RECIPE_STATUSES} onChange={(value) => query.setFilter('status', value)} className={className} />
  );

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Assemblies"
        description="Sellable units of work, priced per work unit — each version is a costed list of components."
        primaryAction={mayManage ? { label: 'New assembly', onClick: () => navigate({ pathname: '/assemblies/new', search: listSearch }) } : undefined}
      />

      <div className="flex flex-wrap items-center gap-2">
        <ListSearchInput input={searchBox.input} searchProps={searchBox.searchProps} onClear={() => query.setFilter('search', undefined)} placeholder="Search code or name…" ariaLabel="Search assemblies" />
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          {groupSelect('inline-group', 'h-10 w-60')}
          {statusSelect('md:w-40')}
          <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
        </div>
        <ListFiltersSheet activeCount={filterCount} onClear={clearAll}>
          <FilterField label="Status">{statusSelect()}</FilterField>
          <FilterField label="Assembly group" htmlFor="sheet-group">
            {groupSelect('sheet-group', 'h-11 w-full')}
          </FilterField>
          <PrintTrigger onPrint={() => print()} className="h-11 w-full justify-center" />
        </ListFiltersSheet>
      </div>

      <ActiveFilterChips chips={chips} onClearAll={clearAll} />

      <BulkActionBar count={selected.length} noun="assembly" onClear={() => setRowSelection({})}>
        <PrintTrigger onPrint={() => printSelected()} label="Print selected" className="h-9 md:h-8" />
      </BulkActionBar>

      <DataTable
        columns={tanStackColumns}
        data={rows}
        rowCount={total}
        recordNoun="assemblies"
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next = typeof updater === 'function' ? updater(pagination) : updater;
          if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(updater) => query.setSort(tableSortingToApiSort(typeof updater === 'function' ? updater(sorting) : updater, assemblyColumns))}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        getRowId={(row) => String(row.id)}
        onRowClick={mayManage ? (row: AssemblyResource) => navigate({ pathname: `/assemblies/${row.id}/edit`, search: listSearch }) : undefined}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load assemblies."
        emptyMessage={chips.length > 0 ? 'No assemblies match these filters.' : 'No assemblies yet.'}
        view={view}
        onViewChange={setView}
        cardColumns={assemblyColumns}
      />

      <div className="hidden">
        <div ref={contentRef}>
          <PrintLayout
            title="ASSEMBLIES"
            printedAt={new Date()}
            recordCount={rows.length}
            meta={[...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, assemblyColumns) ?? query.sort }] : []), ...chips.map((chip) => ({ label: 'Filter', value: chip.label }))]}
          >
            <PrintableTable columns={assemblyColumns} rows={rows} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
      <div className="hidden">
        <div ref={selectedRef}>
          <PrintLayout title="ASSEMBLIES (SELECTED)" printedAt={new Date()} recordCount={selected.length} meta={[{ label: 'Selection', value: `${selected.length} selected assemblies` }]}>
            <PrintableTable columns={assemblyColumns} rows={selected} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}
