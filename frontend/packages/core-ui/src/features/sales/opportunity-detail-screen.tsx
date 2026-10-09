import { useState } from 'react';
import { AlertTriangle } from 'lucide-react';
import { Link, useNavigate, useParams } from 'react-router';
import {
  useCurrenciesIndex,
  useOpportunitiesClose,
  useOpportunitiesNotesStore,
  useOpportunitiesReassign,
  useOpportunitiesReopen,
  useOpportunitiesShow,
  useOpportunitiesStage,
  useOpportunitiesUpdate,
  type OpportunityResource,
} from '@erp/core-api';

import { DetailField } from '../../components/detail/detail-field';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { OwnerCombobox } from '../../components/lookups/entity-comboboxes';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../../components/ui/dialog';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Skeleton } from '../../components/ui/skeleton';
import { Textarea } from '../../components/ui/textarea';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage, isApiError, lookupState } from '../../lib/api-errors';
import { formatDate, formatDateTime } from '../../lib/format-date';
import { trimQty } from '../package-stock/quantity';
import { HistoryList, NotesList, OutcomeBadge, SALES, useSalesStages } from './sales-common';

type Mode = 'close' | 'reopen' | 'reassign' | 'edit' | null;

/** One opportunity: who it is for, who is responsible, where it stands, what happens next — with its notes and the history of every decision. */
export function OpportunityDetailScreen() {
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const mayManage = usePermission(SALES.opportunity);
  const mayAssign = usePermission(SALES.assign);
  const mayClose = usePermission(SALES.close);
  const query = useOpportunitiesShow(id, { include: 'party,stage,lead,notes,history' });
  const opportunity = query.data?.status === 200 ? query.data.data.data : undefined;
  const { active: stages } = useSalesStages();
  const currencies = useCurrenciesIndex({ per_page: 100, sort: 'code' });
  const currencyRows = currencies.data?.status === 200 ? currencies.data.data.data : [];
  const [mode, setMode] = useState<Mode>(null);
  const [error, setError] = useState<string | null>(null);
  const [note, setNote] = useState('');
  const stage = useOpportunitiesStage();
  const addNote = useOpportunitiesNotesStore();

  async function run(action: () => Promise<unknown>, done?: () => void) {
    setError(null);
    try {
      await action();
      await query.refetch();
      done?.();
    } catch (failure) {
      setError(getApiErrorMessage(failure));
    }
  }

  if (query.isLoading) return <Skeleton className="m-6 h-64" />;
  if (query.isError || !opportunity) {
    const denied = isApiError(query.error) && query.error.status === 403;

    return (
      <ScreenTemplate kind="detail" className="erp-screen">
        <div className="space-y-3" data-testid={denied ? 'sales-denied' : 'sales-error'}>
          <p className={denied ? 'text-sm text-muted-foreground' : 'text-sm text-destructive'}>{denied ? "You don't have permission to view this opportunity." : "Couldn't load this opportunity."}</p>
          <Button variant="outline" size="sm" onClick={() => navigate('/opportunities')}>
            Back to opportunities
          </Button>
        </div>
      </ScreenTemplate>
    );
  }

  const open = opportunity.outcome === 'OPEN';
  const currency = currencyRows.find((row) => row.id === opportunity.expected_currency_id)?.code ?? '';

  return (
    <ScreenTemplate kind="detail" className="erp-screen pb-24">
      <div className="space-y-4" data-testid="opportunity-detail">
        <PageHeader
          title={opportunity.title}
          description={opportunity.number ?? undefined}
          breadcrumbs={[{ label: 'Opportunities', to: '/opportunities' }, { label: opportunity.number ?? `#${opportunity.id}` }]}
          actions={
            <>
              {open && mayManage && (
                <Button variant="outline" onClick={() => setMode('edit')} data-testid="opportunity-edit">
                  Edit
                </Button>
              )}
              {open && mayAssign && (
                <Button variant="outline" onClick={() => setMode('reassign')} data-testid="opportunity-reassign">
                  Change owner
                </Button>
              )}
              {open && mayClose && (
                <Button variant="outline" onClick={() => setMode('close')} data-testid="opportunity-close">
                  Close…
                </Button>
              )}
              {!open && mayClose && opportunity.outcome !== 'WON' && (
                <Button onClick={() => setMode('reopen')} data-testid="opportunity-reopen">
                  Reopen
                </Button>
              )}
            </>
          }
        />
        {error && (
          <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive" data-testid="sales-action-error">
            {error}
          </div>
        )}
        {opportunity.owner_needs_reassignment && (
          <div role="status" className="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm" data-testid="owner-banner">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-destructive" aria-hidden="true" />
            <p>
              {opportunity.owner_name} can no longer be responsible for this deal.
              {mayAssign ? ' Choose a new owner.' : ' Ask someone who assigns deals to choose a new owner.'}
            </p>
          </div>
        )}
        <DetailPageLayout>
          <DetailSection title="Deal">
            <DetailField label="Outcome">
              <OutcomeBadge outcome={opportunity.outcome} />
            </DetailField>
            <DetailField label="For">{opportunity.party?.name}</DetailField>
            <DetailField label="Owner">{opportunity.owner_name}</DetailField>
            <DetailField label="Stage">
              {open && mayManage && stages.length > 0 ? (
                <Select value={String(opportunity.stage_id)} onValueChange={(value) => void run(() => stage.mutateAsync({ opportunity: id, data: { stage_id: Number(value) } }))}>
                  <SelectTrigger className="h-9 w-48" aria-label="Stage" data-testid="stage-select">
                    <SelectValue>{opportunity.stage?.name}</SelectValue>
                  </SelectTrigger>
                  <SelectContent>
                    {stages.map((option) => (
                      <SelectItem key={option.id} value={String(option.id)}>
                        {option.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              ) : (
                opportunity.stage?.name
              )}
            </DetailField>
            {opportunity.expected_amount && (
              <DetailField label="Expected value">
                <span data-testid="expected-value">{`${trimQty(opportunity.expected_amount)} ${currency}`.trim()}</span>
              </DetailField>
            )}
            {opportunity.expected_close_on && <DetailField label="Expected close">{formatDate(opportunity.expected_close_on)}</DetailField>}
            {opportunity.next_action && (
              <DetailField label="Next action">
                {opportunity.next_action}
                {opportunity.next_action_on ? ` · ${formatDate(opportunity.next_action_on)}` : ''}
              </DetailField>
            )}
            {opportunity.source && <DetailField label="Source">{opportunity.source}</DetailField>}
            {opportunity.description && <DetailField label="Description">{opportunity.description}</DetailField>}
            {opportunity.lead && (
              <DetailField label="From enquiry">
                <Link className="text-primary hover:underline" to={`/leads/${opportunity.lead.id}`}>
                  {opportunity.lead.number ?? `#${opportunity.lead.id}`}
                </Link>
              </DetailField>
            )}
            {opportunity.closed_at && (
              <DetailField label="Closed">
                <span data-testid="closure">
                  {formatDateTime(opportunity.closed_at)} — {opportunity.closure_reason}
                </span>
              </DetailField>
            )}
          </DetailSection>
          <DetailSection title="Notes" fullWidth>
            <div className="space-y-3 sm:col-span-2">
              {mayManage && (
                <div className="space-y-2">
                  <Label htmlFor="new-note">Add a note</Label>
                  <Textarea id="new-note" rows={2} value={note} onChange={(event) => setNote(event.target.value)} />
                  <Button size="sm" disabled={note.trim() === '' || addNote.isPending} data-testid="note-add" onClick={() => void run(() => addNote.mutateAsync({ opportunity: id, data: { body: note.trim() } }), () => setNote(''))}>
                    Add note
                  </Button>
                </div>
              )}
              <NotesList rows={opportunity.notes ?? []} />
            </div>
          </DetailSection>
          <DetailSection title="History" fullWidth>
            <div className="sm:col-span-2">
              <HistoryList rows={[...(opportunity.history ?? [])].reverse()} />
            </div>
          </DetailSection>
        </DetailPageLayout>
        <ActionDialogs
          key={opportunity.updated_at}
          mode={mode}
          opportunity={opportunity}
          currencies={currencyRows}
          onClose={() => setMode(null)}
          onDone={async () => {
            setMode(null);
            await query.refetch();
          }}
        />
      </div>
    </ScreenTemplate>
  );
}

function ActionDialogs({ mode, opportunity, currencies, onClose, onDone }: { mode: Mode; opportunity: OpportunityResource; currencies: Array<{ id: number; code: string }>; onClose: () => void; onDone: () => Promise<void> }) {
  const close = useOpportunitiesClose();
  const reopen = useOpportunitiesReopen();
  const reassign = useOpportunitiesReassign();
  const update = useOpportunitiesUpdate();
  const [outcome, setOutcome] = useState<'LOST' | 'ABANDONED'>('LOST');
  const [reason, setReason] = useState('');
  const [ownerId, setOwnerId] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [edit, setEdit] = useState({
    title: opportunity.title,
    description: opportunity.description ?? '',
    amount: opportunity.expected_amount ?? '',
    currency: opportunity.expected_currency_id ? String(opportunity.expected_currency_id) : '',
    closeOn: opportunity.expected_close_on ?? '',
    nextAction: opportunity.next_action ?? '',
    nextOn: opportunity.next_action_on ?? '',
  });

  async function submit(action: () => Promise<unknown>) {
    setError(null);
    try {
      await action();
      setReason('');
      await onDone();
    } catch (failure) {
      setError(getApiErrorMessage(failure));
    }
  }

  const needsReason = reason.trim() === '';
  const valueProblem = (String(edit.amount).trim() === '') !== (edit.currency === '');

  return (
    <>
      <Dialog open={mode === 'close'} onOpenChange={(next) => !next && onClose()}>
        <DialogContent className="max-h-[90vh] max-w-md overflow-y-auto" data-testid="close-dialog">
          <DialogHeader>
            <DialogTitle>Close this opportunity</DialogTitle>
            <DialogDescription>Closing ends the pursuit. It does not change any quotation or job, and you can reopen it later.</DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
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
              <Label htmlFor="close-reason">Why?</Label>
              <Textarea id="close-reason" rows={2} value={reason} onChange={(event) => setReason(event.target.value)} />
            </div>
            {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={onClose}>
              Go back
            </Button>
            <Button disabled={needsReason || close.isPending} data-testid="close-confirm" onClick={() => void submit(() => close.mutateAsync({ opportunity: opportunity.id, data: { outcome, reason: reason.trim() } }))}>
              Close opportunity
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={mode === 'reopen'} onOpenChange={(next) => !next && onClose()}>
        <DialogContent className="max-h-[90vh] max-w-md overflow-y-auto" data-testid="reopen-dialog">
          <DialogHeader>
            <DialogTitle>Reopen this opportunity</DialogTitle>
            <DialogDescription>It continues as the same pursuit; the earlier closure stays in the history.</DialogDescription>
          </DialogHeader>
          <div className="space-y-1">
            <Label htmlFor="reopen-reason">Why is it being revived?</Label>
            <Textarea id="reopen-reason" rows={2} value={reason} onChange={(event) => setReason(event.target.value)} />
          </div>
          {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
          <DialogFooter>
            <Button variant="outline" onClick={onClose}>
              Go back
            </Button>
            <Button disabled={needsReason || reopen.isPending} data-testid="reopen-confirm" onClick={() => void submit(() => reopen.mutateAsync({ opportunity: opportunity.id, data: { reason: reason.trim() } }))}>
              Reopen
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={mode === 'reassign'} onOpenChange={(next) => !next && onClose()}>
        <DialogContent className="max-h-[90vh] max-w-md overflow-y-auto" data-testid="reassign-dialog">
          <DialogHeader>
            <DialogTitle>Change who is responsible</DialogTitle>
            <DialogDescription>Responsibility is not access: the new owner sees only what their own permissions allow. Only active members can be chosen.</DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            <div className="space-y-1">
              <Label>New owner</Label>
              <OwnerCombobox label="New owner" placeholder="Choose a person" className="w-full" value={ownerId} onValueChange={setOwnerId} />
            </div>
            <div className="space-y-1">
              <Label htmlFor="reassign-reason">Reason (optional)</Label>
              <Input id="reassign-reason" value={reason} onChange={(event) => setReason(event.target.value)} />
            </div>
            {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={onClose}>
              Go back
            </Button>
            <Button disabled={!ownerId || reassign.isPending} data-testid="reassign-confirm" onClick={() => void submit(() => reassign.mutateAsync({ opportunity: opportunity.id, data: { owner_id: Number(ownerId), reason: reason.trim() || null } }))}>
              Change owner
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={mode === 'edit'} onOpenChange={(next) => !next && onClose()}>
        <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto" data-testid="edit-dialog">
          <DialogHeader>
            <DialogTitle>Edit the deal</DialogTitle>
            <DialogDescription>Who it is for, its stage and its owner change through their own actions.</DialogDescription>
          </DialogHeader>
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-1 sm:col-span-2">
              <Label htmlFor="edit-title">Title</Label>
              <Input id="edit-title" value={edit.title} onChange={(event) => setEdit({ ...edit, title: event.target.value })} />
            </div>
            <div className="space-y-1 sm:col-span-2">
              <Label htmlFor="edit-description">Description</Label>
              <Textarea id="edit-description" rows={2} value={edit.description} onChange={(event) => setEdit({ ...edit, description: event.target.value })} />
            </div>
            <div className="space-y-1">
              <Label htmlFor="edit-amount">Expected value</Label>
              <Input id="edit-amount" inputMode="decimal" placeholder="Leave empty if not known" value={String(edit.amount)} onChange={(event) => setEdit({ ...edit, amount: event.target.value })} />
            </div>
            <div className="space-y-1">
              <Label>Currency</Label>
              <Combobox aria-label="Currency" value={edit.currency} onValueChange={(value) => setEdit({ ...edit, currency: value })} options={currencies.map((currency) => ({ value: String(currency.id), label: currency.code }))} placeholder="Select a currency" searchPlaceholder="Search currencies…" emptyText="No currencies found." />
            </div>
            {valueProblem && <p className="text-xs text-destructive sm:col-span-2">Give both the amount and its currency, or neither.</p>}
            <div className="space-y-1">
              <Label htmlFor="edit-close">Expected close</Label>
              <Input id="edit-close" type="date" value={edit.closeOn} onChange={(event) => setEdit({ ...edit, closeOn: event.target.value })} />
            </div>
            <div className="space-y-1">
              <Label htmlFor="edit-next-on">Next action on</Label>
              <Input id="edit-next-on" type="date" value={edit.nextOn} onChange={(event) => setEdit({ ...edit, nextOn: event.target.value })} />
            </div>
            <div className="space-y-1 sm:col-span-2">
              <Label htmlFor="edit-next">Next action</Label>
              <Input id="edit-next" value={edit.nextAction} onChange={(event) => setEdit({ ...edit, nextAction: event.target.value })} />
            </div>
            {error && <p role="alert" className="text-sm text-destructive sm:col-span-2">{error}</p>}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button
              disabled={valueProblem || edit.title.trim() === '' || update.isPending}
              data-testid="edit-save"
              onClick={() =>
                void submit(() =>
                  update.mutateAsync({
                    opportunity: opportunity.id,
                    data: {
                      title: edit.title.trim(),
                      description: edit.description.trim() || null,
                      expected_amount: String(edit.amount).trim() || null,
                      expected_currency_id: edit.currency ? Number(edit.currency) : null,
                      expected_close_on: edit.closeOn || null,
                      next_action: edit.nextAction.trim() || null,
                      next_action_on: edit.nextOn || null,
                    },
                  }),
                )
              }
            >
              Save changes
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
