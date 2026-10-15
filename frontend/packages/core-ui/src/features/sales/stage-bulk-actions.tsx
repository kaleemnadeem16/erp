import { useState } from 'react';
import { useSalesStagesUpdate } from '@erp/core-api';

import { PrintTrigger } from '../../components/print';
import { Button } from '../../components/ui/button';
import { BulkRunDialog } from '../../components/bulk-run-dialog';
import type { StageRow } from './stage-common';

type BulkKind = 'archive' | 'restore';

/**
 * What can be done to the stages ticked on the current page: archive the active ones or restore the archived ones, one request each. The server keeps at least one stage
 * active, so an archive that would leave none is refused by name rather than half-applied.
 */
export function StageBulkBar({
  selected,
  mayManage,
  onPrintSelected,
  onClear,
  onChanged,
}: {
  selected: StageRow[];
  mayManage: boolean;
  onPrintSelected: () => void;
  onClear: () => void;
  onChanged: () => Promise<void> | void;
}) {
  // The ticked rows are remembered when a dialog opens: a change can take them out of the current view, and the result must stay readable.
  const [dialog, setDialog] = useState<{ kind: BulkKind; selected: StageRow[] } | null>(null);
  const active = selected.filter((stage) => stage.status === 'ACTIVE');
  const archived = selected.filter((stage) => stage.status === 'ARCHIVED');

  return (
    <>
      {selected.length > 0 && (
        <div role="region" aria-label="Bulk actions" className="ui-bulk-bar flex flex-wrap items-center gap-3 rounded-md border bg-muted/40 px-3 py-2 text-sm" data-testid="stage-bulk-bar">
          <span className="font-medium" data-testid="bulk-count">
            {selected.length} selected
          </span>
          <PrintTrigger onPrint={onPrintSelected} label="Print selected" className="h-8" />
          {mayManage && active.length > 0 && (
            <Button variant="outline" size="sm" className="h-8" onClick={() => setDialog({ kind: 'archive', selected })} data-testid="bulk-archive">
              Archive…
            </Button>
          )}
          {mayManage && archived.length > 0 && (
            <Button variant="outline" size="sm" className="h-8" onClick={() => setDialog({ kind: 'restore', selected })} data-testid="bulk-restore">
              Restore…
            </Button>
          )}
          <Button variant="ghost" size="sm" className="h-8" onClick={onClear}>
            Clear selection
          </Button>
        </div>
      )}
      {dialog && (
        <StageBulkDialog
          key={dialog.kind}
          kind={dialog.kind}
          selected={dialog.selected}
          eligible={dialog.selected.filter((stage) => stage.status === (dialog.kind === 'archive' ? 'ACTIVE' : 'ARCHIVED'))}
          onClose={() => setDialog(null)}
          onChanged={onChanged}
        />
      )}
    </>
  );
}

function StageBulkDialog({ kind, selected, eligible, onClose, onChanged }: { kind: BulkKind; selected: StageRow[]; eligible: StageRow[]; onClose: () => void; onChanged: () => Promise<void> | void }) {
  const update = useSalesStagesUpdate();
  const skipped = selected.length - eligible.length;
  const noun = eligible.length === 1 ? 'stage' : 'stages';

  return (
    <BulkRunDialog
      open
      title={kind === 'archive' ? 'Archive these stages' : 'Restore these stages'}
      intro={`${eligible.length} ${kind === 'archive' ? 'active' : 'archived'} ${noun} will be changed, each by its own request.${skipped > 0 ? ` ${skipped} ${skipped === 1 ? 'is' : 'are'} left out because ${skipped === 1 ? 'it is' : 'they are'} already ${kind === 'archive' ? 'archived' : 'active'}.` : ''}`}
      rows={eligible}
      ready
      labelOf={(stage) => stage.name}
      onClose={onClose}
      onChanged={onChanged}
      run={(stage) => update.mutateAsync({ stage: stage.id, data: { status: kind === 'archive' ? 'ARCHIVED' : 'ACTIVE' } })}
    >
      <p className="text-sm text-muted-foreground">
        {kind === 'archive'
          ? 'An archived stage can no longer be chosen for a deal, but everything recorded about it keeps its name. At least one stage always stays active.'
          : 'Restored stages can be chosen for deals again.'}
      </p>
    </BulkRunDialog>
  );
}
