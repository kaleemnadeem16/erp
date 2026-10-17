import { Printer, X } from 'lucide-react';
import type { InventoryOverviewResource } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';

export interface InventoryOverviewBulkBarProps {
  selectedRows: InventoryOverviewResource[];
  onClearSelection: () => void;
  onPrintSelected?: () => void;
}

export function InventoryOverviewBulkBar({
  selectedRows,
  onClearSelection,
  onPrintSelected,
}: InventoryOverviewBulkBarProps) {
  const count = selectedRows.length;
  if (count === 0) return null;

  return (
    <div
      role="region"
      aria-label="Bulk actions"
      className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card p-3 shadow-xs"
    >
      <div className="flex items-center gap-2">
        <Badge variant="secondary" className="font-medium">
          {count} {count === 1 ? 'item' : 'items'} selected
        </Badge>
        <span className="text-xs text-muted-foreground">on this page</span>
      </div>

      <div className="flex items-center gap-2">
        {onPrintSelected && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={onPrintSelected}
            className="h-8 gap-1.5"
          >
            <Printer className="h-3.5 w-3.5" />
            Print selected
          </Button>
        )}

        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={onClearSelection}
          className="h-8 gap-1 text-muted-foreground hover:text-foreground"
        >
          <X className="h-3.5 w-3.5" />
          Clear selection
        </Button>
      </div>
    </div>
  );
}
