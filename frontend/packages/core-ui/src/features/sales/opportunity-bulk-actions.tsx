import { useState } from 'react';
import { useOpportunitiesClose, useOpportunitiesReassign, useOpportunitiesStage, type OpportunityResource } from '@erp/core-api';

import { OwnerCombobox } from '../../components/lookups/entity-comboboxes';
import { PrintTrigger } from '../../components/print';
import { Button } from '../../components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../../components/ui/dialog';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Textarea } from '../../components/ui/textarea';
import { getApiErrorMessage } from '../../lib/api-errors';

type Row = OpportunityResource;
type BulkKind = 'owner' | 'stage' | 'close';

interface Failure {
  row: Row;
  message: string;
}

/** Each record is changed by its own request, one after another, so one refusal never undoes or blocks the rest; what could not be changed is reported by name. */
async function applyToEach(rows: Row[], change: (row: Row) => Promise<unknown>, onProgress: (done: number) => void): Promise<{ done: Row[]; failed: Failure[] }> {
  const done: Row[] = [];
  const failed: Failure[] = [];
  for (const [index, row] of rows.entries()) {
    try {
      await change(row);
      done.push(row);
    } catch (failure) {
      failed.push({ row, message: getApiErrorMessage(failure) ?? 'The change was refused.' });
    }
    onProgress(index + 1);
  }

  return { done, failed };
}

const label = (row: Row) => row.number ?? `#${row.id}`;

/**
 * What can be done to the deals ticked on the current page. Only open deals can be changed (a closed deal is reopened first), so closed ones are counted and skipped,
 * never half-applied. Each action needs its own duty, exactly as on a single deal; the server still decides every one.
 */
export function OpportunityBulkBar({
  selected,
  stages,
  showStage,
  showOwner,
  mayManage,
  mayAssign,
  mayClose,
  onPrintSelected,
  onClear,
  onChanged,
}: {
  selected: Row[];
  stages: Array<{ id: number; name: string }>;
  showStage: boolean;
  showOwner: boolean;
  mayManage: boolean;
  mayAssign: boolean;
  mayClose: boolean;
  onPrintSelected: () => void;
  onClear: () => void;
  onChanged: () => Promise<void> | void;
}) {
  const [kind, setKind] = useState<BulkKind | null>(null);
  const eligible = selected.filter((row) => row.outcome === 'OPEN');

  return (
    <div role="region" aria-label="Bulk actions" className="ui-bulk-bar flex flex-wrap items-center gap-3 rounded-md border bg-muted/40 px-3 py-2 text-sm" data-testid="opportunity-bulk-bar">
      <span className="font-medium" data-testid="bulk-count">
        {selected.length} selected
      </span>
      <PrintTrigger onPrint={onPrintSelected} label="Print selected" className="h-8" />
      {showOwner && mayAssign && eligible.length > 0 && (
        <Button variant="outline" size="sm" className="h-8" onClick={() => setKind('owner')} data-testid="bulk-owner">
          Change owner…
        </Button>
      )}
      {showStage && mayManage && eligible.length > 0 && (
        <Button variant="outline" size="sm" className="h-8" onClick={() => setKind('stage')} data-testid="bulk-stage">
          Move stage…
        </Button>
      )}
      {mayClose && eligible.length > 0 && (
        <Button variant="outline" size="sm" className="h-8" onClick={() => setKind('close')} data-testid="bulk-close">
          Close…
        </Button>
      )}
      <Button variant="ghost" size="sm" className="h-8" onClick={onClear}>
        Clear selection
      </Button>
      <BulkDialog
        // A fresh dialog (and so a fresh result) each time one is opened.
        key={kind ?? 'none'}
        kind={kind}
        selected={selected}
        eligible={eligible}
        stages={stages}
        onClose={() => setKind(null)}
        onChanged={onChanged}
      />
    </div>
  );
}

const TITLE: Record<BulkKind, string> = { owner: 'Change who is responsible', stage: 'Move to a stage', close: 'Close these opportunities' };

