import { useState, type ReactNode } from 'react';
import { AlertTriangle } from 'lucide-react';
import { Link, useLocation, useNavigate, useParams } from 'react-router';
import {
  useCurrenciesIndex,
  useOpportunitiesClose,
  useOpportunitiesNotesStore,
  useOpportunitiesReassign,
  useOpportunitiesReopen,
  useOpportunitiesShow,
  useOpportunitiesStage,
  useOpportunitiesUpdate,
  useSalesOwnersIndex,
  type OpportunityResource,
} from '@erp/core-api';

import { DetailField } from '../../components/detail/detail-field';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { OwnerCombobox } from '../../components/lookups/entity-comboboxes';
import { PageHeader } from '../../components/page/page-header';
import { DetailPrintLayout, PrintTrigger, useQuickPrint, type DetailPrintSection } from '../../components/print';
import { RecordSheet } from '../../components/page/record-sheet';
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
import type { Presentation } from '../../lib/presentation';
import { trimQty } from '../package-stock/quantity';
import { AttributionCorrectionDialog, ResponsibilityRecord } from './opportunity-attribution';
import { contactLabel, usePartyContact } from './contact-picker';
import { HistoryList, NotesList, OUTCOME_LABEL, OutcomeBadge, SALES, describeEvent, useSalesStages } from './sales-common';

const isPast = (day: string | null | undefined) => Boolean(day) && (day as string) < new Date().toLocaleDateString('en-CA');
const Overdue = () => <span className="ml-1 text-xs text-destructive">overdue</span>;

type Mode = 'close' | 'reopen' | 'reassign' | 'quick' | 'attribution' | null;

