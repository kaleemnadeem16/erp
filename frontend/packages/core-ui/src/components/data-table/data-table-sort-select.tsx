import { ArrowDown, ArrowUp } from 'lucide-react';
import type { SortingState } from '@tanstack/react-table';

import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../ui/select';
import type { DataColumn } from './types';

export interface DataTableSortSelectProps<TRow> {
  columns: DataColumn<TRow>[];
  sorting: SortingState;
  onSortingChange: (sorting: SortingState) => void;
}

/**
 * A "Sort by" dropdown built from the same `DataColumn.sortable`/`header`
 * metadata `toTanStackColumns` uses for table view's clickable headers. Card
 * view has no header row to tap, so this is its only way to change sort —
 * see the "Mobile list-screen patterns" note in
 * docs/architecture/user-interface.md. Single-select only, matching
 * `DataTable`/`CursorDataTable`'s `enableMultiSort: false`.
 */
export function DataTableSortSelect<TRow>({ columns, sorting, onSortingChange }: DataTableSortSelectProps<TRow>) {
  const sortable = columns.filter((column) => column.sortable);
  if (sortable.length === 0) return null;

  const current = sorting[0];
  const value = current ? `${current.id}:${current.desc ? 'desc' : 'asc'}` : undefined;

  return (
    <Select
      value={value}
      onValueChange={(next) => {
        const separator = next.lastIndexOf(':');
        onSortingChange([{ id: next.slice(0, separator), desc: next.slice(separator + 1) === 'desc' }]);
      }}
    >
      <SelectTrigger className="h-9 w-full">
        <SelectValue placeholder="Sort by" />
      </SelectTrigger>
      <SelectContent>
        {sortable.map((column) => (
          <SortOptionPair key={column.key} sortKey={column.key} label={column.header} />
        ))}
      </SelectContent>
    </Select>
  );
}

function SortOptionPair({ sortKey, label }: { sortKey: string; label: string }) {
  return (
    <>
      <SelectItem value={`${sortKey}:asc`}>
        <ArrowUp className="h-3.5 w-3.5" />
        {label}
      </SelectItem>
      <SelectItem value={`${sortKey}:desc`}>
        <ArrowDown className="h-3.5 w-3.5" />
        {label}
      </SelectItem>
    </>
  );
}
