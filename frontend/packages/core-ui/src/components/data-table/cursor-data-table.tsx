import { flexRender, getCoreRowModel, useReactTable, type ColumnDef, type OnChangeFn, type SortingState, type VisibilityState } from '@tanstack/react-table';
import { ChevronLeft, ChevronRight } from 'lucide-react';

import { cn } from '../../lib/utils';
import { Button } from '../ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../ui/table';
import { Skeleton } from '../ui/skeleton';
import { DataTableViewOptions } from './data-table-view-options';

export interface CursorDataTableProps<TRow> {
  columns: ColumnDef<TRow, unknown>[];
  data: TRow[];
  sorting: SortingState;
  onSortingChange: OnChangeFn<SortingState>;
  columnVisibility?: VisibilityState;
  onColumnVisibilityChange?: OnChangeFn<VisibilityState>;
  onRowClick?: (row: TRow) => void;
  isLoading?: boolean;
  isError?: boolean;
  errorMessage?: string;
  emptyMessage?: string;
  perPage: number;
  onPerPageChange: (perPage: number) => void;
  pageSizeOptions?: number[];
  hasPrevious: boolean;
  hasNext: boolean;
  onPrevious: () => void;
  onNext: () => void;
}

/**
 * The cursor-paginated sibling of DataTable, for feeds too large or too
 * frequently appended to for `total`/page-jump to make sense — the stock
 * ledger first, more to follow per docs/architecture/api-conventions.md
 * #pagination. No row count or page count exists to show; navigation is
 * Previous/Next only, driven by the API's opaque `next_cursor`/`prev_cursor`.
 */
export function CursorDataTable<TRow>({
  columns,
  data,
  sorting,
  onSortingChange,
  columnVisibility,
  onColumnVisibilityChange,
  onRowClick,
  isLoading,
  isError,
  errorMessage,
  emptyMessage = 'No records found.',
  perPage,
  onPerPageChange,
  pageSizeOptions = [10, 25, 50, 100],
  hasPrevious,
  hasNext,
  onPrevious,
  onNext,
}: CursorDataTableProps<TRow>) {
  const table = useReactTable({
    data,
    columns,
    state: { sorting, columnVisibility },
    manualPagination: true,
    manualSorting: true,
    enableMultiSort: false,
    onSortingChange,
    onColumnVisibilityChange,
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
              Array.from({ length: Math.min(perPage, 8) }).map((_, index) => (
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

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-2">
          <span className="text-sm text-muted-foreground">Rows per page</span>
          <Select value={String(perPage)} onValueChange={(value) => onPerPageChange(Number(value))}>
            <SelectTrigger className="h-8 w-18">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {pageSizeOptions.map((size) => (
                <SelectItem key={size} value={String(size)}>
                  {size}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="flex items-center gap-1">
          <Button variant="outline" size="sm" onClick={onPrevious} disabled={!hasPrevious} aria-label="Previous page">
            <ChevronLeft className="h-4 w-4" />
            Previous
          </Button>
          <Button variant="outline" size="sm" onClick={onNext} disabled={!hasNext} aria-label="Next page">
            Next
            <ChevronRight className="h-4 w-4" />
          </Button>
        </div>
      </div>
    </div>
  );
}
