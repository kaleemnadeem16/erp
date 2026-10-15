import { useState } from 'react';
import { useItemQuantityUnitsDestroy } from '@erp/core-api';

import { PrintTrigger } from '../../components/print';
import { Button } from '../../components/ui/button';
import { BulkRunDialog } from '../../components/bulk-run-dialog';
import type { PackRow } from './pack-conversion-common';

const label = (row: PackRow) => `${row.item ? row.item.code : `Item #${row.item_id}`} / ${row.uom ? row.uom.code : `Unit #${row.uom_id}`}`;

/** What can be done to the pack conversions ticked on the current page: print them, or delete them, one request each. */
export function PackConversionBulkBar({
  selected,
  mayManage,
  onPrintSelected,
  onClear,
  onChanged,
}: {
  selected: PackRow[];
  mayManage: boolean;
  onPrintSelected: () => void;
  onClear: () => void;
  onChanged: () => Promise<void> | void;
}) {
  // The ticked rows are remembered when the dialog opens: deleting takes them out of the list, and the result must stay readable.
  const [deleting, setDeleting] = useState<PackRow[] | null>(null);

  return (
    <>
      {selected.length > 0 && (
        <div role="region" aria-label="Bulk actions" className="ui-bulk-bar flex flex-wrap items-center gap-3 rounded-md border bg-muted/40 px-3 py-2 text-sm" data-testid="pack-bulk-bar">
          <span className="font-medium" data-testid="bulk-count">
            {selected.length} selected
          </span>
          <PrintTrigger onPrint={onPrintSelected} label="Print selected" className="h-8" />
          {mayManage && (
            <Button variant="outline" size="sm" className="h-8" onClick={() => setDeleting(selected)} data-testid="bulk-delete">
              Delete…
            </Button>
          )}
          <Button variant="ghost" size="sm" className="h-8" onClick={onClear}>
            Clear selection
          </Button>
        </div>
      )}
      {deleting && <PackBulkDeleteDialog selected={deleting} onClose={() => setDeleting(null)} onChanged={onChanged} />}
    </>
  );
}

function PackBulkDeleteDialog({ selected, onClose, onChanged }: { selected: PackRow[]; onClose: () => void; onChanged: () => Promise<void> | void }) {
  const destroy = useItemQuantityUnitsDestroy();

  return (
    <BulkRunDialog
      open
      title="Delete these pack conversions"
      intro={`${selected.length} pack ${selected.length === 1 ? 'conversion' : 'conversions'} will be deleted, each by its own request.`}
      rows={selected}
      ready
      labelOf={label}
      onClose={onClose}
      onChanged={onChanged}
      run={(row) => destroy.mutateAsync({ itemQuantityUnit: row.id })}
    >
      <p className="text-sm text-muted-foreground">Orders and receipts already made keep the factor they used. New lines for these units will no longer convert until a conversion is added again.</p>
    </BulkRunDialog>
  );
}
