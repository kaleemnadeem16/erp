import { useState } from 'react';
import { useOpportunitiesClose, useOpportunitiesReassign, useOpportunitiesStage, type OpportunityResource } from '@erp/core-api';

import { OwnerCombobox } from '../../components/lookups/entity-comboboxes';
import { PrintTrigger } from '../../components/print';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Textarea } from '../../components/ui/textarea';
import { BulkRunDialog } from './bulk-run-dialog';

type Row = OpportunityResource;
type BulkKind = 'owner' | 'stage' | 'close';

const label = (row: Row) => row.number ?? `#${row.id}`;
const TITLE: Record<BulkKind, string> = { owner: 'Change who is responsible', stage: 'Move to a stage', close: 'Close these opportunities' };

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
      {/* A fresh dialog (and so a fresh result) each time one is opened. */}
      <OpportunityBulkDialog key={kind ?? 'none'} kind={kind} selected={selected} eligible={eligible} stages={stages} onClose={() => setKind(null)} onChanged={onChanged} />
    </div>
  );
}

function OpportunityBulkDialog({
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
  const skipped = selected.length - eligible.length;
  const ready = kind === 'owner' ? ownerId !== '' : kind === 'stage' ? stageId !== '' : reason.trim() !== '';

  return (
    <BulkRunDialog
      open={kind !== null}
      title={kind ? TITLE[kind] : ''}
      intro={`${eligible.length === 1 ? 'One open opportunity' : `${eligible.length} open opportunities`} will be changed, each by its own request.${skipped > 0 ? ` ${skipped} closed ${skipped === 1 ? 'one is' : 'ones are'} left out — reopen a deal before changing it.` : ''}`}
      rows={eligible}
      ready={ready}
      labelOf={label}
      onClose={onClose}
      onChanged={onChanged}
      run={(row) => {
        if (kind === 'owner') return reassign.mutateAsync({ opportunity: row.id, data: { owner_id: Number(ownerId), reason: reason.trim() || null } });
        if (kind === 'stage') return stage.mutateAsync({ opportunity: row.id, data: { stage_id: Number(stageId) } });

        return close.mutateAsync({ opportunity: row.id, data: { outcome, reason: reason.trim() } });
      }}
    >
      {kind === 'owner' && (
        <>
          <div className="space-y-1.5">
            <Label>New owner</Label>
            <OwnerCombobox label="New owner" placeholder="Choose a person" className="w-full" value={ownerId} onValueChange={setOwnerId} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="bulk-owner-reason">Reason (optional)</Label>
            <Input id="bulk-owner-reason" value={reason} onChange={(event) => setReason(event.target.value)} />
          </div>
        </>
      )}
      {kind === 'stage' && (
        <div className="space-y-1.5">
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
          <div className="space-y-1.5">
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
          <div className="space-y-1.5">
            <Label htmlFor="bulk-close-reason">Why?</Label>
            <Textarea id="bulk-close-reason" rows={2} value={reason} onChange={(event) => setReason(event.target.value)} />
          </div>
        </>
      )}
    </BulkRunDialog>
  );
}
