import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import { EllipsisVertical, FileText, Trash2 } from 'lucide-react';
import { useItemsShow, useResourceRatesDestroy, useResourceRatesIndex, type ResourceRateResource } from '@erp/core-api';

import { ConfirmActionDialog } from '../../components/confirm-action-dialog';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Button } from '../../components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '../../components/ui/dropdown-menu';
import {
  ActiveFilterChips,
  BulkActionBar,
  DataTable,
  FilterField,
  ListFiltersSheet,
  isColumnVisibleByDefault,
  selectionColumn,
  toTanStackColumns,
  type FilterChip,
} from '../../components/data-table';
import { ItemCombobox } from '../../components/lookups/entity-comboboxes';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { usePermission } from '../../hooks/use-permission';
import { apiSortToTableSorting, describeSort, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useListViewPreference } from '../../lib/use-view-preference';
import { resourceRateColumns } from './resource-rates-columns';

interface Filters {
  item_id: string;
  [key: string]: string;
}

/**
 * The standard estimating rate for a LABOUR or PLANT item, effective as of a date. Flat, item-keyed master data with no update endpoint — a rate change is
 * always a new dated row (ResourceRateController) — so a row opens a read-only record with *Delete* as its only change, and *New rate* opens a form sheet.
 * On the screen standard (`erp-blue-design-standard.md` §List): URL-held item filter, chip, selection with *Print selected*, phone cards, Filters sheet.
 * The endpoint takes no free-text search, so there is no search box.
 */
