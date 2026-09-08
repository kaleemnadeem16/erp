import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import type { ColumnDef } from '@tanstack/react-table';

import { cn } from '../../lib/utils';
import { Button } from '../ui/button';
import { Checkbox } from '../ui/checkbox';
import { formatColumnValue, type DataColumn } from './types';

/** Converts one shared `DataColumn` into a real TanStack column: a sortable
 * header button when `sortable`, `cell` for screen rendering (falling back to
 * the canonical `value`), and the visibility/alignment metadata TanStack needs. */
export function toTanStackColumns<TRow>(columns: DataColumn<TRow>[]): ColumnDef<TRow>[] {
  return columns.map(
    (column): ColumnDef<TRow> => ({
      id: column.key,
      accessorFn: (row) => column.value(row),
      enableSorting: column.sortable ?? false,
      enableHiding: true,
      meta: { align: column.align, widthClassName: column.widthClassName, label: column.header },
      header: ({ column: tableColumn }) =>
        column.sortable ? (
          <SortableColumnHeader label={column.header} column={tableColumn} align={column.align} />
        ) : (
          <span className={cn(column.align === 'right' && 'block text-right', column.align === 'center' && 'block text-center')}>
            {column.header}
          </span>
        ),
      cell: ({ row }) => (
        <span className={cn(column.align === 'right' && 'block text-right tabular-nums', column.align === 'center' && 'block text-center')}>
          {column.cell ? column.cell(row.original) : formatColumnValue(column, row.original)}
        </span>
      ),
    })
  );
}

function SortableColumnHeader({
  label,
  column,
  align,
}: {
  label: string;
  column: { getIsSorted: () => false | 'asc' | 'desc'; toggleSorting: (desc?: boolean) => void };
  align?: 'left' | 'right' | 'center';
}) {
  const sorted = column.getIsSorted();
  const Icon = sorted === 'asc' ? ArrowUp : sorted === 'desc' ? ArrowDown : ArrowUpDown;

  return (
    <Button
      variant="ghost"
      size="sm"
      className={cn('-ml-3 h-8', align === 'right' && 'ml-auto')}
      onClick={() => column.toggleSorting(sorted === 'asc')}
    >
      {label}
      <Icon className="ml-1.5 h-3.5 w-3.5" />
    </Button>
  );
}

/** A checkbox column for row selection — opt in per screen via `columns={[selectionColumn<T>(), ...toTanStackColumns(itemColumns)]}`. */
export function selectionColumn<TRow>(): ColumnDef<TRow> {
  return {
    id: '__select',
    enableSorting: false,
    enableHiding: false,
    header: ({ table }) => (
      <Checkbox
        checked={table.getIsAllPageRowsSelected() || (table.getIsSomePageRowsSelected() && 'indeterminate')}
        onCheckedChange={(value) => table.toggleAllPageRowsSelected(!!value)}
        aria-label="Select all"
      />
    ),
    cell: ({ row }) => (
      <Checkbox
        checked={row.getIsSelected()}
        onCheckedChange={(value) => row.toggleSelected(!!value)}
        onClick={(event) => event.stopPropagation()}
        aria-label="Select row"
      />
    ),
    size: 32,
  };
}
