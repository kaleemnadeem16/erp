import { useState } from 'react';
import { useCategoriesDestroy, type CategoryResource } from '@erp/core-api';

import { PrintTrigger } from '../../components/print';
import { Button } from '../../components/ui/button';
import { BulkRunDialog } from '../../components/bulk-run-dialog';

const label = (row: CategoryResource) => `${row.code} — ${row.name}`;

/** What can be done to the categories ticked on the current page: print them, or delete them, one request each. */
export function CategoryBulkBar({
  selected,
  mayManage,
  onPrintSelected,
  onClear,
  onChanged,
}: {
  selected: CategoryResource[];
  mayManage: boolean;
  onPrintSelected: () => void;
  onClear: () => void;
  onChanged: () => Promise<void> | void;
}) {
  // The ticked rows are remembered when the dialog opens: deleting takes them out of the list, and the result must stay readable.
  const [deleting, setDeleting] = useState<CategoryResource[] | null>(null);

  return (
    <>
      {selected.length > 0 && (
        <div
          role="region"
          aria-label="Bulk actions"
          className="ui-bulk-bar flex flex-wrap items-center gap-3 rounded-md border bg-muted/40 px-3 py-2 text-sm"
          data-testid="category-bulk-bar"
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
              onClick={() => setDeleting(selected)}
              data-testid="bulk-delete"
            >
              Delete…
            </Button>
          )}
          <Button variant="ghost" size="sm" className="h-8" onClick={onClear}>
            Clear selection
          </Button>
        </div>
      )}
      {deleting && (
        <CategoryBulkDeleteDialog selected={deleting} onClose={() => setDeleting(null)} onChanged={onChanged} />
      )}
    </>
  );
}

function CategoryBulkDeleteDialog({
  selected,
  onClose,
  onChanged,
}: {
  selected: CategoryResource[];
  onClose: () => void;
  onChanged: () => Promise<void> | void;
}) {
  const destroy = useCategoriesDestroy();

  return (
    <BulkRunDialog
      open
      title="Delete these categories"
      intro={`${selected.length} ${selected.length === 1 ? 'category' : 'categories'} will be deleted, each by its own request.`}
      rows={selected}
      ready
      labelOf={label}
      onClose={onClose}
      onChanged={onChanged}
      run={(row) => destroy.mutateAsync({ category: row.id })}
    >
      <p className="text-sm text-muted-foreground">
        Categories referenced by items or child categories cannot be deleted and will be reported with the server's refusal reason.
      </p>
    </BulkRunDialog>
  );
}

