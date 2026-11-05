import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import { useApplicationRatesIndex, useItemsShow, useUomsShow, type ApplicationRateResource, type ApplicationRatesIndexSort } from '@erp/core-api';

import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Button } from '../../components/ui/button';
import {
  ActiveFilterChips,
  BulkActionBar,
  DataTable,
  FilterField,
  FilterSelect,
  ListFiltersSheet,
  ListSearchInput,
  isColumnVisibleByDefault,
  selectionColumn,
  toTanStackColumns,
  type FilterChip,
} from '../../components/data-table';
import { ItemCombobox, UomCombobox } from '../../components/lookups/entity-comboboxes';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { usePermission } from '../../hooks/use-permission';
import { apiSortToTableSorting, describeSort, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useDebouncedSearch } from '../../lib/use-debounced-search';
import { useListViewPreference } from '../../lib/use-view-preference';
import { applicationRateColumns } from './application-rates-columns';
import { ApplicationRateResolutionTester } from './application-rate-resolution-tester';

interface Filters {
  search: string;
  item_id: string;
  work_uom_id: string;
  is_default: string;
  [key: string]: string;
}

const DEFAULT_OPTIONS = [
  { value: 'true', label: 'Default only' },
  { value: 'false', label: 'Context-specific only' },
] as const;

/**
 * How much of an item is consumed per unit of work. On the screen standard (`erp-blue-design-standard.md` §List): URL-held filters (item, work unit, default),
 * a search of the rate's item (code or name), chips, selection with *Print selected*, phone cards and a Filters sheet. Bulk is print only: a rate is changed or deleted in its own record, where its consequences are stated.
 */
