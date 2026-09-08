import type { ReactNode } from 'react';

declare module '@tanstack/react-table' {
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  interface ColumnMeta<TData, TValue> {
    align?: 'left' | 'right' | 'center';
    widthClassName?: string;
    /** Human label for the column-visibility toggle — `header` is a render function, not text. */
    label?: string;
  }
}

/**
 * One field definition, shared across every place that shows it: the on-screen
 * table, Quick Print, and (later) CSV/Excel export. `value` is the canonical
 * plain value — print and export always read it, and it's the screen's default
 * too. `cell` is an *optional* screen-only enhancement (a badge, a link, an
 * icon) for when the plain value isn't the best on-screen representation.
 *
 * Don't create a second definition (e.g. a "print columns" array) for a column
 * that only needs a different screen rendering — add `cell` here instead. A
 * second definition is only justified when the printed/exported representation
 * itself must differ in content, not just style.
 */
export interface DataColumn<TRow> {
  /** Stable identifier — also the on-screen column-visibility toggle key. */
  key: string;
  header: string;
  /** Canonical value: what prints, what exports, and the screen default. */
  value: (row: TRow) => string | number | boolean | null | undefined;
  /** Screen-only rendering. Print/export always use `value`, never this. */
  cell?: (row: TRow) => ReactNode;
  /** Enables clicking the header to toggle server-side sort. */
  sortable?: boolean;
  /** API `sort` field name, if different from `key`. */
  sortKey?: string;
  /** Documents that a toolbar filter exists for this field. Informational only — building the actual filter control is still up to the screen. */
  filterable?: boolean;
  /** Default on-screen visibility (togglable). Default true. */
  screenVisible?: boolean;
  /** Included in Quick Print's default column set. Default true. */
  printable?: boolean;
  /** Included in a future CSV/Excel export by default. Default = `printable`. */
  exportable?: boolean;
  align?: 'left' | 'right' | 'center';
  /** Tailwind width class, e.g. "w-32". */
  widthClassName?: string;
}

export function isColumnVisibleByDefault<TRow>(column: DataColumn<TRow>): boolean {
  return column.screenVisible ?? true;
}

export function isColumnPrintableByDefault<TRow>(column: DataColumn<TRow>): boolean {
  return column.printable ?? true;
}

export function isColumnExportableByDefault<TRow>(column: DataColumn<TRow>): boolean {
  return column.exportable ?? isColumnPrintableByDefault(column);
}

/** The columns Quick Print renders by default — sensible-default set for V1; a later Print Options dialog can override the selection without touching this shared definition. */
export function printableColumns<TRow>(columns: DataColumn<TRow>[]): DataColumn<TRow>[] {
  return columns.filter(isColumnPrintableByDefault);
}

export function formatColumnValue<TRow>(column: DataColumn<TRow>, row: TRow): string {
  const value = column.value(row);
  if (value === null || value === undefined) return '—';
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  return String(value);
}
