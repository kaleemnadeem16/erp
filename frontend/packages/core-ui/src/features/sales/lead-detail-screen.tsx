import { useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useLeadsAssign, useLeadsConvert, useLeadsDisqualify, useLeadsLinkParty, useLeadsNotesStore, useLeadsQualify, useLeadsRevive, useLeadsShow } from '@erp/core-api';

import { DetailField } from '../../components/detail/detail-field';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { OwnerCombobox, PartyCombobox } from '../../components/lookups/entity-comboboxes';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Button } from '../../components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../../components/ui/dialog';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Skeleton } from '../../components/ui/skeleton';
import { Textarea } from '../../components/ui/textarea';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage, getApiReasonCode, isApiError } from '../../lib/api-errors';
import { formatDateTime } from '../../lib/format-date';
import { HistoryList, LeadStatusBadge, NotesList, OutcomeBadge, RegisterPartyDialog, SALES } from './sales-common';

type Mode = 'disqualify' | 'revive' | 'assign' | 'convert' | null;

const newKey = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `k-${Date.now()}-${Math.random().toString(16).slice(2)}`);

/** One enquiry: what was asked, who it resolved to, what was decided about it, and the deals it produced. */
export function LeadDetailScreen() {
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const mayLead = usePermission(SALES.lead);
  const mayOpportunity = usePermission(SALES.opportunity);
  const mayAssign = usePermission(SALES.assign);
  const query = useLeadsShow(id, { include: 'party,opportunities,notes,history' });
  const lead = query.data?.status === 200 ? query.data.data.data : undefined;
  const [mode, setMode] = useState<Mode>(null);
  const [error, setError] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [note, setNote] = useState('');
  const [partyId, setPartyId] = useState('');
  const [registering, setRegistering] = useState(false);
  const [ownerId, setOwnerId] = useState('');
  const [title, setTitle] = useState('');
  const conversionKey = useRef(newKey());
  // One key per intent: the same reason confirmed against the same decision retries safely; any change of either is a new request.
  const disqualifyAttempt = useRef<{ key: string; reason: string; marker: number | null } | null>(null);
  const qualify = useLeadsQualify();
  const disqualify = useLeadsDisqualify();
  const revive = useLeadsRevive();
  const assign = useLeadsAssign();
  const link = useLeadsLinkParty();
  const convert = useLeadsConvert();
  const addNote = useLeadsNotesStore();

  async function run(action: () => Promise<unknown>, done?: () => void) {
    setError(null);
    try {
      await action();
      await query.refetch();
      done?.();
    } catch (failure) {
      setError(getApiReasonCode(failure) === 'STALE_EVIDENCE' ? 'This enquiry was decided or converted since you opened it. It has been refreshed: review it, then confirm again.' : getApiErrorMessage(failure));
      // A stale confirmation is reviewed against fresh data, never overwritten.
      if (getApiReasonCode(failure) === 'STALE_EVIDENCE') await query.refetch();
    }
  }

  function disqualifyIntent(reasonText: string, marker: number | null) {
    const current = disqualifyAttempt.current;
    if (current === null || current.reason !== reasonText || current.marker !== marker) {
      disqualifyAttempt.current = { key: newKey(), reason: reasonText, marker };
    }

    return disqualifyAttempt.current!;
  }

  if (query.isLoading) return <Skeleton className="m-6 h-64" />;
  if (query.isError || !lead) {
    const denied = isApiError(query.error) && query.error.status === 403;

    return (
      <ScreenTemplate kind="detail" className="erp-screen">
        <div className="space-y-3" data-testid={denied ? 'sales-denied' : 'sales-error'}>
          <p className={denied ? 'text-sm text-muted-foreground' : 'text-sm text-destructive'}>{denied ? "You don't have permission to view this enquiry." : "Couldn't load this enquiry."}</p>
          <Button variant="outline" size="sm" onClick={() => navigate('/leads')}>
            Back to enquiries
          </Button>
        </div>
      </ScreenTemplate>
    );
  }

  const decided = lead.status === 'DISQUALIFIED';
  const linkedElsewhere = partyId !== '' && Number(partyId) !== lead.party_id;
  const closeDialog = () => {
    setMode(null);
    setReason('');
    setError(null);
    disqualifyAttempt.current = null;
  };

  return (
    <ScreenTemplate kind="detail" className="erp-screen pb-24">
      <div className="space-y-4" data-testid="lead-detail">
        <PageHeader
          title={lead.enquirer_organisation ? `${lead.enquirer_name} — ${lead.enquirer_organisation}` : lead.enquirer_name}
          description={lead.number ?? undefined}
          breadcrumbs={[{ label: 'Enquiries', to: '/leads' }, { label: lead.number ?? `#${lead.id}` }]}
          actions={
            <>
              {mayLead && lead.status === 'NEW' && (
                <Button variant="outline" onClick={() => void run(() => qualify.mutateAsync({ lead: id, data: {} }))} data-testid="lead-qualify">
                  Qualify
                </Button>
              )}
              {mayLead && !decided && (
                <Button variant="outline" onClick={() => setMode('disqualify')} data-testid="lead-disqualify">
                  Disqualify…
                </Button>
              )}
              {mayLead && decided && (
                <Button variant="outline" onClick={() => setMode('revive')} data-testid="lead-revive">
                  Revive…
                </Button>
              )}
              {mayOpportunity && !decided && (
                <Button
                  onClick={() => {
                    setTitle(lead.enquirer_organisation ?? lead.enquirer_name);
                    setMode('convert');
                  }}
                  data-testid="lead-convert"
                >
                  Create opportunity…
                </Button>
              )}
            </>
          }
        />
        {error && mode === null && (
          <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive" data-testid="sales-action-error">
            {error}
          </div>
        )}
        <DetailPageLayout>
          <DetailSection title="Enquiry">
            <DetailField label="Status">
              <LeadStatusBadge status={lead.status} />
            </DetailField>
            <DetailField label="Received">{formatDateTime(lead.received_at)}</DetailField>
            {lead.enquirer_email && <DetailField label="Email">{lead.enquirer_email}</DetailField>}
            {lead.enquirer_phone && <DetailField label="Phone">{lead.enquirer_phone}</DetailField>}
            {lead.source && <DetailField label="Source">{lead.source}</DetailField>}
            {lead.description && <DetailField label="What they want">{lead.description}</DetailField>}
            <DetailField label="Assigned to">
              {lead.assigned_name ?? 'Unassigned'}
              {mayAssign && (
                <Button variant="link" size="sm" className="ml-2 h-auto p-0" onClick={() => setMode('assign')} data-testid="lead-assign">
                  Change
                </Button>
              )}
            </DetailField>
            {lead.disqualified_at && (
              <DetailField label="Disqualified">
                {formatDateTime(lead.disqualified_at)} — {lead.disqualification_reason}
              </DetailField>
            )}
          </DetailSection>
          <DetailSection title="Trading partner" description="Who this enquiry is about. Choosing one never creates a customer account.">
            <div className="space-y-2 sm:col-span-2">
              <p className="text-sm" data-testid="lead-party">
                {lead.party ? `${lead.party.name} (${lead.party.code})` : 'Not decided yet.'}
              </p>
              {mayLead && !decided && (
                <div className="flex flex-wrap items-center gap-2">
                  <PartyCombobox label="Trading partner" placeholder="Search trading partners" className="min-w-0 flex-1" value={partyId} onValueChange={setPartyId} />
                  <Button size="sm" variant="outline" disabled={!linkedElsewhere || link.isPending} data-testid="lead-link-party" onClick={() => void run(() => link.mutateAsync({ lead: id, data: { party_id: Number(partyId) } }), () => setPartyId(''))}>
                    Link
                  </Button>
                  <Button size="sm" variant="outline" onClick={() => setRegistering(true)} data-testid="register-party">
                    Register new…
                  </Button>
                </div>
              )}
            </div>
          </DetailSection>
          <DetailSection title="Opportunities from this enquiry" fullWidth>
            <ul className="space-y-2 text-sm sm:col-span-2" data-testid="lead-opportunities">
              {(lead.opportunities ?? []).length === 0 && <li className="text-muted-foreground">None yet.</li>}
              {(lead.opportunities ?? []).map((opportunity) => (
                <li key={opportunity.id} className="flex flex-wrap items-center gap-2">
                  <Link className="text-primary hover:underline" to={`/opportunities/${opportunity.id}`}>
                    {opportunity.number ?? `#${opportunity.id}`} — {opportunity.title}
                  </Link>
                  <OutcomeBadge outcome={opportunity.outcome} />
                </li>
              ))}
            </ul>
          </DetailSection>
          <DetailSection title="Notes" fullWidth>
            <div className="space-y-3 sm:col-span-2">
              {mayLead && (
                <div className="space-y-2">
                  <Label htmlFor="lead-note">Add a note</Label>
                  <Textarea id="lead-note" rows={2} value={note} onChange={(event) => setNote(event.target.value)} />
                  <Button size="sm" disabled={note.trim() === '' || addNote.isPending} data-testid="note-add" onClick={() => void run(() => addNote.mutateAsync({ lead: id, data: { body: note.trim() } }), () => setNote(''))}>
                    Add note
                  </Button>
                </div>
              )}
              <NotesList rows={lead.notes ?? []} />
            </div>
          </DetailSection>
          <DetailSection title="History" fullWidth>
            <div className="sm:col-span-2">
              <HistoryList rows={[...(lead.history ?? [])].reverse()} />
            </div>
          </DetailSection>
        </DetailPageLayout>

        <Dialog open={mode === 'disqualify' || mode === 'revive'} onOpenChange={(next) => !next && closeDialog()}>
          <DialogContent className="max-h-[90vh] max-w-md overflow-y-auto" data-testid="lead-reason-dialog">
            <DialogHeader>
              <DialogTitle>{mode === 'revive' ? 'Revive this enquiry' : 'Disqualify this enquiry'}</DialogTitle>
              <DialogDescription>{mode === 'revive' ? 'It returns to new; what was decided before stays in the history.' : 'The enquiry stays on record with your reason, and you can revive it later. Deals already created from it stay open and are not changed.'}</DialogDescription>
            </DialogHeader>
            <div className="space-y-1">
              <Label htmlFor="lead-reason">Why?</Label>
              <Textarea id="lead-reason" rows={2} value={reason} onChange={(event) => setReason(event.target.value)} />
            </div>
            {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
            <DialogFooter>
              <Button variant="outline" onClick={closeDialog}>
                Go back
              </Button>
              <Button
                disabled={reason.trim() === ''}
                data-testid="lead-reason-confirm"
                onClick={() => void run(() => (mode === 'revive' ? revive.mutateAsync({ lead: id, data: { reason: reason.trim() } }) : disqualify.mutateAsync({ lead: id, data: { reason: reason.trim(), request_key: disqualifyIntent(reason.trim(), lead.decision_event_id).key, expected_decision_event_id: lead.decision_event_id } })), closeDialog)}
              >
                {mode === 'revive' ? 'Revive' : 'Disqualify'}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        <Dialog open={mode === 'assign'} onOpenChange={(next) => !next && closeDialog()}>
          <DialogContent className="max-h-[90vh] max-w-md overflow-y-auto" data-testid="lead-assign-dialog">
            <DialogHeader>
              <DialogTitle>Assign this enquiry</DialogTitle>
              <DialogDescription>Only active members can be chosen. Assigning gives them responsibility, not extra access.</DialogDescription>
            </DialogHeader>
            <OwnerCombobox label="Assign to" placeholder="Choose a person" className="w-full" value={ownerId} onValueChange={setOwnerId} />
            {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
            <DialogFooter>
              <Button variant="outline" onClick={closeDialog}>
                Go back
              </Button>
              <Button disabled={!ownerId} data-testid="lead-assign-confirm" onClick={() => void run(() => assign.mutateAsync({ lead: id, data: { assigned_to: Number(ownerId) } }), closeDialog)}>
                Assign
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        <Dialog open={mode === 'convert'} onOpenChange={(next) => !next && closeDialog()}>
          <DialogContent className="max-h-[90vh] max-w-md overflow-y-auto" data-testid="lead-convert-dialog">
            <DialogHeader>
              <DialogTitle>Create an opportunity</DialogTitle>
              <DialogDescription>One enquiry can produce several genuine deals. Retrying this one will not create it twice.</DialogDescription>
            </DialogHeader>
            {!lead.party_id ? (
              <p className="text-sm text-muted-foreground" data-testid="convert-needs-party">
                Link a trading partner to the enquiry first, so the deal is for someone.
              </p>
            ) : (
              <div className="space-y-3">
                <div className="space-y-1">
                  <Label htmlFor="convert-title">Title</Label>
                  <Input id="convert-title" value={title} onChange={(event) => setTitle(event.target.value)} />
                </div>
                {mayAssign && (
                  <div className="space-y-1">
                    <Label>Responsible (you, unless you choose someone)</Label>
                    <OwnerCombobox label="Responsible" placeholder="Choose a person" className="w-full" value={ownerId} onValueChange={setOwnerId} />
                  </div>
                )}
              </div>
            )}
            {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
            <DialogFooter>
              <Button variant="outline" onClick={closeDialog}>
                Go back
              </Button>
              <Button
                disabled={!lead.party_id || title.trim() === '' || convert.isPending}
                data-testid="lead-convert-confirm"
                onClick={() =>
                  void run(
                    async () => {
                      // The conversion answers 201 (created) or 200 (this key was already converted) with the opportunity; the generated type only describes the refusals.
                      const result = (await convert.mutateAsync({ lead: id, data: { conversion_key: conversionKey.current, title: title.trim(), ...(mayAssign && ownerId ? { owner_id: Number(ownerId) } : {}) } })) as unknown as { status: number; data: { data: { id: number } } };
                      conversionKey.current = newKey();
                      if (result.status === 200 || result.status === 201) navigate(`/opportunities/${result.data.data.id}`);
                    },
                    closeDialog,
                  )
                }
              >
                Create opportunity
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
        <RegisterPartyDialog open={registering} onOpenChange={setRegistering} onChosen={(party) => setPartyId(String(party.id))} />
      </div>
    </ScreenTemplate>
  );
}
