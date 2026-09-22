import type { ReactNode } from 'react';

import { formatColumnValue, printableColumns, type DataColumn } from '../data-table/types';

export interface PrintableTableProps<TRow> {
  /** Full column set — this filters to `printable` itself, so callers pass the same definitions the screen uses. */
  columns: DataColumn<TRow>[];
  rows: TRow[];
  getRowKey?: (row: TRow, index: number) => string | number;
  /** Extra row(s) below the data — e.g. an order's total, read from the server value rather than summed on the client. Rendered inside a `<tfoot>` so it repeats if the table spans a page break. */
  footer?: ReactNode;
}

/**
 * A plain, paper-oriented `<table>` — deliberately not the on-screen
 * DataTable (no sort buttons, no checkboxes, no interactive chrome). Always
 * reads the same `value()` every column already defines for the screen, so a
 * printed column can never silently drift from what's shown on screen.
 */
export function PrintableTable<TRow>({ columns, rows, getRowKey, footer }: PrintableTableProps<TRow>) {
  const printed = printableColumns(columns);

  return (
    <table className="mt-4 w-full border-collapse text-[10.5px] leading-[1.4] [break-inside:auto]">
      <thead className="[display:table-header-group]">
        <tr className="border-b-2 border-[var(--print-accent)] bg-[var(--print-accent)]/[0.06]">
          {printed.map((column) => (
            <th
              key={column.key}
              scope="col"
              className="px-1.5 py-2 align-bottom text-[9.5px] font-bold uppercase tracking-wide text-black/70 [overflow-wrap:anywhere]"
              style={{ textAlign: column.align ?? 'left' }}
            >
              {column.header}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.map((row, index) => (
          <tr
            key={getRowKey ? getRowKey(row, index) : index}
            className="border-b border-black/10 even:bg-black/[0.018] [break-inside:avoid]"
          >
            {printed.map((column) => (
              <td
                key={column.key}
                className="px-1.5 py-1.5 align-top [overflow-wrap:anywhere]"
                style={{ textAlign: column.align ?? 'left' }}
              >
                {formatColumnValue(column, row)}
              </td>
            ))}
          </tr>
        ))}
      </tbody>
      {footer && <tfoot className="[break-inside:avoid]">{footer}</tfoot>}
    </table>
  );
}