/** One opportunity: who it is for, who is responsible, where it stands, what happens next — with its notes and the history of every decision. */
export function OpportunityDetailScreen({ presentation = 'sheet' }: { presentation?: Presentation }) {
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const { search } = useLocation();
  const backToList = () => navigate({ pathname: '/opportunities', search });
  const mayManage = usePermission(SALES.opportunity);
  const mayAssign = usePermission(SALES.assign);
  const mayClose = usePermission(SALES.close);
  const query = useOpportunitiesShow(id, { include: 'party,stage,lead,notes,history,attribution_corrections' });
  const opportunity = query.data?.status === 200 ? query.data.data.data : undefined;
  const { stages: allStages, active: stages } = useSalesStages();
  const { contact, readable: contactsReadable } = usePartyContact(opportunity?.party_id, opportunity?.party_contact_id);
  const owners = useSalesOwnersIndex(undefined, { query: { enabled: mayAssign } });
  const { contentRef: printRef, print } = useQuickPrint({ documentTitle: 'Opportunity' });
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

  /** The same content as a side sheet over the list (the default) or as a page of its own. */
  function present(content: ReactNode, header?: { title: string; description?: string; actions?: ReactNode }) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet title={header?.title ?? 'Opportunity'} description={header?.description} actions={header?.actions} size="xl" className="erp-record" onClose={backToList}>
          <div className="space-y-4 pb-6">{content}</div>
        </RecordSheet>
      );
    }

    return (
      <ScreenTemplate kind="detail" className="erp-screen pb-24">
        <div className="space-y-4">
          {header && (
            <PageHeader
              title={header.title}
              description={header.description}
              breadcrumbs={[{ label: 'Opportunities', to: `/opportunities${search}` }, { label: header.description ?? header.title }]}
              actions={header.actions}
            />
          )}
          {content}
        </div>
      </ScreenTemplate>
    );
  }

  if (query.isLoading) {
    return present(
      <div className="space-y-4 p-4">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-48 w-full" />
      </div>,
    );
  }
  if (query.isError || !opportunity) {
    const denied = isApiError(query.error) && query.error.status === 403;

    return present(
      <div className="space-y-3 p-4" data-testid={denied ? 'sales-denied' : 'sales-error'}>
        <p className={denied ? 'text-sm text-muted-foreground' : 'text-sm text-destructive'}>{denied ? "You don't have permission to view this opportunity." : "Couldn't load this opportunity."}</p>
        <Button variant="outline" size="sm" onClick={backToList}>
          Back to opportunities
        </Button>
      </div>,
    );
  }

  const open = opportunity.outcome === 'OPEN';
  // Features follow the company's size: a single stage needs no stage control, and a single possible owner no owner change — until a flagged owner makes it necessary.
  const usesStages = allStages.length > 1;
  const ownerChoices = owners.data?.status === 200 ? owners.data.data.data.length : undefined;
  const canChangeOwner = ownerChoices === undefined || ownerChoices > 1 || opportunity.owner_needs_reassignment;
  const overdueClose = open && isPast(opportunity.expected_close_on);
  const overdueNext = open && isPast(opportunity.next_action_on);
  const currency = currencyRows.find((row) => row.id === opportunity.expected_currency_id)?.code ?? '';

  const actions = (
    <>
      {open && mayManage && (
        <>
          <Button variant="outline" onClick={() => navigate({ pathname: `/opportunities/${id}/edit`, search })} data-testid="opportunity-edit">
            Edit
          </Button>
          <Button variant="outline" onClick={() => setMode('quick')} data-testid="opportunity-quick-edit">
            Quick update
          </Button>
        </>
      )}
      {open && mayAssign && canChangeOwner && (
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
      <PrintTrigger onPrint={() => print()} data-testid="opportunity-print" />
    </>
  );

  const valueText = opportunity.expected_amount ? `${trimQty(opportunity.expected_amount)} ${currency}`.trim() : 'Not set';
  const printSections: DetailPrintSection[] = [
    {
      title: 'Deal',
      fields: [
        { label: 'Outcome', value: OUTCOME_LABEL[opportunity.outcome] ?? opportunity.outcome },
        { label: 'For', value: opportunity.party ? `${opportunity.party.name} (${opportunity.party.code})` : '—' },
        { label: 'Contact person', value: contact ? [contactLabel(contact), contact.phone, contact.email].filter(Boolean).join(' · ') : opportunity.party_contact_id ? 'Set' : 'Not set' },
        { label: 'Owner', value: opportunity.owner_name ?? '—' },
        ...(usesStages ? [{ label: 'Stage', value: opportunity.stage?.name ?? '—' }] : []),
        { label: 'Expected value', value: valueText },
        { label: 'Expected close', value: formatDate(opportunity.expected_close_on) ?? 'Not set' },
        { label: 'Next action', value: opportunity.next_action ? `${opportunity.next_action}${opportunity.next_action_on ? ` · ${formatDate(opportunity.next_action_on)}` : ''}` : 'Not set' },
        { label: 'Source', value: opportunity.source ?? '—' },
        { label: 'From enquiry', value: opportunity.lead?.number ?? '—' },
        { label: 'Description', value: opportunity.description ?? '—' },
        ...(opportunity.closed_at ? [{ label: 'Closed', value: `${formatDateTime(opportunity.closed_at)} — ${opportunity.closure_reason ?? ''}` }] : []),
        { label: 'Created', value: formatDateTime(opportunity.created_at) ?? '—' },
        { label: 'Last updated', value: formatDateTime(opportunity.updated_at) ?? '—' },
      ],
    },
    {
      title: 'Notes',
      fullWidth: true,
      fields:
        (opportunity.notes ?? []).length > 0
          ? (opportunity.notes ?? []).map((item) => ({ label: `${item.author_name ?? 'Someone'} · ${formatDateTime(item.created_at)}`, value: item.body }))
          : [{ label: 'Notes', value: 'No notes yet.' }],
    },
    {
      title: 'History',
      fullWidth: true,
      fields: [...(opportunity.history ?? [])]
        .reverse()
        .map((event) => ({ label: `${formatDateTime(event.occurred_at)} · ${event.actor_name ?? 'Someone'}`, value: `${describeEvent(event)}${event.reason ? ` — “${event.reason}”` : ''}` })),
    },
  ];

  return present(
    <div className="space-y-4" data-testid="opportunity-detail">
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
        <DetailSection title="Deal" fullWidth>
          <DetailField label="Outcome">
            <OutcomeBadge outcome={opportunity.outcome} />
          </DetailField>
          <DetailField label="For">
            {opportunity.party?.name}
            {opportunity.party?.code && <span className="text-muted-foreground"> ({opportunity.party.code})</span>}
          </DetailField>
          <DetailField label="Contact person">
            {contact ? (
              <span className="inline-flex flex-col" data-testid="deal-contact">
                <span>{contactLabel(contact)}</span>
                {(contact.phone || contact.email) && <span className="text-xs text-muted-foreground">{[contact.phone, contact.email].filter(Boolean).join(' · ')}</span>}
              </span>
            ) : opportunity.party_contact_id ? (
              <span className="text-muted-foreground">{contactsReadable ? 'Not found' : 'One is set (you cannot see contacts)'}</span>
            ) : (
              <span className="text-muted-foreground">Not set</span>
            )}
          </DetailField>
          <DetailField label="Owner">{opportunity.owner_name}</DetailField>
          {usesStages && (
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
          )}
          <DetailField label="Expected value">
            {opportunity.expected_amount ? <span data-testid="expected-value">{valueText}</span> : <span className="text-muted-foreground">Not set</span>}
          </DetailField>
          <DetailField label="Expected close">
            {opportunity.expected_close_on ? formatDate(opportunity.expected_close_on) : <span className="text-muted-foreground">Not set</span>}
            {overdueClose && <Overdue />}
          </DetailField>
          <DetailField label="Next action">
            {opportunity.next_action ? (
              <>
                {opportunity.next_action}
                {opportunity.next_action_on ? ` · ${formatDate(opportunity.next_action_on)}` : ''}
                {overdueNext && <Overdue />}
              </>
            ) : (
              <span className="text-muted-foreground">Not set</span>
            )}
          </DetailField>
          {opportunity.source && <DetailField label="Source">{opportunity.source}</DetailField>}
          {opportunity.description && (
            <DetailField label="Description" fullWidth>
              <span className="whitespace-pre-wrap break-words">{opportunity.description}</span>
            </DetailField>
          )}
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
                <Button
                  size="sm"
                  disabled={note.trim() === '' || addNote.isPending}
                  data-testid="note-add"
                  onClick={() =>
                    void run(
                      () => addNote.mutateAsync({ opportunity: id, data: { body: note.trim() } }),
                      () => setNote(''),
                    )
                  }
                >
                  Add note
                </Button>
              </div>
            )}
            <NotesList rows={opportunity.notes ?? []} />
          </div>
        </DetailSection>
        {opportunity.outcome !== 'OPEN' && <ResponsibilityRecord opportunity={opportunity} onCorrect={() => setMode('attribution')} />}
        <DetailSection title="History" fullWidth>
          <div className="sm:col-span-2">
            <HistoryList rows={[...(opportunity.history ?? [])].reverse()} />
          </div>
        </DetailSection>
        <DetailSection title="Record">
          <DetailField label="Created">{formatDateTime(opportunity.created_at)}</DetailField>
          <DetailField label="Last updated">{formatDateTime(opportunity.updated_at)}</DetailField>
        </DetailSection>
      </DetailPageLayout>
      <div className="hidden">
        <div ref={printRef}>
          <DetailPrintLayout title="OPPORTUNITY" subtitle={`${opportunity.number ?? `#${opportunity.id}`} — ${opportunity.title}`} printedAt={new Date()} sections={printSections} />
        </div>
      </div>
      <AttributionCorrectionDialog
        opportunity={opportunity}
        open={mode === 'attribution'}
        onClose={() => setMode(null)}
        onSaved={async () => {
          await query.refetch();
        }}
      />
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
    </div>,
    { title: opportunity.title, description: opportunity.number ?? undefined, actions },
  );
}

