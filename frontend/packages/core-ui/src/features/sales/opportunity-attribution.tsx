import { useEffect, useState } from 'react';
import { useOpportunitiesAttributionCorrectionsStore, useOpportunitiesAttributionMembersIndex, type OpportunityResource } from '@erp/core-api';

import { DetailSection } from '../../components/detail/detail-page-layout';
import { Button } from '../../components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../../components/ui/dialog';
import { Label } from '../../components/ui/label';
import { RemoteLookupCombobox } from '../../components/ui/remote-lookup-combobox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Textarea } from '../../components/ui/textarea';
import { getApiErrorMessage, getApiReasonCode, lookupState } from '../../lib/api-errors';
import { formatDateTime } from '../../lib/format-date';
import { useIntentKey } from './sales-common';

type Fact = NonNullable<OpportunityResource['responsibility']>[number];

const factLabel = (fact: Fact) =>
  `${fact.target_field === 'OWNER_ID' ? 'Created for' : 'Reassigned to'} ${fact.recorded_owner_name ?? `member #${fact.recorded_owner_id}`} · ${formatDateTime(fact.occurred_at)}`;

function useDelayed(value: string, delayMs = 250): string {
  const [delayed, setDelayed] = useState(value);
  useEffect(() => {
    const handle = window.setTimeout(() => setDelayed(value), value === '' ? 0 : delayMs);
    return () => window.clearTimeout(handle);
  }, [value, delayMs]);

  return delayed;
}

/** Who the record says was responsible on a closed deal, as recorded and as corrected; nothing here changes who owns the deal now. */
export function ResponsibilityRecord({ opportunity, onCorrect }: { opportunity: OpportunityResource; onCorrect: () => void }) {
  const facts = opportunity.responsibility ?? [];
  const chain = opportunity.attribution_corrections ?? [];

  return (
    <DetailSection title="Recorded responsibility" description="Who the record says was responsible. A correction adds to this record; the original entry and the deal itself are not changed." fullWidth>
      <div className="space-y-3 sm:col-span-2" data-testid="responsibility-record">
        {facts.length === 0 && <p className="text-sm text-muted-foreground">No owner has been recorded.</p>}
        <ul className="space-y-2 text-sm">
          {facts.map((fact) => (
            <li key={`${fact.event_id}:${fact.target_field}`} data-testid="responsibility-fact">
              <p className="font-medium">{factLabel(fact)}</p>
              {fact.corrected && (
                <p className="text-muted-foreground" data-testid="responsibility-corrected">
                  Corrected to {fact.effective_owner_name ?? `member #${fact.effective_owner_id}`} (recorded: {fact.recorded_owner_name ?? `member #${fact.recorded_owner_id}`})
                </p>
              )}
            </li>
          ))}
        </ul>
        {chain.length > 0 && (
          <ol className="space-y-2 border-l-2 border-border pl-3 text-sm" data-testid="attribution-chain">
            {[...chain].reverse().map((correction) => (
              <li key={correction.id}>
                <p>
                  {correction.recorded_owner_name ?? `#${correction.recorded_owner_id}`} → {correction.asserted_owner_name ?? `#${correction.asserted_owner_id}`}
                </p>
                <p className="text-muted-foreground">“{correction.reason}”</p>
                <p className="text-xs text-muted-foreground">
                  {correction.actor_name ?? 'Someone'} · {formatDateTime(correction.recorded_at)}
                </p>
              </li>
            ))}
          </ol>
        )}
        {opportunity.can_correct_attribution && facts.length > 0 && (
          <Button variant="outline" size="sm" onClick={onCorrect} data-testid="attribution-correct">
            Correct who was responsible…
          </Button>
        )}
      </div>
    </DetailSection>
  );
}