export function ResourceRatesListScreen() {
  const mayManage = usePermission('estimating.resource-rate.manage');
  const navigate = useNavigate();
  const { search: listSearch } = useLocation();
  const queryClient = useQueryClient();
  const query = useListQueryState<Filters>({ defaultSort: '-effective_from' });
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference('resource-rates-list', Object.fromEntries(resourceRateColumns.map((column) => [column.key, isColumnVisibleByDefault(column)])));
  const { view, setView } = useListViewPreference('resource-rates-list');

  const itemId = Number(query.filters.item_id) || 0;
  const itemQuery = useItemsShow(itemId, undefined, { query: { enabled: itemId > 0 } });
  const item = itemQuery.data?.status === 200 ? itemQuery.data.data.data : undefined;

  const result = useResourceRatesIndex({
    'filter[item_id]': itemId > 0 ? itemId : undefined,
    include: 'item,rateUom',
    sort: query.sort as 'effective_from' | '-effective_from' | 'created_at' | '-created_at' | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const tanStackColumns = useMemo(() => [selectionColumn<ResourceRateResource>(), ...toTanStackColumns(resourceRateColumns)], []);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  // A selection belongs to the rows it was made on: any change of page, sort, size or filter (all in the URL) clears it.
  useEffect(() => setRowSelection({}), [listSearch]);
  const selected = useMemo(() => rows.filter((row) => rowSelection[String(row.id)]), [rows, rowSelection]);
  const sorting: SortingState = apiSortToTableSorting(query.sort, resourceRateColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const { contentRef, print } = useQuickPrint({ documentTitle: 'Resource Rates' });
  const { contentRef: selectedRef, print: printSelected } = useQuickPrint({ documentTitle: 'Resource Rates (Selected)' });

  const destroy = useResourceRatesDestroy();
  const [deleting, setDeleting] = useState<ResourceRateResource | null>(null);

  const chips: FilterChip[] = [];
  if (itemId > 0) chips.push({ id: 'item', label: `Item: ${item ? `${item.code} — ${item.name}` : `#${itemId}`}`, onRemove: () => query.setFilter('item_id', undefined) });
  const clearAll = () => query.setFilters({ item_id: undefined });

  const itemPicker = (id: string, className: string) => (
    <ItemCombobox
      id={id}
      label="Item"
      allLabel="All items"
      placeholder="All labour and plant items"
      types={['LABOUR', 'PLANT']}
      className={className}
      value={query.filters.item_id ?? 'all'}
      onValueChange={(value) => query.setFilter('item_id', value === 'all' ? undefined : value)}
    />
  );
  const open = (row: ResourceRateResource) => navigate({ pathname: `/resource-rates/${row.id}`, search: listSearch });

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Resource rates"
        description="The standard estimating rate for a LABOUR or PLANT item, effective as of a date — the source Assembly component costing resolves from. A correction is a new dated row, not an edit to an old one."
        primaryAction={mayManage ? { label: 'New rate', onClick: () => navigate({ pathname: '/resource-rates/new', search: listSearch }) } : undefined}
      />

      <div className="flex flex-wrap items-center gap-2">
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          {itemPicker('inline-item', 'h-10 w-72')}
          <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
        </div>
        <ListFiltersSheet activeCount={itemId > 0 ? 1 : 0} onClear={clearAll}>
          <FilterField label="Item" htmlFor="sheet-item">
            {itemPicker('sheet-item', 'h-11 w-full')}
          </FilterField>
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
        recordNoun="resource rates"
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next = typeof updater === 'function' ? updater(pagination) : updater;
          if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(updater) => query.setSort(tableSortingToApiSort(typeof updater === 'function' ? updater(sorting) : updater, resourceRateColumns))}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        getRowId={(row) => String(row.id)}
        onRowClick={open}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load resource rates."
        emptyMessage={chips.length > 0 ? 'No resource rates match this filter.' : 'No resource rates yet.'}
        view={view}
        onViewChange={setView}
        cardColumns={resourceRateColumns}
        actionsHeader="Actions"
        rowActions={(row: ResourceRateResource) => (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="h-11 w-11 md:h-8 md:w-8" aria-label={`Actions for ${row.item?.code ?? `rate ${row.id}`} from ${row.effective_from}`} onClick={(event) => event.stopPropagation()}>
                <EllipsisVertical className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
              <DropdownMenuItem
                onClick={(event) => {
                  event.stopPropagation();
                  open(row);
                }}
              >
                <FileText className="mr-2 h-4 w-4 text-muted-foreground" />
                View rate
              </DropdownMenuItem>
              {mayManage && (
                <>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem
                    onClick={(event) => {
                      event.stopPropagation();
                      setDeleting(row);
                    }}
                  >
                    <Trash2 className="mr-2 h-4 w-4 text-muted-foreground" />
                    Delete rate
                  </DropdownMenuItem>
                </>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        )}
      />

      {deleting && (
        <ConfirmActionDialog
          open
          onOpenChange={(next) => !next && setDeleting(null)}
          title="Delete this resource rate?"
          description="The rate is removed and cannot be brought back. Costing from now on uses the most recent rate dated on or before the costing date; if this was the only one, that item has no rate until another is entered."
          confirmLabel="Delete rate"
          pendingLabel="Deleting…"
          failureMessage="The rate could not be deleted."
          testId="delete-resource-rate-dialog"
          onConfirm={async () => {
            await destroy.mutateAsync({ resourceRate: deleting.id });
            await queryClient.invalidateQueries();
          }}
        />
      )}

      <div className="hidden">
        <div ref={contentRef}>
          <PrintLayout
            title="RESOURCE RATES"
            printedAt={new Date()}
            recordCount={rows.length}
            meta={[...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, resourceRateColumns) ?? query.sort }] : []), ...chips.map((chip) => ({ label: 'Filter', value: chip.label }))]}
          >
            <PrintableTable columns={resourceRateColumns} rows={rows} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
      <div className="hidden">
        <div ref={selectedRef}>
          <PrintLayout title="RESOURCE RATES (SELECTED)" printedAt={new Date()} recordCount={selected.length} meta={[{ label: 'Selection', value: `${selected.length} selected resource rates` }]}>
            <PrintableTable columns={resourceRateColumns} rows={selected} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}
