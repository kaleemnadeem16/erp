import type { ReactNode } from 'react';
import type { Table } from '@tanstack/react-table';
import { SlidersHorizontal } from 'lucide-react';

import { Button } from '../ui/button';
import { cn } from '../../lib/utils';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '../ui/dropdown-menu';

/** Column-visibility toggle for a DataTable's toolbar. Hides `enableHiding: false` columns (selection, actions). */
export function DataTableViewOptions<TRow>({
  table,
  contentClassName,
  icon,
  triggerClassName,
}: {
  table: Table<TRow>;
  contentClassName?: string;
  /** Replaces the default sliders icon on the Columns button. */
  icon?: ReactNode;
  triggerClassName?: string;
}) {
  const columns = table.getAllColumns().filter((column) => column.getCanHide());

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline" size="sm" className={cn("h-9", triggerClassName)}>
          {icon ?? <SlidersHorizontal className="h-4 w-4" />}
          Columns
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className={cn("w-48", contentClassName)}>
        <DropdownMenuLabel>Toggle columns</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {columns.map((column) => (
          <DropdownMenuCheckboxItem
            key={column.id}
            checked={column.getIsVisible()}
            onCheckedChange={(value) => column.toggleVisibility(!!value)}
            onSelect={(event) => event.preventDefault()}
          >
            {column.columnDef.meta?.label ?? column.id}
          </DropdownMenuCheckboxItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
