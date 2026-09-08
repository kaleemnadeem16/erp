import { formatColumnValue, printableColumns, type DataColumn } from '../data-table/types';

export interface PrintableTableProps<TRow> {
  /** Full column set — this filters to `printable` itself, so callers pass the same definitions the screen uses. */
  columns: DataColumn<TRow>[];
  rows: TRow[];
  getRowKey?: (row: TRow, index: number) => string | number;
}

/**
 * A plain, paper-oriented `<table>` — deliberately not the on-screen
 * DataTable (no sort buttons, no checkboxes, no interactive chrome). Always
 * reads the same `value()` every column already defines for the screen, so a
 * printed column can never silently drift from what's shown on screen.
 */
export function PrintableTable<TRow>({ columns, rows, getRowKey }: PrintableTableProps<TRow>) {
  const printed = printableColumns(columns);

  return (
    <table className="w-full border-collapse text-xs">
      <thead>
        <tr className="border-b-2 border-black/70">
          {printed.map((column) => (
            <th
              key={column.key}
              className="whitespace-nowrap px-2 py-1.5 font-semibold"
              style={{ textAlign: column.align ?? 'left' }}
            >
              {column.header}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.map((row, index) => (
          <tr key={getRowKey ? getRowKey(row, index) : index} className="border-b border-black/15">
            {printed.map((column) => (
              <td key={column.key} className="px-2 py-1" style={{ textAlign: column.align ?? 'left' }}>
                {formatColumnValue(column, row)}
              </td>
            ))}
          </tr>
        ))}
      </tbody>
    </table>
  );
}
