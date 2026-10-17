import { useState } from 'react';
import { useWarehousesDestroy, type WarehouseResource } from '@erp/core-api';

import { PrintTrigger } from '../../components/print';
import { Button } from '../../components/ui/button';
import { BulkRunDialog } from '../../components/bulk-run-dialog';

const label = (row: WarehouseResource) => `${row.code} — ${row.name}`;

/** What can be done to the warehouses ticked on the current page: print them, or archive/deactivate them, one request each. */
export function WarehouseBulkBar({
  selected,
  mayManage,
  onPrintSelected,
  onClear,
  onChanged,
}: {
  selected: WarehouseResource[];
  mayManage: boolean;
  onPrintSelected: () => void;
  onClear: () => void;
  onChanged: () => Promise<void> | void;
}) {
  // The ticked rows are remembered when the dialog opens: archiving takes them out of the active list, and the result must stay readable.
  const [archiving, setArchiving] = useState<WarehouseResource[] | null>(null);

  return (
    <>
      {selected.length > 0 && (
        <div
          role="region"
          aria-label="Bulk actions"
          className="ui-bulk-bar flex flex-wrap items-center gap-3 rounded-md border bg-muted/40 px-3 py-2 text-sm"
          data-testid="warehouse-bulk-bar"
        >
          <span className="font-medium" data-testid="bulk-count">
            {selected.length} selected
          </span>
          <PrintTrigger onPrint={onPrintSelected} label="Print selected" className="h-8" />
          {mayManage && (
            <Button
              variant="outline"
              size="sm"
              className="h-8"
              onClick={() => setArchiving(selected)}
              data-testid="bulk-archive"
            >
              Archive…
            </Button>
          )}
          <Button variant="ghost" size="sm" className="h-8" onClick={onClear}>
            Clear selection
          </Button>
        </div>
      )}
      {archiving && (
        <WarehouseBulkArchiveDialog
          selected={archiving}
          onClose={() => setArchiving(null)}
          onChanged={onChanged}
        />
      )}
    </>
  );
}

function WarehouseBulkArchiveDialog({
  selected,
  onClose,
  onChanged,
}: {
  selected: WarehouseResource[];
  onClose: () => void;
  onChanged: () => Promise<void> | void;
}) {
  const destroy = useWarehousesDestroy();

  return (
    <BulkRunDialog
      open
      title="Archive these warehouses"
      intro={`${selected.length} ${selected.length === 1 ? 'warehouse' : 'warehouses'} will be archived, each by its own request.`}
      rows={selected}
      ready
      labelOf={label}
      onClose={onClose}
      onChanged={onChanged}
      run={(row) => destroy.mutateAsync({ warehouse: row.id })}
    >
      <p className="text-sm text-muted-foreground">
        Archiving a warehouse deactivates it and all of its locations. It cannot be used for new stock receipts or movements while inactive.
      </p>
    </BulkRunDialog>
  );
}