export function ApplicationRatesListScreen() {
  const mayManage = usePermission('estimating.application-rate.manage');
  const navigate = useNavigate();
  const { search: listSearch } = useLocation();
  const query = useListQueryState<Filters>({ defaultSort: 'created_at' });
  const searchBox = useDebouncedSearch(query.filters.search, (value) => query.setFilter('search', value));
  const [testerOpen, setTesterOpen] = useState(false);
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference('application-rates-list', Object.fromEntries(applicationRateColumns.map((column) => [column.key, isColumnVisibleByDefault(column)])));
  const { view, setView } = useListViewPreference('application-rates-list');

  // The chosen item / unit are named for their chips by their own id, not by whatever first page a picker happened to load.
  const itemId = Number(query.filters.item_id) || 0;
  const itemQuery = useItemsShow(itemId, undefined, { query: { enabled: itemId > 0 } });
  const item = itemQuery.data?.status === 200 ? itemQuery.data.data.data : undefined;
  const uomId = Number(query.filters.work_uom_id) || 0;
  const uomQuery = useUomsShow(uomId, { query: { enabled: uomId > 0 } });
  const uom = uomQuery.data?.status === 200 ? uomQuery.data.data.data : undefined;

  const result = useApplicationRatesIndex({
    'filter[search]': query.filters.search || undefined,
    'filter[item_id]': itemId > 0 ? itemId : undefined,
    'filter[work_uom_id]': uomId > 0 ? uomId : undefined,
    'filter[is_default]': query.filters.is_default ? query.filters.is_default === 'true' : undefined,
    include: 'item,workUom,uom',
    sort: query.sort as ApplicationRatesIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const tanStackColumns = useMemo(() => [selectionColumn<ApplicationRateResource>(), ...toTanStackColumns(applicationRateColumns)], []);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  // A selection belongs to the rows it was made on: any change of page, sort, size or filter (all in the URL) clears it.
  useEffect(() => setRowSelection({}), [listSearch]);
  const selected = useMemo(() => rows.filter((row) => rowSelection[String(row.id)]), [rows, rowSelection]);
  const sorting: SortingState = apiSortToTableSorting(query.sort, applicationRateColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const { contentRef, print } = useQuickPrint({ documentTitle: 'Application Rates' });
  const { contentRef: selectedRef, print: printSelected } = useQuickPrint({ documentTitle: 'Application Rates (Selected)' });

  const filterCount = [itemId > 0, uomId > 0, query.filters.is_default].filter(Boolean).length;
  const chips: FilterChip[] = [];
  if (query.filters.search) chips.push({ id: 'search', label: `Search: "${query.filters.search}"`, onRemove: () => query.setFilter('search', undefined) });
  if (itemId > 0) chips.push({ id: 'item', label: `Item: ${item ? `${item.code} — ${item.name}` : `#${itemId}`}`, onRemove: () => query.setFilter('item_id', undefined) });
  if (uomId > 0) chips.push({ id: 'uom', label: `Work unit: ${uom ? `${uom.name} (${uom.code})` : `#${uomId}`}`, onRemove: () => query.setFilter('work_uom_id', undefined) });
  if (query.filters.is_default) {
    chips.push({ id: 'default', label: query.filters.is_default === 'true' ? 'Default rates only' : 'Context-specific rates only', onRemove: () => query.setFilter('is_default', undefined) });
  }
  const clearAll = () => query.setFilters({ search: undefined, item_id: undefined, work_uom_id: undefined, is_default: undefined });

  const itemPicker = (id: string, className: string) => (
    <ItemCombobox
      id={id}
      label="Item"
      allLabel="All items"
      placeholder="All items"
      className={className}
      value={query.filters.item_id ?? 'all'}
      onValueChange={(value) => query.setFilter('item_id', value === 'all' ? undefined : value)}
    />
  );
  const uomPicker = (id: string, className: string) => (
    <UomCombobox
      id={id}
      label="Work unit"
      allLabel="All work units"
      placeholder="All work units"
      className={className}
      value={query.filters.work_uom_id ?? 'all'}
      onValueChange={(value) => query.setFilter('work_uom_id', value === 'all' ? undefined : value)}
    />
  );
  const defaultSelect = (className?: string) => (
    <FilterSelect label="Default status" allLabel="All rates" value={query.filters.is_default} options={DEFAULT_OPTIONS} onChange={(value) => query.setFilter('is_default', value)} className={className} />
  );

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Application rates"
        description="How much of an item is consumed per one unit of work, by context."
        primaryAction={mayManage ? { label: 'New rate', onClick: () => navigate({ pathname: '/application-rates/new', search: listSearch }) } : undefined}
        actions={
          <Button type="button" variant="outline" onClick={() => setTesterOpen((open) => !open)} aria-expanded={testerOpen}>
            {testerOpen ? 'Hide resolution tester' : 'Test resolution'}
          </Button>
        }
      />
      {testerOpen && <ApplicationRateResolutionTester />}

      <div className="flex flex-wrap items-center gap-2">
        <ListSearchInput input={searchBox.input} searchProps={searchBox.searchProps} onClear={() => query.setFilter('search', undefined)} placeholder="Search by item code or name…" ariaLabel="Search application rates" />
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          {itemPicker('inline-item', 'h-10 w-64')}
          {uomPicker('inline-uom', 'h-10 w-56')}
          {defaultSelect('md:w-52')}
          <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
        </div>
        <ListFiltersSheet activeCount={filterCount} onClear={clearAll}>
          <FilterField label="Item" htmlFor="sheet-item">
            {itemPicker('sheet-item', 'h-11 w-full')}
          </FilterField>
          <FilterField label="Work unit" htmlFor="sheet-uom">
            {uomPicker('sheet-uom', 'h-11 w-full')}
          </FilterField>
          <FilterField label="Default status">{defaultSelect()}</FilterField>
          <PrintTrigger onPrint={() => print()} className="h-11 w-full justify-center" />
        </ListFiltersSheet>
      </div>

      <ActiveFilterChips chips={chips} onClearAll={clearAll} />

      <BulkActionBar count={selected.length} noun="rate" onClear={() => setRowSelection({})}>
        <PrintTrigger onPrint={() => printSelected()} label="Print selected" className="h-9 md:h-8" />
      </BulkActionBar>

      <DataTable
        columns={tanStackColumns}
        data={rows}
        rowCount={total}
        recordNoun="application rates"
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next = typeof updater === 'function' ? updater(pagination) : updater;
          if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(updater) => query.setSort(tableSortingToApiSort(typeof updater === 'function' ? updater(sorting) : updater, applicationRateColumns))}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        getRowId={(row) => String(row.id)}
        onRowClick={mayManage ? (row: ApplicationRateResource) => navigate({ pathname: `/application-rates/${row.id}/edit`, search: listSearch }) : undefined}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load application rates."
        emptyMessage={chips.length > 0 ? 'No application rates match these filters.' : 'No application rates yet.'}
        view={view}
        onViewChange={setView}
        cardColumns={applicationRateColumns}
      />

      <div className="hidden">
        <div ref={contentRef}>
          <PrintLayout
            title="APPLICATION RATES"
            printedAt={new Date()}
            recordCount={rows.length}
            meta={[...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, applicationRateColumns) ?? query.sort }] : []), ...chips.map((chip) => ({ label: 'Filter', value: chip.label }))]}
          >
            <PrintableTable columns={applicationRateColumns} rows={rows} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
      <div className="hidden">
        <div ref={selectedRef}>
          <PrintLayout title="APPLICATION RATES (SELECTED)" printedAt={new Date()} recordCount={selected.length} meta={[{ label: 'Selection', value: `${selected.length} selected application rates` }]}>
            <PrintableTable columns={applicationRateColumns} rows={selected} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}
