import type { KeyboardEvent, ReactNode } from 'react';
import type { RowSelectionState, VisibilityState } from '@tanstack/react-table';

import { cn } from '../../lib/utils';
import { Checkbox } from '../ui/checkbox';
import { Skeleton } from '../ui/skeleton';
import { formatColumnValue, isColumnVisibleByDefault, type DataColumn } from './types';
import { ListError } from './list-error';

export interface DataTableCardsProps<TRow> {
  columns: DataColumn<TRow>[];
  data: TRow[];
  columnVisibility?: VisibilityState;
  getRowId?: (row: TRow) => string;
  onRowClick?: (row: TRow) => void;
  /** Same render function `DataTable`/`CursorDataTable` pass through — rendered as a top-right icon cluster here. */
  rowActions?: (row: TRow) => ReactNode;
  isLoading?: boolean;
  isError?: boolean;
  errorMessage?: string;
  error?: unknown;
  onRetry?: () => void;
  emptyMessage?: string;
  skeletonCount?: number;
  /**
   * Opt-in selection, the card-view counterpart of the table's checkbox column: the ticked rows (by `getRowId`) and a setter. Each card gets a checkbox and the list
   * a "select all on this page" control, so bulk actions work on a phone exactly as in the table. Needs `getRowId`.
   */
  rowSelection?: RowSelectionState;
  onRowSelectionChange?: (next: RowSelectionState) => void;
}

/**
 * The card-view fallback for `DataTable`/`CursorDataTable` on small screens
 * (ADR 0015). Reads the same `DataColumn[]` the table renders from — the
 * same `cell` overrides (badges, formatting) apply here, so a card never
 * looks different from its row. One column marked `cardRole: 'title'` (or
 * the first visible column, absent that) becomes the heading; `'subtitle'`
 * becomes the line beneath it; every other visible column becomes a
 * label/value row in the body.
 */
export function DataTableCards<TRow>({
  columns,
  data,
  columnVisibility,
  getRowId,
  onRowClick,
  rowActions,
  isLoading,
  isError,
  errorMessage,
  error,
  onRetry,
  emptyMessage = 'No records found.',
  skeletonCount = 6,
  rowSelection,
  onRowSelectionChange,
}: DataTableCardsProps<TRow>) {
  const selectable = Boolean(onRowSelectionChange && getRowId);
  const visible = columns.filter(
    (column) => (columnVisibility?.[column.key] ?? isColumnVisibleByDefault(column)) !== false
  );
  const title = visible.find((column) => column.cardRole === 'title') ?? visible[0];
  const subtitle = visible.find((column) => column.cardRole === 'subtitle');
  const body = visible.filter((column) => column !== title && column !== subtitle);

  if (isLoading) {
    return (
      <div className="space-y-3">
        {Array.from({ length: skeletonCount }).map((_, index) => (
          <div key={`skeleton-${index}`} className="rounded-md border border-border p-4">
            <Skeleton className="h-4 w-2/3" />
            <Skeleton className="mt-2 h-3 w-1/3" />
          </div>
        ))}
      </div>
    );
  }

  if (isError) {
    return (
      <div className="rounded-md border border-border">
        <ListError message={errorMessage} error={error} onRetry={onRetry} />
      </div>
    );
  }

  if (data.length === 0) {
    return (
      <div className="rounded-md border border-border p-6 text-center text-sm text-muted-foreground">
        {emptyMessage}
      </div>
    );
  }

  const idOf = (row: TRow, index: number) => getRowId?.(row) ?? String(index);
  const chosen = (id: string) => Boolean(rowSelection?.[id]);
  const toggle = (id: string, on: boolean) => {
    const next = { ...(rowSelection ?? {}) };
    if (on) next[id] = true;
    else delete next[id];
    onRowSelectionChange?.(next);
  };
  const allChosen = selectable && data.length > 0 && data.every((row, index) => chosen(idOf(row, index)));
  const someChosen = selectable && data.some((row, index) => chosen(idOf(row, index)));

  return (
    <div className="space-y-3">
      {selectable && (
        <label className="flex min-h-[44px] items-center gap-3 rounded-md border border-border px-4 text-sm font-medium">
          <Checkbox
            checked={allChosen || (someChosen && 'indeterminate')}
            onCheckedChange={(value) => {
              const next = { ...(rowSelection ?? {}) };
              data.forEach((row, index) => {
                if (value === true) next[idOf(row, index)] = true;
                else delete next[idOf(row, index)];
              });
              onRowSelectionChange?.(next);
            }}
            aria-label="Select all"
          />
          Select all on this page
        </label>
      )}
      {data.map((row, index) => {
        const key = idOf(row, index);
        const isChosen = selectable && chosen(key);

        function handleKeyDown(event: KeyboardEvent<HTMLDivElement>) {
          if (!onRowClick) return;
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            onRowClick(row);
          }
        }

        return (
          <div
            key={key}
            // No role="button": this card can carry a real nested rowActions
            // link/button (e.g. Inventory Overview's "View ledger"), and ARIA
            // forbids nesting one interactive widget inside another — Chrome's
            // accessibility tree collapsed the whole card, rowActions text
            // included, into a single button name instead of exposing "View
            // ledger" as its own control. tabIndex/onKeyDown alone still make
            // this focusable and Enter/Space-activatable.
            tabIndex={onRowClick ? 0 : undefined}
            onClick={() => onRowClick?.(row)}
            onKeyDown={handleKeyDown}
            className={cn(
              'min-h-[44px] rounded-md border border-border p-4',
              isChosen && 'border-primary/60 bg-primary/5',
              onRowClick && 'cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-ring'
            )}
          >
            <div className="flex items-start justify-between gap-2">
              {selectable && (
                <Checkbox
                  className="mt-1 shrink-0"
                  checked={isChosen}
                  onCheckedChange={(value) => toggle(key, value === true)}
                  onClick={(event) => event.stopPropagation()}
                  aria-label="Select row"
                />
              )}
              <div className="min-w-0 flex-1">
                {title && <div className="font-medium">{title.cell?.(row) ?? formatColumnValue(title, row)}</div>}
                {subtitle && (
                  <div className="mt-0.5 text-sm text-muted-foreground">
                    {subtitle.cell?.(row) ?? formatColumnValue(subtitle, row)}
                  </div>
                )}
              </div>
              {rowActions && (
                <div
                  className="-mr-1.5 -mt-1.5 flex shrink-0 items-center gap-0.5"
                  onClick={(event) => event.stopPropagation()}
                >
                  {rowActions(row)}
                </div>
              )}
            </div>
            {body.length > 0 && (
              <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                {body.map((column) => (
                  <div key={column.key}>
                    <dt className="text-xs text-muted-foreground">{column.header}</dt>
                    <dd className="mt-0.5">{column.cell?.(row) ?? formatColumnValue(column, row)}</dd>
                  </div>
                ))}
              </dl>
            )}
          </div>
        );
      })}
    </div>
  );
}
