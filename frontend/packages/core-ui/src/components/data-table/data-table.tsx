import type { ReactNode } from 'react';
import {
  flexRender,
  getCoreRowModel,
  useReactTable,
  type ColumnDef,
  type OnChangeFn,
  type PaginationState,
  type RowSelectionState,
  type SortingState,
  type VisibilityState,
} from '@tanstack/react-table';

import { cn } from '../../lib/utils';
import type { ListView } from '../../lib/use-view-preference';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../ui/table';
import { Skeleton } from '../ui/skeleton';
import { DataTableCards } from './data-table-cards';
import { DataTablePagination } from './data-table-pagination';
import { DataTableSortSelect } from './data-table-sort-select';
import { DataTableViewOptions } from './data-table-view-options';
import { DataTableViewToggle } from './data-table-view-toggle';
import type { DataColumn } from './types';

export interface DataTableProps<TRow> {
  columns: ColumnDef<TRow, unknown>[];
  data: TRow[];
  /** Total rows across every page (from the API's `meta.total`) — drives pagination, not `data.length`. */
  rowCount: number;
  pagination: PaginationState;
  onPaginationChange: OnChangeFn<PaginationState>;
  sorting: SortingState;
  onSortingChange: OnChangeFn<SortingState>;
  rowSelection?: RowSelectionState;
  onRowSelectionChange?: OnChangeFn<RowSelectionState>;
  columnVisibility?: VisibilityState;
  onColumnVisibilityChange?: OnChangeFn<VisibilityState>;
  getRowId?: (row: TRow) => string;
  onRowClick?: (row: TRow) => void;
  isLoading?: boolean;
  isError?: boolean;
  errorMessage?: string;
  emptyMessage?: string;
  pageSizeOptions?: number[];
  /** Current table/cards rendering mode. Omit entirely to keep this table always table-only (today's behavior). */
  view?: ListView;
  onViewChange?: (view: ListView) => void;
  /** The plain `DataColumn[]` cards read from — required alongside `view`/`onViewChange` to enable card view. */
  cardColumns?: DataColumn<TRow>[];
  /**
   * Per-row quick actions (icon buttons — edit, archive, etc.), rendered by
   * the screen itself so this stays generic. Appears as a trailing column in
   * table view and a top-right cluster in card view — one definition drives
   * both. The base component only owns placement/layout; which actions exist
   * is entirely up to the screen passing this prop.
   */
  rowActions?: (row: TRow) => ReactNode;
}

/**
 * The one reusable list-table primitive every master-data (and later
 * transactional) screen renders through. Always server-driven — pagination,
 * sorting and filtering all happen on the backend, never client-side, so this
 * never loads more than one page of rows into the browser. Search/filter
 * inputs are composed by the screen itself, above this component (they're too
 * shape-specific to generalise); column visibility (shown whenever
 * `onColumnVisibilityChange` is passed), loading/empty/error states and
 * pagination are handled here once, for every screen.
 */
export function DataTable<TRow>({
  columns,
  data,
  rowCount,
  pagination,
  onPaginationChange,
  sorting,
  onSortingChange,
  rowSelection = {},
  onRowSelectionChange,
  columnVisibility,
  onColumnVisibilityChange,
  getRowId,
  onRowClick,
  isLoading,
  isError,
  errorMessage,
  emptyMessage = 'No records found.',
  pageSizeOptions,
  view,
  onViewChange,
  cardColumns,
  rowActions,
}: DataTableProps<TRow>) {
  const table = useReactTable({
    data,
    columns,
    state: { pagination, sorting, rowSelection, columnVisibility },
    pageCount: Math.max(1, Math.ceil(rowCount / Math.max(1, pagination.pageSize))),
    rowCount,
    manualPagination: true,
    manualSorting: true,
    enableMultiSort: false,
    enableRowSelection: !!onRowSelectionChange,
    onPaginationChange,
    onSortingChange,
    onRowSelectionChange,
    onColumnVisibilityChange,
    getRowId,
    getCoreRowModel: getCoreRowModel(),
  });

  const columnCount = table.getVisibleFlatColumns().length + (rowActions ? 1 : 0);

  return (
    <div className="space-y-3">
      {(onColumnVisibilityChange || onViewChange) && (
        <div className="flex items-center justify-between gap-2">
          {onViewChange && (
            <div className="md:hidden">
              <DataTableViewToggle value={view ?? 'table'} onChange={onViewChange} />
            </div>
          )}
          {onColumnVisibilityChange && (
            <div className="ml-auto">
              <DataTableViewOptions table={table} />
            </div>
          )}
        </div>
      )}
      {view === 'cards' && cardColumns && (
        <div className="md:hidden">
          <DataTableSortSelect columns={cardColumns} sorting={sorting} onSortingChange={onSortingChange} />
        </div>
      )}
      {view === 'cards' && cardColumns ? (
        <DataTableCards
          columns={cardColumns}
          data={data}
          columnVisibility={columnVisibility}
          getRowId={getRowId}
          onRowClick={onRowClick}
          rowActions={rowActions}
          isLoading={isLoading}
          isError={isError}
          errorMessage={errorMessage}
          emptyMessage={emptyMessage}
          skeletonCount={Math.min(pagination.pageSize, 8)}
        />
      ) : (
      <div className="rounded-md border border-border">
        <Table>
          <TableHeader>
            {table.getHeaderGroups().map((headerGroup) => (
              <TableRow key={headerGroup.id}>
                {headerGroup.headers.map((header) => (
                  <TableHead key={header.id}>
                    {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                  </TableHead>
                ))}
                {rowActions && (
                  <TableHead className="w-px">
                    <span className="sr-only">Actions</span>
                  </TableHead>
                )}
              </TableRow>
            ))}
          </TableHeader>
          <TableBody>
            {isLoading ? (
              Array.from({ length: Math.min(pagination.pageSize, 8) }).map((_, index) => (
                <TableRow key={`skeleton-${index}`}>
                  {Array.from({ length: columnCount }).map((__, cellIndex) => (
                    <TableCell key={cellIndex}>
                      <Skeleton className="h-4 w-full max-w-32" />
                    </TableCell>
                  ))}
                </TableRow>
              ))
            ) : isError ? (
              <TableRow>
                <TableCell colSpan={columnCount} className="h-24 text-center text-destructive">
                  {errorMessage ?? 'Something went wrong loading this list.'}
                </TableCell>
              </TableRow>
            ) : table.getRowModel().rows.length === 0 ? (
              <TableRow>
                <TableCell colSpan={columnCount} className="h-24 text-center text-muted-foreground">
                  {emptyMessage}
                </TableCell>
              </TableRow>
            ) : (
              table.getRowModel().rows.map((row) => (
                <TableRow
                  key={row.id}
                  data-state={row.getIsSelected() ? 'selected' : undefined}
                  className={cn(onRowClick && 'cursor-pointer')}
                  onClick={() => onRowClick?.(row.original)}
                >
                  {row.getVisibleCells().map((cell) => (
                    <TableCell key={cell.id}>{flexRender(cell.column.columnDef.cell, cell.getContext())}</TableCell>
                  ))}
                  {rowActions && (
                    <TableCell className="text-right" onClick={(event) => event.stopPropagation()}>
                      <div className="flex items-center justify-end gap-1">{rowActions(row.original)}</div>
                    </TableCell>
                  )}
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>
      )}

      <DataTablePagination table={table} pageSizeOptions={pageSizeOptions} />
    </div>
  );
}
