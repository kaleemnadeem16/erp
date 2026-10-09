import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router';
import { useCurrenciesIndex, useOpportunitiesStore, useSalesStagesSeedSuggested } from '@erp/core-api';

import { OwnerCombobox, PartyCombobox } from '../../components/lookups/entity-comboboxes';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { MoreOptionsToggle } from '../../components/ui/more-options-toggle';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Textarea } from '../../components/ui/textarea';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage, getApiReasonCode, lookupState } from '../../lib/api-errors';
import { useAuthStore } from '../../stores/auth-store';
import { RegisterPartyDialog, SALES, useSalesStages } from './sales-common';

/** A direct opportunity for a trading partner — no enquiry is needed first (ADR 0098/0108). The owner defaults to you; stage, value and the rest stay out of the way until wanted. */
export function OpportunityFormScreen() {
  const navigate = useNavigate();
  const [search] = useSearchParams();
  const me = useAuthStore((state) => state.user);
  const mayAssign = usePermission(SALES.assign);
  const mayManage = usePermission(SALES.opportunity);
  const mayConfigure = usePermission(SALES.stage);
  const { stages, active, query: stagesQuery } = useSalesStages();
  const currencies = useCurrenciesIndex({ per_page: 100, sort: 'code' });
  const currencyRows = currencies.data?.status === 200 ? currencies.data.data.data : [];
  const store = useOpportunitiesStore();
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

  const valueProblem = (amount.trim() === '') !== (currencyId === '') ? 'Give both the amount and its currency, or neither.' : amount.trim() !== '' && !/^\d+(\.\d{1,4})?$/.test(amount.trim()) ? 'Enter the amount as a number.' : null;
  const titleMissing = title.trim() === '';
  const partyMissing = partyId === '';

  async function save() {
    setShowErrors(true);
    setError(null);
    if (titleMissing || partyMissing || valueProblem) {
      if (valueProblem) setMore(true);
      return;
    }
    try {
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
      if (result.status === 201) navigate(`/opportunities/${result.data.data.id}`);
    } catch (failure) {
      setError(getApiReasonCode(failure) === 'NO_STAGES_CONFIGURED' ? 'No pipeline stages are set up yet.' : getApiErrorMessage(failure));
    }
  }

  if (!mayManage) {
    return (
      <ScreenTemplate kind="form" className="erp-screen">
        <p className="text-sm text-muted-foreground" data-testid="sales-denied">
          You don't have permission to create opportunities.
        </p>
      </ScreenTemplate>
    );
  }

  const noStages = !stagesQuery.isLoading && stages.length === 0;

  return (
    <ScreenTemplate kind="form" className="erp-screen pb-24">
      <div className="space-y-4" data-testid="opportunity-form">
        <PageHeader title="New opportunity" description="A deal for a trading partner. You can start here without an enquiry." breadcrumbs={[{ label: 'Opportunities', to: '/opportunities' }, { label: 'New' }]} />
        {error && (
          <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
            {error}
          </div>
        )}
        {noStages && (
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
        <DetailSection title="The deal">
          <div className="space-y-1 sm:col-span-2">
            <Label htmlFor="opp-title">Title</Label>
            <Input id="opp-title" value={title} onChange={(event) => setTitle(event.target.value)} aria-invalid={showErrors && titleMissing} />
            {showErrors && titleMissing && <p className="text-xs text-destructive">Give the deal a short title.</p>}
          </div>
          <div className="space-y-1 sm:col-span-2">
            <Label>Trading partner</Label>
            <div className="flex flex-wrap items-center gap-2">
              <PartyCombobox label="Trading partner" placeholder="Search trading partners" className="min-w-0 flex-1" value={partyId} onValueChange={setPartyId} />
              <Button type="button" variant="outline" size="sm" onClick={() => setRegistering(true)} data-testid="register-party">
                Register new…
              </Button>
            </div>
            {showErrors && partyMissing && <p className="text-xs text-destructive">Choose who this deal is for.</p>}
          </div>
          {mayAssign && (
            <div className="space-y-1">
              <Label>Responsible</Label>
              <OwnerCombobox label="Responsible" placeholder="Choose who is responsible" className="w-full" value={ownerId} onValueChange={setOwnerId} />
            </div>
          )}
          <div className="sm:col-span-2">
            <MoreOptionsToggle open={more} onOpenChange={setMore} controls="opp-more" label="Stage, value, dates and notes" />
          </div>
          {more && (
            <div id="opp-more" className="grid gap-3 sm:col-span-2 sm:grid-cols-2">
              {active.length > 1 && (
                <div className="space-y-1">
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
              <div className="space-y-1">
                <Label htmlFor="opp-source">Source</Label>
                <Input id="opp-source" value={source} onChange={(event) => setSource(event.target.value)} />
              </div>
              <div className="space-y-1 sm:col-span-2">
                <Label htmlFor="opp-description">Description</Label>
                <Textarea id="opp-description" rows={2} value={description} onChange={(event) => setDescription(event.target.value)} />
              </div>
              <div className="space-y-1">
                <Label htmlFor="opp-amount">Expected value</Label>
                <Input id="opp-amount" inputMode="decimal" placeholder="Leave empty if not known" value={amount} onChange={(event) => setAmount(event.target.value)} aria-invalid={showErrors && !!valueProblem} />
              </div>
              <div className="space-y-1">
                <Label>Currency</Label>
                <Combobox {...lookupState(currencies, 'currencies')} aria-label="Currency" value={currencyId} onValueChange={setCurrencyId} options={currencyRows.map((currency) => ({ value: String(currency.id), label: currency.code }))} placeholder="Select a currency" searchPlaceholder="Search currencies…" emptyText="No currencies found." />
              </div>
              {showErrors && valueProblem && (
                <p className="text-xs text-destructive sm:col-span-2" data-testid="value-problem">
                  {valueProblem}
                </p>
              )}
              <div className="space-y-1">
                <Label htmlFor="opp-close">Expected close</Label>
                <Input id="opp-close" type="date" value={closeOn} onChange={(event) => setCloseOn(event.target.value)} />
              </div>
              <div className="space-y-1">
                <Label htmlFor="opp-next-on">Next action on</Label>
                <Input id="opp-next-on" type="date" value={nextOn} onChange={(event) => setNextOn(event.target.value)} />
              </div>
              <div className="space-y-1 sm:col-span-2">
                <Label htmlFor="opp-next">Next action</Label>
                <Input id="opp-next" placeholder="For example: call back about the quote" value={nextAction} onChange={(event) => setNextAction(event.target.value)} />
              </div>
            </div>
          )}
        </DetailSection>
        <div className="sticky bottom-0 -mx-4 flex flex-wrap items-center justify-end gap-2 border-t bg-background p-3 md:static md:mx-0 md:border-0 md:p-0">
          <Button variant="outline" onClick={() => navigate('/opportunities')}>
            Cancel
          </Button>
          <Button onClick={() => void save()} disabled={store.isPending || noStages} data-testid="opportunity-save">
            {store.isPending ? 'Saving…' : 'Create opportunity'}
          </Button>
        </div>
        <RegisterPartyDialog open={registering} onOpenChange={setRegistering} initialName={undefined} onChosen={(party) => setPartyId(String(party.id))} />
      </div>
    </ScreenTemplate>
  );
}
