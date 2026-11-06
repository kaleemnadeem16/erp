import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import { useCustomerAccountsShow, useFormulationsIndex, useItemsShow, type FormulationResource, type FormulationsIndexSort } from '@erp/core-api';

import { PageHeader } from '../../components/page/page-header';
import { Button } from '../../components/ui/button';
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
import { CustomerCombobox, ItemCombobox } from '../../components/lookups/entity-comboboxes';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { usePermission } from '../../hooks/use-permission';
import { apiSortToTableSorting, describeSort, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useDebouncedSearch } from '../../lib/use-debounced-search';
import { useListViewPreference } from '../../lib/use-view-preference';
import { RECIPE_STATUSES } from '../estimating/estimating-status';
import { formulationColumns } from './formulations-columns';
import { FormulationResolutionTester } from './formulation-resolution-tester';

interface Filters {
  search: string;
  status: string;
  output_item_id: string;
  customer_id: string;
  [key: string]: string;
}

/**
 * Recipes. On the screen standard (`erp-blue-design-standard.md` §List): URL-held search and filters (status, output item, customer), chips, selection with
 * *Print selected*, phone cards and a Filters sheet. Bulk is print only: archiving a formulation takes it out of resolution and the API has no way back, so it is
 * a decision made on the record, not across a selection.
 */
