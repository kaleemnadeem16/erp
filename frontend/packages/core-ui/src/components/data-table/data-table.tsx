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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../ui/table';
import { Skeleton } from '../ui/skeleton';
import { DataTablePagination } from './data-table-pagination';
import { DataTableViewOptions } from './data-table-view-options';

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
  rowSelection,
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

  const columnCount = table.getVisibleFlatColumns().length;

  return (
    <div className="space-y-3">
      {onColumnVisibilityChange && (
        <div className="flex justify-end">
          <DataTableViewOptions table={table} />
        </div>
      )}
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
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>

      <DataTablePagination table={table} pageSizeOptions={pageSizeOptions} />
    </div>
  );
}
