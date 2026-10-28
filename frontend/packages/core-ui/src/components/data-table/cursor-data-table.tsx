import type { KeyboardEvent, ReactNode } from 'react';
import {
  flexRender,
  getCoreRowModel,
  useReactTable,
  type ColumnDef,
  type OnChangeFn,
  type RowSelectionState,
  type SortingState,
  type VisibilityState,
} from '@tanstack/react-table';
import { ChevronLeft, ChevronRight } from 'lucide-react';

import { cn } from '../../lib/utils';
import type { ListView } from '../../lib/use-view-preference';
import { Button } from '../ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../ui/table';
import { Skeleton } from '../ui/skeleton';
import { Tooltip, TooltipContent, TooltipTrigger } from '../ui/tooltip';
import { DataTableCards } from './data-table-cards';
import { DataTableSortSelect } from './data-table-sort-select';
import { DataTableViewOptions } from './data-table-view-options';
import { DataTableViewToggle } from './data-table-view-toggle';
import type { DataColumn } from './types';
import { ListError } from './list-error';

export interface CursorDataTableProps<TRow> {
  columns: ColumnDef<TRow, unknown>[];
  data: TRow[];
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
  error?: unknown;
  onRetry?: () => void;
  emptyMessage?: string;
  perPage: number;
  onPerPageChange: (perPage: number) => void;
  pageSizeOptions?: number[];
  hasPrevious: boolean;
  hasNext: boolean;
  onPrevious: () => void;
  onNext: () => void;
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
  rowSelection = {},
  onRowSelectionChange,
  columnVisibility,
  onColumnVisibilityChange,
  getRowId,
  onRowClick,
  isLoading,
  isError,
  errorMessage,
  error,
  onRetry,
  emptyMessage = 'No records found.',
  perPage,
  onPerPageChange,
  pageSizeOptions = [10, 25, 50, 100],
  hasPrevious,
  hasNext,
  onPrevious,
  onNext,
  view,
  onViewChange,
  cardColumns,
  rowActions,
}: CursorDataTableProps<TRow>) {
  const table = useReactTable({
    data,
    columns,
    state: { sorting, columnVisibility, rowSelection },
    manualPagination: true,
    manualSorting: true,
    enableMultiSort: false,
    enableRowSelection: !!onRowSelectionChange,
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
        <div className="flex items-center gap-2">
          {onViewChange && (
            <div className="md:hidden">
              <DataTableViewToggle value={view ?? 'table'} onChange={onViewChange} />
            </div>
          )}
          {view === 'cards' && cardColumns && (
            <div className="min-w-0 flex-1 md:hidden">
              <DataTableSortSelect columns={cardColumns} sorting={sorting} onSortingChange={onSortingChange} />
            </div>
          )}
          {onColumnVisibilityChange && (
            <div className="ml-auto">
              <DataTableViewOptions table={table} />
            </div>
          )}
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
          rowSelection={rowSelection}
          onRowSelectionChange={onRowSelectionChange ? (next) => onRowSelectionChange(next) : undefined}
          isLoading={isLoading}
          isError={isError}
          errorMessage={errorMessage}
          error={error}
          onRetry={onRetry}
          emptyMessage={emptyMessage}
          skeletonCount={Math.min(perPage, 8)}
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
                <TableCell colSpan={columnCount}>
                  <ListError message={errorMessage} error={error} onRetry={onRetry} />
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
                  data-state={row.getIsSelected() && 'selected'}
                  // No role="button": see the identical comment in data-table.tsx —
                  // this row can carry a nested rowActions link/button, and
                  // overriding the role collapses both into one confusing stop.
                  tabIndex={onRowClick ? 0 : undefined}
                  className={cn(onRowClick && 'cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset')}
                  onClick={() => onRowClick?.(row.original)}
                  onKeyDown={(event: KeyboardEvent<HTMLTableRowElement>) => {
                    if (!onRowClick) return;
                    if (event.key === 'Enter' || event.key === ' ') {
                      event.preventDefault();
                      onRowClick(row.original);
                    }
                  }}
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

      <div className="ui-list-pagination flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-2">
          <span className="text-sm text-muted-foreground">Rows per page</span>
          <Select value={String(perPage)} onValueChange={(value) => onPerPageChange(Number(value))}>
            <SelectTrigger className="h-8 w-18" aria-label="Rows per page">
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

        <div className="flex items-center gap-2">
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                variant="outline"
                size="sm"
                className="!w-auto min-w-[5.5rem] h-9 gap-1.5 px-3"
                onClick={onPrevious}
                disabled={!hasPrevious}
                aria-label="Previous page"
              >
                <ChevronLeft className="h-4 w-4" />
                Previous
              </Button>
            </TooltipTrigger>
            <TooltipContent>Previous page</TooltipContent>
          </Tooltip>
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                variant="outline"
                size="sm"
                className="!w-auto min-w-[5.5rem] h-9 gap-1.5 px-3"
                onClick={onNext}
                disabled={!hasNext}
                aria-label="Next page"
              >
                Next
                <ChevronRight className="h-4 w-4" />
              </Button>
            </TooltipTrigger>
            <TooltipContent>Next page</TooltipContent>
          </Tooltip>
        </div>
      </div>
    </div>
  );
}