export function FormulationsListScreen() {
  const mayManage = usePermission('estimating.formulation.manage');
  const navigate = useNavigate();
  const { search: listSearch } = useLocation();
  const query = useListQueryState<Filters>({ defaultSort: 'code' });
  const searchBox = useDebouncedSearch(query.filters.search, (value) => query.setFilter('search', value));
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference('formulations-list', Object.fromEntries(formulationColumns.map((column) => [column.key, isColumnVisibleByDefault(column)])));
  const { view, setView } = useListViewPreference('formulations-list');
  const [testerOpen, setTesterOpen] = useState(false);

  // The chosen item / customer are named for their chips by their own id, not by whatever first page a picker happened to load.
  const itemId = Number(query.filters.output_item_id) || 0;
  const itemQuery = useItemsShow(itemId, undefined, { query: { enabled: itemId > 0 } });
  const item = itemQuery.data?.status === 200 ? itemQuery.data.data.data : undefined;
  const customerId = Number(query.filters.customer_id) || 0;
  const customerQuery = useCustomerAccountsShow(customerId, { include: 'party' }, { query: { enabled: customerId > 0 } });
  const customer = customerQuery.data?.status === 200 ? customerQuery.data.data.data : undefined;

  const result = useFormulationsIndex({
    'filter[search]': query.filters.search || undefined,
    'filter[status]': query.filters.status || undefined,
    'filter[output_item_id]': itemId > 0 ? itemId : undefined,
    'filter[customer_id]': customerId > 0 ? customerId : undefined,
    include: 'outputItem,customer',
    sort: query.sort as FormulationsIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const tanStackColumns = useMemo(() => [selectionColumn<FormulationResource>(), ...toTanStackColumns(formulationColumns)], []);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  // A selection belongs to the rows it was made on: any change of page, sort, size or filter (all in the URL) clears it.
  useEffect(() => setRowSelection({}), [listSearch]);
  const selected = useMemo(() => rows.filter((row) => rowSelection[String(row.id)]), [rows, rowSelection]);
  const sorting: SortingState = apiSortToTableSorting(query.sort, formulationColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const { contentRef, print } = useQuickPrint({ documentTitle: 'Formulations' });
  const { contentRef: selectedRef, print: printSelected } = useQuickPrint({ documentTitle: 'Formulations (Selected)' });

  const filterCount = [query.filters.status, itemId > 0, customerId > 0].filter(Boolean).length;
  const chips: FilterChip[] = [];
  if (query.filters.search) chips.push({ id: 'search', label: `Search: "${query.filters.search}"`, onRemove: () => query.setFilter('search', undefined) });
  if (query.filters.status) chips.push({ id: 'status', label: `Status: ${humanizeCode(query.filters.status)}`, onRemove: () => query.setFilter('status', undefined) });
  if (itemId > 0) chips.push({ id: 'item', label: `Output item: ${item ? `${item.code} — ${item.name}` : `#${itemId}`}`, onRemove: () => query.setFilter('output_item_id', undefined) });
  if (customerId > 0) chips.push({ id: 'customer', label: `Customer: ${customer ? (customer.party?.name ? `${customer.code} — ${customer.party.name}` : customer.code) : `#${customerId}`}`, onRemove: () => query.setFilter('customer_id', undefined) });
  const clearAll = () => query.setFilters({ search: undefined, status: undefined, output_item_id: undefined, customer_id: undefined });

  const filterControls = (id: string, className: string) => (
    <>
      <ItemCombobox
        id={`${id}-item`}
        label="Output item"
        allLabel="All output items"
        placeholder="All output items"
        className={className}
        value={query.filters.output_item_id ?? 'all'}
        onValueChange={(value) => query.setFilter('output_item_id', value === 'all' ? undefined : value)}
      />
      <CustomerCombobox
        id={`${id}-customer`}
        label="Customer"
        allLabel="All customers"
        placeholder="All customers"
        className={className}
        value={query.filters.customer_id ?? 'all'}
        onValueChange={(value) => query.setFilter('customer_id', value === 'all' ? undefined : value)}
      />
    </>
  );
  const statusSelect = (className?: string) => (
    <FilterSelect label="Status" allLabel="All statuses" value={query.filters.status} options={RECIPE_STATUSES} onChange={(value) => query.setFilter('status', value)} className={className} />
  );

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Formulations"
        description="Recipes: the inputs required to produce one unit of an output item."
        primaryAction={mayManage ? { label: 'New formulation', onClick: () => navigate({ pathname: '/formulations/new', search: listSearch }) } : undefined}
        actions={
          <Button type="button" variant="outline" onClick={() => setTesterOpen((open) => !open)} aria-expanded={testerOpen}>
            {testerOpen ? 'Hide resolution tester' : 'Test resolution'}
          </Button>
        }
      />
      {testerOpen && <FormulationResolutionTester onClose={() => setTesterOpen(false)} />}

      <div className="flex flex-wrap items-center gap-2">
        <ListSearchInput input={searchBox.input} searchProps={searchBox.searchProps} onClear={() => query.setFilter('search', undefined)} placeholder="Search code or name…" ariaLabel="Search formulations" />
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          {filterControls('inline', 'h-10 w-60')}
          {statusSelect('md:w-40')}
          <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
        </div>
        <ListFiltersSheet activeCount={filterCount} onClear={clearAll}>
          <FilterField label="Status">{statusSelect()}</FilterField>
          <FilterField label="Output item" htmlFor="sheet-item">
            <ItemCombobox
              id="sheet-item"
              label="Output item"
              allLabel="All output items"
              placeholder="All output items"
              className="h-11 w-full"
              value={query.filters.output_item_id ?? 'all'}
              onValueChange={(value) => query.setFilter('output_item_id', value === 'all' ? undefined : value)}
            />
          </FilterField>
          <FilterField label="Customer" htmlFor="sheet-customer">
            <CustomerCombobox
              id="sheet-customer"
              label="Customer"
              allLabel="All customers"
              placeholder="All customers"
              className="h-11 w-full"
              value={query.filters.customer_id ?? 'all'}
              onValueChange={(value) => query.setFilter('customer_id', value === 'all' ? undefined : value)}
            />
          </FilterField>
          <PrintTrigger onPrint={() => print()} className="h-11 w-full justify-center" />
        </ListFiltersSheet>
      </div>

      <ActiveFilterChips chips={chips} onClearAll={clearAll} />

      <BulkActionBar count={selected.length} noun="formulation" onClear={() => setRowSelection({})}>
        <PrintTrigger onPrint={() => printSelected()} label="Print selected" className="h-9 md:h-8" />
      </BulkActionBar>

      <DataTable
        columns={tanStackColumns}
        data={rows}
        rowCount={total}
        recordNoun="formulations"
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next = typeof updater === 'function' ? updater(pagination) : updater;
          if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(updater) => query.setSort(tableSortingToApiSort(typeof updater === 'function' ? updater(sorting) : updater, formulationColumns))}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        getRowId={(row) => String(row.id)}
        onRowClick={mayManage ? (row: FormulationResource) => navigate({ pathname: `/formulations/${row.id}/edit`, search: listSearch }) : undefined}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load formulations."
        emptyMessage={chips.length > 0 ? 'No formulations match these filters.' : 'No formulations yet.'}
        view={view}
        onViewChange={setView}
        cardColumns={formulationColumns}
      />

      <div className="hidden">
        <div ref={contentRef}>
          <PrintLayout
            title="FORMULATIONS"
            printedAt={new Date()}
            recordCount={rows.length}
            meta={[...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, formulationColumns) ?? query.sort }] : []), ...chips.map((chip) => ({ label: 'Filter', value: chip.label }))]}
          >
            <PrintableTable columns={formulationColumns} rows={rows} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
      <div className="hidden">
        <div ref={selectedRef}>
          <PrintLayout title="FORMULATIONS (SELECTED)" printedAt={new Date()} recordCount={selected.length} meta={[{ label: 'Selection', value: `${selected.length} selected formulations` }]}>
            <PrintableTable columns={formulationColumns} rows={selected} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}
