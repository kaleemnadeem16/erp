import { useEffect, useState, type KeyboardEvent, type ReactNode } from "react";
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
} from "@tanstack/react-table";

import { cn } from "../../lib/utils";
import type { ListView } from "../../lib/use-view-preference";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "../ui/table";
import { Skeleton } from "../ui/skeleton";
import { DataTableCards } from "./data-table-cards";
import { ListError } from "./list-error";
import { DataTablePagination } from "./data-table-pagination";
import { DataTableSortSelect } from "./data-table-sort-select";
import { DataTableViewOptions } from "./data-table-view-options";
import { DataTableViewToggle } from "./data-table-view-toggle";
import type { DataColumn } from "./types";

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
  /** The screen's own context for a failure, e.g. "Couldn't load warehouses." The cause is added from `error`. */
  errorMessage?: string;
  /** The failed request's error, so the cause (no permission, bad filter, server fault, offline) can be said. */
  error?: unknown;
  /** Offers "Try again" in the error state. */
  onRetry?: () => void;
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
  /** Optional class name to attach to the Columns dropdown content */
  viewOptionsMenuClassName?: string;
  /** Opt-in content for the left of the toolbar row, opposite the Columns button (e.g. active-filter chips). */
  toolbarStart?: ReactNode;
  /** Opt-in: name the records in the footer count, e.g. "purchase orders" -> "1–6 of 6 purchase orders". */
  recordNoun?: string;
  /** Opt-in: show the current page as a numbered button instead of "Page 1 of N". */
  numberedPagination?: boolean;
  paginationLabel?: string;
  scrollRegionLabel?: string;
  /** Opt-in: replaces the default icon on the Columns button. */
  viewOptionsIcon?: ReactNode;
  /** Opt-in: extra classes for the Columns button (e.g. a taller control). */
  viewOptionsTriggerClassName?: string;
  /** Opt-in: show this text as the (right-aligned) heading of the row-actions column instead of hiding it. */
  actionsHeader?: string;
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
  error,
  onRetry,
  emptyMessage = "No records found.",
  pageSizeOptions,
  view,
  onViewChange,
  cardColumns,
  viewOptionsMenuClassName,
  toolbarStart,
  recordNoun,
  numberedPagination,
  paginationLabel,
  scrollRegionLabel,
  viewOptionsIcon,
  viewOptionsTriggerClassName,
  actionsHeader,
  rowActions,
}: DataTableProps<TRow>) {
  // While a new page, sort or search is on its way, keep showing the last rows (dimmed) instead of flashing skeletons and
  // a "0 records" count: the screen changes at once and the figures settle when the answer arrives. Skeletons are only for
  // the very first load, when there is nothing to keep.
  const [lastLoaded, setLastLoaded] = useState<{
    data: TRow[];
    rowCount: number;
  } | null>(null);
  useEffect(() => {
    if (!isLoading && !isError) setLastLoaded({ data, rowCount });
  }, [data, rowCount, isLoading, isError]);
  const refreshing = Boolean(isLoading && lastLoaded);
  const shownData = refreshing ? lastLoaded!.data : data;
  const shownRowCount = refreshing ? lastLoaded!.rowCount : rowCount;

  const table = useReactTable({
    data: shownData,
    columns,
    state: { pagination, sorting, rowSelection, columnVisibility },
    pageCount: Math.max(
      1,
      Math.ceil(shownRowCount / Math.max(1, pagination.pageSize)),
    ),
    rowCount: shownRowCount,
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

  const columnCount =
    table.getVisibleFlatColumns().length + (rowActions ? 1 : 0);

  return (
    <div className="space-y-3">
      {(onColumnVisibilityChange || onViewChange || toolbarStart) && (
        <div className="flex flex-wrap items-center gap-2">
          {onViewChange && (
            <div className="md:hidden">
              <DataTableViewToggle
                value={view ?? "table"}
                onChange={onViewChange}
              />
            </div>
          )}
          {view === "cards" && cardColumns && (
            <div className="min-w-0 flex-1 md:hidden">
              <DataTableSortSelect
                columns={cardColumns}
                sorting={sorting}
                onSortingChange={onSortingChange}
              />
            </div>
          )}
          {toolbarStart && (
            <div className="order-first w-full min-w-0 md:order-none md:w-auto md:flex-1">
              {toolbarStart}
            </div>
          )}
          {onColumnVisibilityChange && (
            <div className="ml-auto">
              <DataTableViewOptions
                table={table}
                contentClassName={viewOptionsMenuClassName}
                icon={viewOptionsIcon}
                triggerClassName={viewOptionsTriggerClassName}
              />
            </div>
          )}
        </div>
      )}
      {view === "cards" && cardColumns ? (
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
          error={error}
          onRetry={onRetry}
          emptyMessage={emptyMessage}
          skeletonCount={Math.min(pagination.pageSize, 8)}
        />
      ) : (
        <div
          className="rounded-md border border-border"
          aria-busy={refreshing || undefined}
        >
          <Table
            scrollRegionLabel={scrollRegionLabel}
            className={
              refreshing
                ? "opacity-60 transition-opacity"
                : "transition-opacity"
            }
          >
            <TableHeader>
              {table.getHeaderGroups().map((headerGroup) => (
                <TableRow key={headerGroup.id}>
                  {headerGroup.headers.map((header) => (
                    <TableHead key={header.id}>
                      {header.isPlaceholder
                        ? null
                        : flexRender(
                            header.column.columnDef.header,
                            header.getContext(),
                          )}
                    </TableHead>
                  ))}
                  {rowActions && (
                    <TableHead
                      className={actionsHeader ? "text-right" : "w-px"}
                    >
                      <span className={actionsHeader ? undefined : "sr-only"}>
                        {actionsHeader ?? "Actions"}
                      </span>
                    </TableHead>
                  )}
                </TableRow>
              ))}
            </TableHeader>
            <TableBody>
              {isLoading && !refreshing ? (
                Array.from({ length: Math.min(pagination.pageSize, 8) }).map(
                  (_, index) => (
                    <TableRow key={`skeleton-${index}`}>
                      {Array.from({ length: columnCount }).map(
                        (__, cellIndex) => (
                          <TableCell key={cellIndex}>
                            <Skeleton className="h-4 w-full max-w-32" />
                          </TableCell>
                        ),
                      )}
                    </TableRow>
                  ),
                )
              ) : isError ? (
                <TableRow>
                  <TableCell colSpan={columnCount}>
                    <ListError
                      message={errorMessage}
                      error={error}
                      onRetry={onRetry}
                    />
                  </TableCell>
                </TableRow>
              ) : table.getRowModel().rows.length === 0 ? (
                <TableRow>
                  <TableCell
                    colSpan={columnCount}
                    className="h-24 text-center text-muted-foreground"
                  >
                    {emptyMessage}
                  </TableCell>
                </TableRow>
              ) : (
                table.getRowModel().rows.map((row) => (
                  <TableRow
                    key={row.id}
                    data-state={row.getIsSelected() ? "selected" : undefined}
                    // No role="button" override: this row can carry a real nested
                    // link/button via rowActions, and ARIA forbids one interactive
                    // widget containing another — Chrome's own accessibility tree
                    // collapsed the whole row (rowActions text included) into a
                    // single button name when this was tried, burying "View ledger"
                    // as a second, redundant tab stop instead of its own control.
                    // Keeping the native row role and just adding tabIndex/onKeyDown
                    // still makes it focusable and Enter/Space-activatable.
                    tabIndex={onRowClick ? 0 : undefined}
                    className={cn(
                      onRowClick &&
                        "cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset",
                    )}
                    onClick={() => onRowClick?.(row.original)}
                    onKeyDown={(event: KeyboardEvent<HTMLTableRowElement>) => {
                      if (!onRowClick) return;
                      if (event.key === "Enter" || event.key === " ") {
                        event.preventDefault();
                        onRowClick(row.original);
                      }
                    }}
                  >
                    {row.getVisibleCells().map((cell) => (
                      <TableCell key={cell.id}>
                        {flexRender(
                          cell.column.columnDef.cell,
                          cell.getContext(),
                        )}
                      </TableCell>
                    ))}
                    {rowActions && (
                      <TableCell
                        className="text-right"
                        onClick={(event) => event.stopPropagation()}
                      >
                        <div className="flex items-center justify-end gap-1">
                          {rowActions(row.original)}
                        </div>
                      </TableCell>
                    )}
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </div>
      )}

      {/* A failed load has no count to show — "0 records" would read as "nothing found". */}
      {!isError && (
        <DataTablePagination
          table={table}
          pageSizeOptions={pageSizeOptions}
          recordNoun={recordNoun}
          numbered={numberedPagination}
          navigationLabel={paginationLabel}
        />
      )}
    </div>
  );
}