/** Pick one recorded owner, name the member the record should say, and give the reason. The deal is not reopened and not edited. */
export function AttributionCorrectionDialog({ opportunity, open, onClose, onSaved }: { opportunity: OpportunityResource; open: boolean; onClose: () => void; onSaved: () => Promise<void> }) {
  const facts = opportunity.responsibility ?? [];
  const store = useOpportunitiesAttributionCorrectionsStore();
  const keyFor = useIntentKey();
  const [factKey, setFactKey] = useState('');
  const [memberId, setMemberId] = useState('');
  const [memberName, setMemberName] = useState<string | undefined>();
  const [search, setSearch] = useState('');
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const delayed = useDelayed(search);
  const members = useOpportunitiesAttributionMembersIndex(opportunity.id, { 'filter[search]': delayed || undefined }, { query: { enabled: open } });
  const rows = members.data?.status === 200 ? members.data.data.data : [];
  const fact = facts.find((candidate) => `${candidate.event_id}:${candidate.target_field}` === factKey);
  const valid = fact !== undefined && memberId !== '' && Number(memberId) !== fact.effective_owner_id && reason.trim() !== '';

  function close() {
    setFactKey('');
    setMemberId('');
    setMemberName(undefined);
    setSearch('');
    setReason('');
    setError(null);
    onClose();
  }

  async function save() {
    if (fact === undefined || opportunity.closure_event_id === null || opportunity.closure_event_id === undefined) return;
    setError(null);
    const data = {
      target_event_id: fact.event_id,
      target_field: fact.target_field as 'OWNER_ID' | 'TO_OWNER_ID',
      asserted_owner_id: Number(memberId),
      expected_predecessor_id: fact.head_id,
      expected_closure_event_id: opportunity.closure_event_id,
      reason: reason.trim(),
    };
    try {
      await store.mutateAsync({ opportunity: opportunity.id, data: { ...data, request_key: keyFor(JSON.stringify(data)) } });
      await onSaved();
      close();
    } catch (failure) {
      const code = getApiReasonCode(failure);
      if (code === 'STALE_EVIDENCE' || code === 'ATTRIBUTION_REQUIRES_CLOSED') {
        setError('This deal changed since you opened it (it was corrected, reopened or closed again). It has been refreshed: review it, then correct again.');
        await onSaved();
      } else {
        setError(getApiErrorMessage(failure));
      }
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !next && close()}>
      <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto" data-testid="attribution-dialog">
        <DialogHeader>
          <DialogTitle>Correct who was responsible</DialogTitle>
          <DialogDescription>
            This corrects what the record says, not who owns the deal. The deal stays closed and unchanged, the original entry stays visible, and your reason is kept.
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-3">
          <div className="space-y-1">
            <Label htmlFor="attribution-fact">Which entry is wrong?</Label>
            <Select value={factKey} onValueChange={setFactKey}>
              <SelectTrigger id="attribution-fact">
                <SelectValue placeholder="Choose a recorded owner" />
              </SelectTrigger>
              <SelectContent>
                {facts.map((candidate) => (
                  <SelectItem key={`${candidate.event_id}:${candidate.target_field}`} value={`${candidate.event_id}:${candidate.target_field}`}>
                    {factLabel(candidate)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1">
            <Label>Who should the record say?</Label>
            <RemoteLookupCombobox
              {...lookupState(members, 'people')}
              label="Member"
              searchPlaceholder="Search members, including former colleagues"
              value={memberId}
              onValueChange={(value) => {
                setMemberId(value);
                setMemberName(rows.find((row) => String(row.id) === value)?.name);
              }}
              search={search}
              onSearchChange={setSearch}
              searching={search !== delayed || (search !== '' && members.isFetching)}
              selectedLabel={memberName}
              options={rows.map((member) => ({ value: String(member.id), label: member.status === 'ACTIVE' ? member.name : `${member.name} (no longer active)` }))}
            />
          </div>
          <div className="space-y-1">
            <Label htmlFor="attribution-reason">Why is this being corrected?</Label>
            <Textarea id="attribution-reason" rows={2} value={reason} onChange={(event) => setReason(event.target.value)} />
          </div>
        </div>
        {error && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={close}>
            Go back
          </Button>
          <Button disabled={!valid || store.isPending} onClick={() => void save()} data-testid="attribution-confirm">
            {store.isPending ? 'Saving…' : 'Save correction'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