function ActionDialogs({
  mode,
  opportunity,
  currencies,
  onClose,
  onDone,
}: {
  mode: Mode;
  opportunity: OpportunityResource;
  currencies: Array<{ id: number; code: string }>;
  onClose: () => void;
  onDone: () => Promise<void>;
}) {
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
    source: opportunity.source ?? '',
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
              <Label htmlFor="close-reason">Why?</Label>
              <Textarea id="close-reason" rows={2} value={reason} onChange={(event) => setReason(event.target.value)} />
            </div>
            {error && (
              <p role="alert" className="text-sm text-destructive">
                {error}
              </p>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={onClose}>
              Go back
            </Button>
            <Button
              disabled={needsReason || close.isPending}
              data-testid="close-confirm"
              onClick={() => void submit(() => close.mutateAsync({ opportunity: opportunity.id, data: { outcome, reason: reason.trim() } }))}
            >
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
          <div className="space-y-1.5">
            <Label htmlFor="reopen-reason">Why is it being revived?</Label>
            <Textarea id="reopen-reason" rows={2} value={reason} onChange={(event) => setReason(event.target.value)} />
          </div>
          {error && (
            <p role="alert" className="text-sm text-destructive">
              {error}
            </p>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={onClose}>
              Go back
            </Button>
            <Button
              disabled={needsReason || reopen.isPending}
              data-testid="reopen-confirm"
              onClick={() => void submit(() => reopen.mutateAsync({ opportunity: opportunity.id, data: { reason: reason.trim() } }))}
            >
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
            <div className="space-y-1.5">
              <Label>New owner</Label>
              <OwnerCombobox label="New owner" placeholder="Choose a person" className="w-full" value={ownerId} onValueChange={setOwnerId} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="reassign-reason">Reason (optional)</Label>
              <Input id="reassign-reason" value={reason} onChange={(event) => setReason(event.target.value)} />
            </div>
            {error && (
              <p role="alert" className="text-sm text-destructive">
                {error}
              </p>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={onClose}>
              Go back
            </Button>
            <Button
              disabled={!ownerId || reassign.isPending}
              data-testid="reassign-confirm"
              onClick={() => void submit(() => reassign.mutateAsync({ opportunity: opportunity.id, data: { owner_id: Number(ownerId), reason: reason.trim() || null } }))}
            >
              Change owner
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={mode === 'quick'} onOpenChange={(next) => !next && onClose()}>
        <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto" data-testid="edit-dialog">
          <DialogHeader>
            <DialogTitle>Quick update</DialogTitle>
            <DialogDescription>Just the follow-up: value, expected close and the next step. Use Edit for the title, source and description.</DialogDescription>
          </DialogHeader>
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="edit-amount">Expected value</Label>
              <Input id="edit-amount" inputMode="decimal" placeholder="Leave empty if not known" value={String(edit.amount)} onChange={(event) => setEdit({ ...edit, amount: event.target.value })} />
            </div>
            <div className="space-y-1.5">
              <Label>Currency</Label>
              <Combobox
                aria-label="Currency"
                value={edit.currency}
                onValueChange={(value) => setEdit({ ...edit, currency: value })}
                options={currencies.map((currency) => ({ value: String(currency.id), label: currency.code }))}
                placeholder="Select a currency"
                searchPlaceholder="Search currencies…"
                emptyText="No currencies found."
              />
            </div>
            {valueProblem && <p className="text-xs text-destructive sm:col-span-2">Give both the amount and its currency, or neither.</p>}
            <div className="space-y-1.5">
              <Label htmlFor="edit-close">Expected close</Label>
              <Input id="edit-close" type="date" value={edit.closeOn} onChange={(event) => setEdit({ ...edit, closeOn: event.target.value })} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="edit-next-on">Next action on</Label>
              <Input id="edit-next-on" type="date" value={edit.nextOn} onChange={(event) => setEdit({ ...edit, nextOn: event.target.value })} />
            </div>
            <div className="space-y-1.5 sm:col-span-2">
              <Label htmlFor="edit-next">Next action</Label>
              <Input id="edit-next" value={edit.nextAction} onChange={(event) => setEdit({ ...edit, nextAction: event.target.value })} />
            </div>
            {error && (
              <p role="alert" className="text-sm text-destructive sm:col-span-2">
                {error}
              </p>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button
              disabled={valueProblem || update.isPending}
              data-testid="edit-save"
              onClick={() =>
                void submit(() =>
                  update.mutateAsync({
                    opportunity: opportunity.id,
                    data: {
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