function BulkDialog({
  kind,
  selected,
  eligible,
  stages,
  onClose,
  onChanged,
}: {
  kind: BulkKind | null;
  selected: Row[];
  eligible: Row[];
  stages: Array<{ id: number; name: string }>;
  onClose: () => void;
  onChanged: () => Promise<void> | void;
}) {
  const reassign = useOpportunitiesReassign();
  const stage = useOpportunitiesStage();
  const close = useOpportunitiesClose();
  const [ownerId, setOwnerId] = useState('');
  const [stageId, setStageId] = useState('');
  const [outcome, setOutcome] = useState<'LOST' | 'ABANDONED'>('LOST');
  const [reason, setReason] = useState('');
  const [progress, setProgress] = useState<number | null>(null);
  const [result, setResult] = useState<{ done: number; failed: Failure[] } | null>(null);
  const [remaining, setRemaining] = useState<Row[]>(eligible);

  const skipped = selected.length - eligible.length;
  const running = progress !== null;
  const ready = kind === 'owner' ? ownerId !== '' : kind === 'stage' ? stageId !== '' : reason.trim() !== '';

  async function run() {
    if (!kind) return;
    setProgress(0);
    const outcomeRows = await applyToEach(
      remaining,
      (row) => {
        if (kind === 'owner') return reassign.mutateAsync({ opportunity: row.id, data: { owner_id: Number(ownerId), reason: reason.trim() || null } });
        if (kind === 'stage') return stage.mutateAsync({ opportunity: row.id, data: { stage_id: Number(stageId) } });

        return close.mutateAsync({ opportunity: row.id, data: { outcome, reason: reason.trim() } });
      },
      setProgress,
    );
    setProgress(null);
    setRemaining(outcomeRows.failed.map((failure) => failure.row));
    setResult((previous) => ({ done: (previous?.done ?? 0) + outcomeRows.done.length, failed: outcomeRows.failed }));
    await onChanged();
  }

  const finished = result !== null && result.failed.length === 0;

  return (
    <Dialog open={kind !== null} onOpenChange={(next) => !next && !running && onClose()}>
      <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto" data-testid="bulk-dialog">
        <DialogHeader>
          <DialogTitle>{kind ? TITLE[kind] : ''}</DialogTitle>
          <DialogDescription>
            {remaining.length === 1 ? 'One open opportunity' : `${remaining.length} open opportunities`} will be changed, each by its own request.
            {skipped > 0 && result === null ? ` ${skipped} closed ${skipped === 1 ? 'one is' : 'ones are'} left out — reopen a deal before changing it.` : ''}
          </DialogDescription>
        </DialogHeader>
        {!finished && (
          <div className="space-y-3">
            {kind === 'owner' && (
              <>
                <div className="space-y-1">
                  <Label>New owner</Label>
                  <OwnerCombobox label="New owner" placeholder="Choose a person" className="w-full" value={ownerId} onValueChange={setOwnerId} />
                </div>
                <div className="space-y-1">
                  <Label htmlFor="bulk-owner-reason">Reason (optional)</Label>
                  <Input id="bulk-owner-reason" value={reason} onChange={(event) => setReason(event.target.value)} />
                </div>
              </>
            )}
            {kind === 'stage' && (
              <div className="space-y-1">
                <Label>Stage</Label>
                <Select value={stageId} onValueChange={setStageId}>
                  <SelectTrigger aria-label="Stage">
                    <SelectValue placeholder="Choose a stage" />
                  </SelectTrigger>
                  <SelectContent>
                    {stages.map((option) => (
                      <SelectItem key={option.id} value={String(option.id)}>
                        {option.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            )}
            {kind === 'close' && (
              <>
                <p className="text-sm text-muted-foreground">Closing ends the pursuit. It changes no quotation or job, and each deal can be reopened later.</p>
                <div className="space-y-1">
                  <Label>How did it end?</Label>
                  <Select value={outcome} onValueChange={(value) => setOutcome(value as 'LOST' | 'ABANDONED')}>
                    <SelectTrigger aria-label="Outcome">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="LOST">Lost — we did not win the business</SelectItem>
                      <SelectItem value="ABANDONED">Abandoned — we stopped pursuing it</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1">
                  <Label htmlFor="bulk-close-reason">Why?</Label>
                  <Textarea id="bulk-close-reason" rows={2} value={reason} onChange={(event) => setReason(event.target.value)} />
                </div>
              </>
            )}
          </div>
        )}
        {running && (
          <p role="status" className="text-sm text-muted-foreground" data-testid="bulk-progress">
            Changing {progress} of {remaining.length}…
          </p>
        )}
        {result && (
          <div role="status" className="space-y-2 rounded-md border p-3 text-sm" data-testid="bulk-result">
            <p className="font-medium">
              {result.done} changed{result.failed.length > 0 ? `, ${result.failed.length} could not be changed` : '.'}
            </p>
            {result.failed.length > 0 && (
              <ul className="list-disc space-y-1 pl-5 text-destructive">
                {result.failed.map((failure) => (
                  <li key={failure.row.id}>
                    {label(failure.row)} — {failure.message}
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={running}>
            {finished ? 'Done' : 'Cancel'}
          </Button>
          {!finished && (
            <Button onClick={() => void run()} disabled={!ready || running || remaining.length === 0} data-testid="bulk-confirm">
              {result ? `Retry ${remaining.length} that failed` : `Change ${remaining.length}`}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
