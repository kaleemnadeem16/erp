import { useEffect, useMemo, useState } from 'react';
import { EllipsisVertical, Eye, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useLocation, useNavigate } from 'react-router';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import { useItemQuantityUnitsIndex, useItemsShow } from '@erp/core-api';

import { DataTable, isColumnVisibleByDefault, selectionColumn, toTanStackColumns } from '../../components/data-table';
import { ItemCombobox } from '../../components/lookups/entity-comboboxes';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '../../components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '../../components/ui/dropdown-menu';
import { Label } from '../../components/ui/label';
import { Tooltip, TooltipContent, TooltipTrigger } from '../../components/ui/tooltip';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage } from '../../lib/api-errors';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useListViewPreference } from '../../lib/use-view-preference';
import { itemQuantityUnitColumns } from './item-quantity-units-columns';
import { PackConversionBulkBar } from './pack-conversion-bulk-actions';
import { PackConversionDeleteDialog, PackConversionEditor, usePackUoms, type PackRow } from './pack-conversion-common';

interface Filters {
  item_id: string;
  [key: string]: string;
}

/**
 * ADR 0050's per-(item, UoM) pack conversion override — "1 pack unit = factor × the item's own stock UoM," may cross UoM dimension. The (item, UoM) pair is create-only:
 * editing a conversion only ever changes its factor and rounding. On the Pack conversions screen it is a list with side panels; on an item (`itemId`) the same conversions
 * are managed in place, with the form in a dialog.
 */
export function ItemQuantityUnitsListScreen({ itemId, stockUnit }: { itemId?: number; stockUnit?: string } = {}) {
  return itemId ? <ItemPackConversions itemId={itemId} stockUnit={stockUnit} /> : <PackConversionsScreen />;
}

