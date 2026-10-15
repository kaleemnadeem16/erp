import { useRef, useState } from 'react';
import { useLeadsAssign, useLeadsDisqualify, useLeadsQualify, useLeadsRevive, type LeadResource } from '@erp/core-api';

import { OwnerCombobox } from '../../components/lookups/entity-comboboxes';
import { PrintTrigger } from '../../components/print';
import { Button } from '../../components/ui/button';
import { Label } from '../../components/ui/label';
import { Textarea } from '../../components/ui/textarea';
import { BulkRunDialog } from './bulk-run-dialog';
import { newKey } from './sales-common';

type Row = LeadResource;
type BulkKind = 'assign' | 'qualify' | 'disqualify' | 'revive';

const label = (row: Row) => row.number ?? `#${row.id}`;
const TITLE: Record<BulkKind, string> = { assign: 'Assign these enquiries', qualify: 'Qualify these enquiries', disqualify: 'Disqualify these enquiries', revive: 'Revive these enquiries' };
const NOUN: Record<BulkKind, [string, string]> = {
  assign: ['enquiry', 'enquiries'],
  qualify: ['new enquiry', 'new enquiries'],
  disqualify: ['enquiry that is not already disqualified', 'enquiries that are not already disqualified'],
  revive: ['disqualified enquiry', 'disqualified enquiries'],
};

/**
 * What can be done to the enquiries ticked on the current page. Each action applies only to the enquiries it makes sense for (qualify: new ones; revive: disqualified ones) and
 * the rest are counted and left out. Each needs its own duty, exactly as on a single enquiry; the server still decides every one.
 */
export function LeadBulkBar({
  selected,
  showOwner,
  mayManage,
  mayAssign,
  onPrintSelected,
  onClear,
  onChanged,
}: {
  selected: Row[];
  showOwner: boolean;
  mayManage: boolean;
  mayAssign: boolean;
  onPrintSelected: () => void;
  onClear: () => void;
  onChanged: () => Promise<void> | void;
}) {
  const [kind, setKind] = useState<BulkKind | null>(null);
  const eligibleFor: Record<BulkKind, Row[]> = {
    assign: selected,
    qualify: selected.filter((row) => row.status === 'NEW'),
    disqualify: selected.filter((row) => row.status !== 'DISQUALIFIED'),
    revive: selected.filter((row) => row.status === 'DISQUALIFIED'),
  };
  const action = (target: BulkKind, text: string, allowed: boolean) =>
    allowed &&
    eligibleFor[target].length > 0 && (
      <Button variant="outline" size="sm" className="h-8" onClick={() => setKind(target)} data-testid={`bulk-${target}`}>
        {text}
      </Button>
    );

  return (
    <div role="region" aria-label="Bulk actions" className="ui-bulk-bar flex flex-wrap items-center gap-3 rounded-md border bg-muted/40 px-3 py-2 text-sm" data-testid="lead-bulk-bar">
      <span className="font-medium" data-testid="bulk-count">
        {selected.length} selected
      </span>
      <PrintTrigger onPrint={onPrintSelected} label="Print selected" className="h-8" />
      {action('assign', 'Assign…', showOwner && mayAssign)}
      {action('qualify', 'Qualify…', mayManage)}
      {action('disqualify', 'Disqualify…', mayManage)}
      {action('revive', 'Revive…', mayManage)}
      <Button variant="ghost" size="sm" className="h-8" onClick={onClear}>
        Clear selection
      </Button>
      {kind && <LeadBulkDialog key={kind} kind={kind} selected={selected} eligible={eligibleFor[kind]} onClose={() => setKind(null)} onChanged={onChanged} />}
    </div>
  );
}

function LeadBulkDialog({ kind, selected, eligible, onClose, onChanged }: { kind: BulkKind; selected: Row[]; eligible: Row[]; onClose: () => void; onChanged: () => Promise<void> | void }) {
  const assign = useLeadsAssign();
  const qualify = useLeadsQualify();
  const disqualify = useLeadsDisqualify();
  const revive = useLeadsRevive();
  const [ownerId, setOwnerId] = useState('');
  const [reason, setReason] = useState('');
  // One request key per enquiry for this confirmation: a retry of the same decision replays on the server instead of recording it twice.
  const keys = useRef(new Map<number, string>());
  const keyOf = (row: Row) => {
    if (!keys.current.has(row.id)) keys.current.set(row.id, newKey());

    return keys.current.get(row.id) as string;
  };
  const skipped = selected.length - eligible.length;
  const ready = kind === 'assign' ? ownerId !== '' : kind === 'qualify' ? true : reason.trim() !== '';

  return (
    <BulkRunDialog
      open
      title={TITLE[kind]}
      intro={`${eligible.length} ${NOUN[kind][eligible.length === 1 ? 0 : 1]} will be changed, each by its own request.${skipped > 0 ? ` ${skipped} ${skipped === 1 ? 'is' : 'are'} left out because ${kind === 'assign' ? 'it cannot be assigned' : 'it is not in the right state'}.` : ''}`}
      rows={eligible}
      ready={ready}
      labelOf={label}
      onClose={onClose}
      onChanged={onChanged}
      run={(row) => {
        if (kind === 'assign') return assign.mutateAsync({ lead: row.id, data: { assigned_to: Number(ownerId) } });
        if (kind === 'qualify') return qualify.mutateAsync({ lead: row.id, data: {} });
        if (kind === 'revive') return revive.mutateAsync({ lead: row.id, data: { reason: reason.trim() } });

        return disqualify.mutateAsync({ lead: row.id, data: { reason: reason.trim(), request_key: keyOf(row), expected_decision_event_id: row.decision_event_id } });
      }}
    >
      {kind === 'assign' && (
        <div className="space-y-1.5">
          <Label>Assign to</Label>
          <OwnerCombobox label="Assign to" placeholder="Choose a person" className="w-full" value={ownerId} onValueChange={setOwnerId} />
          <p className="text-xs text-muted-foreground">Only active members can be chosen. Assigning gives them responsibility, not extra access.</p>
        </div>
      )}
      {kind === 'qualify' && <p className="text-sm text-muted-foreground">Qualifying records that the enquiry is worth pursuing. Each is recorded in its own history.</p>}
      {(kind === 'disqualify' || kind === 'revive') && (
        <div className="space-y-1.5">
          <Label htmlFor="bulk-lead-reason">Why?</Label>
          <Textarea id="bulk-lead-reason" rows={2} value={reason} onChange={(event) => setReason(event.target.value)} />
          <p className="text-xs text-muted-foreground">
            {kind === 'disqualify' ? 'Each enquiry stays on record with this reason. Deals already created from it stay open.' : 'Each returns to new; what was decided before stays in its history.'}
          </p>
        </div>
      )}
    </BulkRunDialog>
  );
}
