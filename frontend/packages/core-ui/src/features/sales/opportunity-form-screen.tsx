import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useLocation, useNavigate, useParams, useSearchParams } from 'react-router';
import { useCurrenciesIndex, useOpportunitiesShow, useOpportunitiesStore, useOpportunitiesUpdate, useSalesStagesSeedSuggested } from '@erp/core-api';

import { OwnerCombobox, PartyCombobox } from '../../components/lookups/entity-comboboxes';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { PageHeader } from '../../components/page/page-header';
import { RecordSheet } from '../../components/page/record-sheet';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { Skeleton } from '../../components/ui/skeleton';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { MoreOptionsToggle } from '../../components/ui/more-options-toggle';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Textarea } from '../../components/ui/textarea';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage, getApiReasonCode, lookupState } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import { useAuthStore } from '../../stores/auth-store';
import { RegisterPartyDialog, SALES, useSalesStages } from './sales-common';

/**
 * A direct opportunity for a trading partner — no enquiry is needed first (ADR 0098/0108). The owner defaults to you; stage, value and the rest stay out of the way until wanted.
 * The same form edits an open deal (`/opportunities/:id/edit`): who it is for, its owner and its stage change through their own actions, so they are not asked again.
 */
export function OpportunityFormScreen({ presentation = 'sheet' }: { presentation?: Presentation }) {
  const navigate = useNavigate();
  const editingId = Number(useParams<{ id: string }>().id) || undefined;
  const editing = editingId !== undefined;
  const { search: listSearch } = useLocation();
  const [search] = useSearchParams();
  const backToList = () => navigate({ pathname: editing ? `/opportunities/${editingId}` : '/opportunities', search: listSearch });
  const me = useAuthStore((state) => state.user);
  const mayAssign = usePermission(SALES.assign);
  const mayManage = usePermission(SALES.opportunity);
  const mayConfigure = usePermission(SALES.stage);
  const { stages, active, query: stagesQuery } = useSalesStages();
  const currencies = useCurrenciesIndex({ per_page: 100, sort: 'code' });
  const currencyRows = currencies.data?.status === 200 ? currencies.data.data.data : [];
  const store = useOpportunitiesStore();
  const update = useOpportunitiesUpdate();
  const existing = useOpportunitiesShow(editingId ?? 0, { include: 'party' }, { query: { enabled: editing } });
  const deal = existing.data?.status === 200 ? existing.data.data.data : undefined;
  const seed = useSalesStagesSeedSuggested();

  const [title, setTitle] = useState('');
  const [partyId, setPartyId] = useState(search.get('party') ?? '');
  const [ownerId, setOwnerId] = useState(me ? String(me.id) : '');
  const [stageId, setStageId] = useState('');
  const [description, setDescription] = useState('');
  const [source, setSource] = useState('');
  const [amount, setAmount] = useState('');
  const [currencyId, setCurrencyId] = useState('');
  const [closeOn, setCloseOn] = useState('');
  const [nextAction, setNextAction] = useState('');
  const [nextOn, setNextOn] = useState('');
  const [more, setMore] = useState(false);
  const [registering, setRegistering] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showErrors, setShowErrors] = useState(false);

  // Fill the form once, from the deal being edited; later typing is never overwritten.
  const filled = useRef(false);
  useEffect(() => {
    if (!deal || filled.current) return;
    filled.current = true;
    setTitle(deal.title);
    setDescription(deal.description ?? '');
    setSource(deal.source ?? '');
    setAmount(deal.expected_amount ?? '');
    setCurrencyId(deal.expected_currency_id ? String(deal.expected_currency_id) : '');
    setCloseOn(deal.expected_close_on ?? '');
    setNextAction(deal.next_action ?? '');
    setNextOn(deal.next_action_on ?? '');
    // Anything already set is shown, never hidden behind the toggle.
    setMore(Boolean(deal.description || deal.source || deal.expected_amount || deal.expected_close_on || deal.next_action || deal.next_action_on));
  }, [deal]);

  const valueProblem =
    (amount.trim() === '') !== (currencyId === '')
      ? 'Give both the amount and its currency, or neither.'
      : amount.trim() !== '' && !/^\d+(\.\d{1,4})?$/.test(amount.trim())
        ? 'Enter the amount as a number.'
        : null;
  const titleMissing = title.trim() === '';
  const partyMissing = !editing && partyId === '';

  async function save() {
    setShowErrors(true);
    setError(null);
    if (titleMissing || partyMissing || valueProblem) {
      if (valueProblem) setMore(true);
      return;
    }
    try {
      if (editing) {
        const saved = await update.mutateAsync({
          opportunity: editingId,
          data: {
            title: title.trim(),
            description: description.trim() || null,
            source: source.trim() || null,
            expected_amount: amount.trim() || null,
            expected_currency_id: currencyId ? Number(currencyId) : null,
            expected_close_on: closeOn || null,
            next_action: nextAction.trim() || null,
            next_action_on: nextOn || null,
          },
        });
        if (saved.status === 200) backToList();

        return;
      }
      const result = await store.mutateAsync({
        data: {
          title: title.trim(),
          party_id: Number(partyId),
          ...(ownerId && mayAssign ? { owner_id: Number(ownerId) } : {}),
          ...(stageId ? { stage_id: Number(stageId) } : {}),
          description: description.trim() || null,
          source: source.trim() || null,
          expected_amount: amount.trim() || null,
          expected_currency_id: currencyId ? Number(currencyId) : null,
          expected_close_on: closeOn || null,
          next_action: nextAction.trim() || null,
          next_action_on: nextOn || null,
        },
      });
      if (result.status === 201) navigate({ pathname: `/opportunities/${result.data.data.id}`, search: listSearch });
    } catch (failure) {
      setError(getApiReasonCode(failure) === 'NO_STAGES_CONFIGURED' ? 'No pipeline stages are set up yet.' : getApiErrorMessage(failure));
    }
  }

  /** The same form as a side sheet over the list (the default; never closed by a stray click) or as a page of its own. */
  function present(content: ReactNode) {
    const title = editing ? 'Edit opportunity' : 'New opportunity';
    const description = editing ? (deal?.number ?? undefined) : 'A deal for a trading partner. You can start here without an enquiry.';
    if (presentation === 'sheet') {
      return (
        <RecordSheet title={title} description={description} size="lg" className="erp-record" dismissOnOutsideClick={false} onClose={backToList}>
          <div className="space-y-4 pb-6">{content}</div>
        </RecordSheet>
      );
    }

    return (
      <ScreenTemplate kind="form" className="erp-screen pb-24">
        <div className="space-y-4">
          <PageHeader title={title} description={description} breadcrumbs={[{ label: 'Opportunities', to: `/opportunities${listSearch}` }, { label: editing ? 'Edit' : 'New' }]} />
          {content}
        </div>
      </ScreenTemplate>
    );
  }

  if (!mayManage) {
    return present(
      <p className="text-sm text-muted-foreground" data-testid="sales-denied">
        You don't have permission to {editing ? 'edit' : 'create'} opportunities.
      </p>,
    );
  }
  if (editing && existing.isLoading) return present(<Skeleton className="h-48 w-full" />);
  if (editing && (!deal || deal.outcome !== 'OPEN')) {
    return present(
      <p className="text-sm text-muted-foreground" data-testid="sales-error">
        {deal ? 'A closed deal is not edited — reopen it first.' : "Couldn't load this opportunity."}
      </p>,
    );
  }

  const noStages = !stagesQuery.isLoading && stages.length === 0;

  return present(
    <div className="space-y-4" data-testid="opportunity-form">
      {error && (
        <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
          {error}
        </div>
      )}
      {noStages && !editing && (
        <div className="space-y-2 rounded-md border border-dashed p-3 text-sm" data-testid="no-stages">
          <p>No pipeline stages are set up yet, so a deal cannot be created.</p>
          {mayConfigure ? (
            <Button type="button" variant="outline" size="sm" onClick={() => void seed.mutateAsync().then(() => stagesQuery.refetch())}>
              Start with Qualification, Proposal and Negotiation
            </Button>
          ) : (
            <p className="text-muted-foreground">Ask someone who configures the pipeline to set up the stages.</p>
          )}
        </div>
      )}
      <DetailSection title={editing ? 'The deal' : 'The deal'}>
        <div className="space-y-1.5 sm:col-span-2">
          <Label htmlFor="opp-title">Title</Label>
          <Input id="opp-title" value={title} onChange={(event) => setTitle(event.target.value)} aria-invalid={showErrors && titleMissing} />
          {showErrors && titleMissing && <p className="text-xs text-destructive">Give the deal a short title.</p>}
        </div>
        {editing ? (
          <div className="space-y-1.5 sm:col-span-2">
            <Label>Trading partner</Label>
            <p className="text-sm">{deal?.party?.name}</p>
          </div>
        ) : (
          <div className="space-y-1.5 sm:col-span-2">
            <Label>Trading partner</Label>
            <div className="flex flex-wrap items-center gap-2">
              <PartyCombobox label="Trading partner" placeholder="Search trading partners" className="min-w-0 flex-1" value={partyId} onValueChange={setPartyId} />
              <Button type="button" variant="outline" size="sm" onClick={() => setRegistering(true)} data-testid="register-party">
                Register new…
              </Button>
            </div>
            {showErrors && partyMissing && <p className="text-xs text-destructive">Choose who this deal is for.</p>}
          </div>
        )}
        {mayAssign && !editing && (
          <div className="space-y-1.5">
            <Label>Responsible</Label>
            <OwnerCombobox label="Responsible" placeholder="Choose who is responsible" className="w-full" value={ownerId} onValueChange={setOwnerId} />
          </div>
        )}
        <div className="sm:col-span-2">
          <MoreOptionsToggle open={more} onOpenChange={setMore} controls="opp-more" label="Stage, value, dates and notes" />
        </div>
        {more && (
          <div id="opp-more" className="grid gap-3 sm:col-span-2 sm:grid-cols-2">
            {active.length > 1 && !editing && (
              <div className="space-y-1.5">
                <Label>Stage</Label>
                <Select value={stageId || String(active[0]?.id)} onValueChange={setStageId}>
                  <SelectTrigger aria-label="Stage">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {active.map((stage) => (
                      <SelectItem key={stage.id} value={String(stage.id)}>
                        {stage.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            )}
            <div className="space-y-1.5">
              <Label htmlFor="opp-source">Source</Label>
              <Input id="opp-source" value={source} onChange={(event) => setSource(event.target.value)} />
            </div>
            <div className="space-y-1.5 sm:col-span-2">
              <Label htmlFor="opp-description">Description</Label>
              <Textarea id="opp-description" rows={2} value={description} onChange={(event) => setDescription(event.target.value)} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="opp-amount">Expected value</Label>
              <Input
                id="opp-amount"
                inputMode="decimal"
                placeholder="Leave empty if not known"
                value={amount}
                onChange={(event) => setAmount(event.target.value)}
                aria-invalid={showErrors && !!valueProblem}
              />
            </div>
            <div className="space-y-1.5">
              <Label>Currency</Label>
              <Combobox
                {...lookupState(currencies, 'currencies')}
                aria-label="Currency"
                value={currencyId}
                onValueChange={setCurrencyId}
                options={currencyRows.map((currency) => ({ value: String(currency.id), label: currency.code }))}
                placeholder="Select a currency"
                searchPlaceholder="Search currencies…"
                emptyText="No currencies found."
              />
            </div>
            {showErrors && valueProblem && (
              <p className="text-xs text-destructive sm:col-span-2" data-testid="value-problem">
                {valueProblem}
              </p>
            )}
            <div className="space-y-1.5">
              <Label htmlFor="opp-close">Expected close</Label>
              <Input id="opp-close" type="date" value={closeOn} onChange={(event) => setCloseOn(event.target.value)} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="opp-next-on">Next action on</Label>
              <Input id="opp-next-on" type="date" value={nextOn} onChange={(event) => setNextOn(event.target.value)} />
            </div>
            <div className="space-y-1.5 sm:col-span-2">
              <Label htmlFor="opp-next">Next action</Label>
              <Input id="opp-next" placeholder="For example: call back about the quote" value={nextAction} onChange={(event) => setNextAction(event.target.value)} />
            </div>
          </div>
        )}
      </DetailSection>
      <div className="sticky bottom-0 -mx-4 flex flex-wrap items-center justify-end gap-2 border-t bg-background p-3 md:static md:mx-0 md:border-0 md:p-0">
        <Button variant="outline" onClick={backToList}>
          Cancel
        </Button>
        <Button onClick={() => void save()} disabled={store.isPending || update.isPending || (noStages && !editing)} data-testid="opportunity-save">
          {store.isPending || update.isPending ? 'Saving…' : editing ? 'Save changes' : 'Create opportunity'}
        </Button>
      </div>
      <RegisterPartyDialog open={registering} onOpenChange={setRegistering} initialName={undefined} onChosen={(party) => setPartyId(String(party.id))} />
    </div>,
  );
}