function PackConversionsScreen() {
  const navigate = useNavigate();
  const { search } = useLocation();
  const mayManage = usePermission('inventory.item.manage');
  const query = useListQueryState<Filters>({ defaultSort: '-created_at', defaultPerPage: 25 });
  const { view, setView } = useListViewPreference('pack-conversions');
  const result = useItemQuantityUnitsIndex({
    'filter[item_id]': query.filters.item_id ? Number(query.filters.item_id) : undefined,
    include: 'item,uom',
    sort: query.sort as 'created_at' | '-created_at' | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const uoms = usePackUoms(rows);
  const filteredItem = useItemsShow(Number(query.filters.item_id), undefined, { query: { enabled: Boolean(query.filters.item_id) } });
  const itemName = filteredItem.data?.status === 200 ? `${filteredItem.data.data.data.code} — ${filteredItem.data.data.data.name}` : undefined;
  const [deleting, setDeleting] = useState<PackRow | null>(null);

  const columns = useMemo(() => itemQuantityUnitColumns(uoms), [uoms]);
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference('pack-conversions', Object.fromEntries(columns.map((column) => [column.key, isColumnVisibleByDefault(column)])));
  const tanStackColumns = useMemo(() => [selectionColumn<PackRow>(), ...toTanStackColumns(columns)], [columns]);
  const printedColumns = columns.filter((column) => columnVisibility[column.key] !== false);
  const sorting: SortingState = apiSortToTableSorting(query.sort, columns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  const viewKey = JSON.stringify([query.filters, query.sort, query.page, query.perPage]);
  useEffect(() => setRowSelection({}), [viewKey]);
  const selectedRows = useMemo(() => rows.filter((row) => rowSelection[String(row.id)]), [rows, rowSelection]);
  const { contentRef: printRef, print } = useQuickPrint({ documentTitle: 'Pack conversions' });
  const { contentRef: selectedPrintRef, print: printSelected } = useQuickPrint({ documentTitle: 'Pack conversions (selected)' });

  const open = (row: PackRow) => navigate({ pathname: `/item-quantity-units/${row.id}`, search });
  const filterMeta = query.filters.item_id ? [{ label: 'Filter', value: `Item: ${itemName ?? `#${query.filters.item_id}`}` }] : [];

  return (
    <ScreenTemplate kind="list" className="erp-screen pb-24">
      <div className="space-y-4" data-testid="pack-conversions-list">
        <PageHeader
          title="Pack conversions"
          description="An item's own alternate/pack unit factor (a box of eaches, a roll priced by the metre) — resolved before the global unit-of-measure conversion. Item and pack unit are fixed once created."
          primaryAction={mayManage ? { label: 'New pack conversion', onClick: () => navigate({ pathname: '/item-quantity-units/new', search }) } : undefined}
        />

        <div className="flex flex-wrap items-center gap-2">
          <div className="min-w-0 flex-1 md:w-80 md:flex-none">
            <Label htmlFor="pack-filter-item" className="sr-only">
              Filter by item
            </Label>
            <ItemCombobox
              id="pack-filter-item"
              label="Filter by item"
              placeholder="All items"
              allLabel="All items"
              className="h-10 w-full md:w-80"
              value={query.filters.item_id ?? 'all'}
              onValueChange={(value) => query.setFilter('item_id', value === 'all' ? undefined : value)}
            />
          </div>
          <PrintTrigger onPrint={() => print()} className="hidden h-10 px-4 md:inline-flex" />
          <PrintTrigger onPrint={() => print()} label="" aria-label="Print" className="h-10 shrink-0 md:hidden" />
        </div>

        {query.filters.item_id && (
          <div className="flex flex-wrap items-center gap-1.5 pt-1" data-testid="active-filters">
            <Badge variant="secondary" className="ui-chip-active h-7 gap-1 px-2.5 text-xs font-normal">
              Item: {itemName ?? `#${query.filters.item_id}`}
              <button
                type="button"
                onClick={() => query.setFilter('item_id', undefined)}
                className="rounded-full p-0.5 hover:bg-muted"
                aria-label={`Remove filter: Item: ${itemName ?? query.filters.item_id}`}
              >
                <X className="h-3 w-3" aria-hidden="true" />
              </button>
            </Badge>
            <Button variant="ghost" size="sm" className="h-7 text-xs text-muted-foreground hover:text-foreground" onClick={() => query.setFilter('item_id', undefined)}>
              Clear all
            </Button>
          </div>
        )}

        <PackConversionBulkBar
          selected={selectedRows}
          mayManage={mayManage}
          onPrintSelected={() => printSelected()}
          onClear={() => setRowSelection({})}
          onChanged={async () => {
            await result.refetch();
          }}
        />

        <DataTable
          columns={tanStackColumns}
          data={rows}
          rowCount={total}
          pagination={pagination}
          onPaginationChange={(updater) => {
            const next = typeof updater === 'function' ? updater(pagination) : updater;
            if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
            else query.setPage(next.pageIndex + 1);
          }}
          sorting={sorting}
          onSortingChange={(updater) => {
            const next = typeof updater === 'function' ? updater(sorting) : updater;
            query.setSort(tableSortingToApiSort(next, columns));
          }}
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
          errorMessage={getApiErrorMessage(result.error) ?? "Couldn't load pack conversions."}
          emptyMessage={query.filters.item_id ? 'No pack conversions for this item.' : 'No pack conversions yet.'}
          view={view}
          onViewChange={setView}
          cardColumns={columns}
          recordNoun="pack conversions"
          actionsHeader="Actions"
          numberedPagination
          rowActions={(row: PackRow) => (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="h-8 w-8" aria-label={`Actions for ${row.item?.code ?? row.item_id} ${row.uom?.code ?? ''}`} onClick={(event) => event.stopPropagation()}>
                  <EllipsisVertical className="h-4 w-4" aria-hidden="true" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuItem
                  onClick={(event) => {
                    event.stopPropagation();
                    open(row);
                  }}
                >
                  <Eye className="mr-2 h-4 w-4 text-muted-foreground" aria-hidden="true" />
                  View details
                </DropdownMenuItem>
                {mayManage && (
                  <>
                    <DropdownMenuItem
                      onClick={(event) => {
                        event.stopPropagation();
                        navigate({ pathname: `/item-quantity-units/${row.id}/edit`, search });
                      }}
                    >
                      <Pencil className="mr-2 h-4 w-4 text-muted-foreground" aria-hidden="true" />
                      Edit conversion
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      onClick={(event) => {
                        event.stopPropagation();
                        setDeleting(row);
                      }}
                    >
                      <Trash2 className="mr-2 h-4 w-4 text-muted-foreground" aria-hidden="true" />
                      Delete conversion
                    </DropdownMenuItem>
                  </>
                )}
              </DropdownMenuContent>
            </DropdownMenu>
          )}
        />

        <PackConversionDeleteDialog
          row={deleting}
          open={deleting !== null}
          onClose={() => setDeleting(null)}
          onDeleted={() => {
            setDeleting(null);
            void result.refetch();
          }}
        />

        <div className="hidden">
          <div ref={printRef}>
            <PrintLayout title="PACK CONVERSIONS" printedAt={new Date()} recordCount={rows.length} meta={[...filterMeta, { label: 'Page', value: `${rows.length} of ${total} shown` }]}>
              <PrintableTable columns={printedColumns} rows={rows} getRowKey={(row) => row.id} />
            </PrintLayout>
          </div>
        </div>
        <div className="hidden">
          <div ref={selectedPrintRef}>
            <PrintLayout
              title="PACK CONVERSIONS (SELECTED)"
              printedAt={new Date()}
              recordCount={selectedRows.length}
              meta={[{ label: 'Selection', value: `${selectedRows.length} selected pack conversions` }]}
            >
              <PrintableTable columns={printedColumns} rows={selectedRows} getRowKey={(row) => row.id} />
            </PrintLayout>
          </div>
        </div>
      </div>
    </ScreenTemplate>
  );
}

/** One item's own conversions, managed in place on the item (which is itself a side panel, so adding and editing happen in a dialog over it). */
function ItemPackConversions({ itemId, stockUnit }: { itemId: number; stockUnit?: string }) {
  const mayManage = usePermission('inventory.item.manage');
  const result = useItemQuantityUnitsIndex({ 'filter[item_id]': itemId, include: 'item,uom', sort: '-created_at', per_page: 100 });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const uoms = usePackUoms(rows);
  const columns = useMemo(() => toTanStackColumns(itemQuantityUnitColumns(uoms).filter((column) => column.key !== 'item' && column.key !== 'created_at' && column.key !== 'updated_at')), [uoms]);
  const [editor, setEditor] = useState<{ row: PackRow | null } | null>(null);
  const [deleting, setDeleting] = useState<PackRow | null>(null);

  return (
    <div className="space-y-4" data-testid="item-pack-conversions">
      {mayManage && (
        <div className="flex justify-end">
          <Button size="sm" onClick={() => setEditor({ row: null })} data-testid="pack-add">
            <Plus className="mr-1 h-4 w-4" aria-hidden="true" />
            Add pack conversion
          </Button>
        </div>
      )}
      {stockUnit && <p className="text-sm text-muted-foreground">1 pack unit = factor × {stockUnit}. Each pack size converts directly to the stock unit.</p>}
      <DataTable
        columns={columns}
        data={rows}
        rowCount={rows.length}
        pagination={{ pageIndex: 0, pageSize: 100 }}
        onPaginationChange={() => undefined}
        sorting={[]}
        onSortingChange={() => undefined}
        rowActions={
          mayManage
            ? (row: PackRow) => (
                <>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Button variant="ghost" size="icon" className="h-8 w-8" aria-label="Edit" onClick={() => setEditor({ row })}>
                        <Pencil className="h-4 w-4" />
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent>Edit</TooltipContent>
                  </Tooltip>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Button variant="ghost" size="icon" className="h-8 w-8" aria-label="Delete" onClick={() => setDeleting(row)}>
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent>Delete</TooltipContent>
                  </Tooltip>
                </>
              )
            : undefined
        }
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage={getApiErrorMessage(result.error) ?? "Couldn't load pack conversions."}
        emptyMessage="No pack conversions yet."
      />
      <Dialog open={editor !== null} onOpenChange={(next) => !next && setEditor(null)}>
        <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto" data-testid="pack-dialog">
          <DialogHeader>
            <DialogTitle>{editor?.row ? 'Edit pack conversion' : 'Add a pack conversion'}</DialogTitle>
            <DialogDescription>{editor?.row ? 'Only the factor and rounding change; the pack unit is fixed.' : 'How many of this item’s stock unit one pack unit holds.'}</DialogDescription>
          </DialogHeader>
          {editor && (
            <PackConversionEditor
              key={editor.row?.id ?? 'new'}
              itemId={itemId}
              stockUnit={stockUnit}
              editing={editor.row}
              onCancel={() => setEditor(null)}
              onSaved={() => {
                setEditor(null);
                void result.refetch();
              }}
            />
          )}
        </DialogContent>
      </Dialog>
      <PackConversionDeleteDialog
        row={deleting}
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        onDeleted={() => {
          setDeleting(null);
          void result.refetch();
        }}
      />
    </div>
  );
}
