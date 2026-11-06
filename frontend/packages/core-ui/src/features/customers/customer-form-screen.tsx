import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import {
  useCurrenciesIndex,
  useCustomerAccountsShow,
  useCustomerAccountsStore,
  useCustomerAccountsUpdate,
  usePartiesShow,
  usePartiesStore,
  type CustomerAccountResource,
} from '@erp/core-api';
import { History, RotateCcw } from 'lucide-react';

import { DetailSection } from '../../components/detail/detail-page-layout';
import { AccountCombobox, PartyCombobox } from '../../components/lookups/entity-comboboxes';
import { PageHeader } from '../../components/page/page-header';
import { RecordSheet } from '../../components/page/record-sheet';
import { ScreenTemplate } from '../../components/page/screen-template';
import { Button } from '../../components/ui/button';
import { Collapse } from '../../components/ui/collapse';
import { Combobox } from '../../components/ui/combobox';
import { FieldHint } from '../../components/ui/field-hint';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { MoreOptionsToggle } from '../../components/ui/more-options-toggle';
import { SectionStack } from '../../components/ui/section-stack';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Skeleton } from '../../components/ui/skeleton';
import { getApiErrorMessage, isApiError, lookupState } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import { clearDraft, readDraft, writeDraft } from '../../lib/session-draft';
import { useAuthStore } from '../../stores/auth-store';
import { CustomFieldInputs, customFieldValues, type CustomFieldValues } from '../custom-fields/custom-field-inputs';
import { EMPTY_PARTY_IDENTITY, PartyCandidatePanel, PartyIdentityFields, partyCreatePayload, validatePartyIdentity, type PartyIdentityErrors, type PartyIdentityValues } from '../parties/party-identity';
import {
  customerAccountPayload,
  customerAuthority,
  customerOptionsSummary,
  EMPTY_CUSTOMER_FORM,
  suggestCustomerCode,
  validateCustomerForm,
  type CustomerAuthority,
  type CustomerFormErrors,
  type CustomerFormValues,
} from './customer-rules';

const DRAFT_NAME = 'customer';

/** The list's own query string, without the one-shot `party_id` preset the "Make a customer" link adds. */
function withoutPresetParty(search: string): string {
  const params = new URLSearchParams(search);
  params.delete('party_id');
  const rest = params.toString();

  return rest ? `?${rest}` : '';
}

interface UnfinishedCustomer {
  partyMode: 'existing' | 'new';
  partyId: string;
  identity: PartyIdentityValues;
  values: CustomerFormValues;
  fields: CustomFieldValues;
  showOptions: boolean;
}

const isPristine = (draft: UnfinishedCustomer) =>
  !draft.partyId &&
  draft.partyMode === 'existing' &&
  JSON.stringify(draft.identity) === JSON.stringify(EMPTY_PARTY_IDENTITY) &&
  JSON.stringify(draft.values) === JSON.stringify(EMPTY_CUSTOMER_FORM) &&
  Object.keys(draft.fields).length === 0;

export const readUnfinishedCustomer = (): UnfinishedCustomer | null => readDraft<UnfinishedCustomer>(DRAFT_NAME)?.value ?? null;
export const hasUnfinishedCustomer = () => readUnfinishedCustomer() !== null;

export interface CustomerFormScreenProps {
  presentation?: Presentation;
}

/** Create (`/customers/new`) or edit (`/customers/:id/edit`) a customer account; a side sheet over the list by default, a page on request. */
export function CustomerFormScreen({ presentation = 'sheet' }: CustomerFormScreenProps) {
  const { id } = useParams<{ id: string }>();
  const customerId = Number(id);
  const navigate = useNavigate();
  const { search: rawSearch } = useLocation();
  const search = withoutPresetParty(rawSearch);

  const query = useCustomerAccountsShow(customerId, { include: 'party' }, { query: { enabled: Number.isFinite(customerId) && customerId > 0 } });
  const customer = query.data?.status === 200 ? query.data.data.data : undefined;
  const backToList = () => navigate({ pathname: '/customers', search });

  if (id && query.isLoading) {
    return frame(presentation, 'Loading customer…', backToList, <Skeleton className="m-4 h-48 w-[calc(100%-2rem)]" />);
  }
  if (id && !customer) {
    return frame(
      presentation,
      'Customer not found',
      backToList,
      <div className="space-y-3 p-4" role="alert">
        <p className="text-sm text-destructive">
          {query.isError && isApiError(query.error) && query.error.status !== 404 ? "Couldn't load this customer." : "This customer isn't available."}
        </p>
        <div className="flex gap-2">
          {query.isError && isApiError(query.error) && query.error.status !== 404 && (
            <Button variant="outline" className="h-11 md:h-9" onClick={() => void query.refetch()}>
              Try again
            </Button>
          )}
          <Button variant="ghost" className="h-11 md:h-9" onClick={backToList}>
            Back to customers
          </Button>
        </div>
      </div>,
    );
  }

  return <CustomerEditor key={id ?? 'new'} customer={customer} presentation={presentation} />;
}

function frame(presentation: Presentation, title: string, onClose: () => void, body: React.ReactNode) {
  if (presentation === 'sheet') {
    return (
      <RecordSheet title={title} size="lg" className="erp-record" dismissOnOutsideClick={false} onClose={onClose}>
        {body}
      </RecordSheet>
    );
  }

  return (
    <ScreenTemplate kind="form" className="erp-screen">
      <PageHeader title={title} />
      {body}
    </ScreenTemplate>
  );
}

function CustomerEditor({ customer, presentation }: { customer?: CustomerAccountResource; presentation: Presentation }) {
  const navigate = useNavigate();
  const { search: rawSearch } = useLocation();
  const search = withoutPresetParty(rawSearch);
  // "Make a customer" on a trading partner opens this form for that partner (`?party_id=`); it wins over an older unfinished draft.
  const presetParty = new URLSearchParams(rawSearch).get('party_id');
  const queryClient = useQueryClient();
  const isCreate = !customer;
  const permissions = useAuthStore((state) => state.user?.permissions);
  const authority: CustomerAuthority = useMemo(() => customerAuthority((permission) => Array.isArray(permissions) && permissions.includes(permission)), [permissions]);

  const [unfinished] = useState(() => (isCreate ? readUnfinishedCustomer() : null));
  const [resumed, setResumed] = useState(unfinished !== null);
  const [confirmingDiscard, setConfirmingDiscard] = useState(false);

  const [partyMode, setPartyMode] = useState<'existing' | 'new'>(unfinished?.partyMode ?? 'existing');
  const [partyId, setPartyId] = useState(customer ? String(customer.party_id) : (presetParty ?? unfinished?.partyId ?? ''));
  const [identity, setIdentity] = useState<PartyIdentityValues>(unfinished?.identity ?? EMPTY_PARTY_IDENTITY);
  const [values, setValues] = useState<CustomerFormValues>(
    customer
      ? {
          code: customer.code,
          paymentTerms: String(customer.payment_terms_days ?? 0),
          creditLimit: customer.credit_limit ?? '',
          receivableAccountId: customer.receivable_account_id ? String(customer.receivable_account_id) : '',
          defaultCurrencyId: customer.default_currency_id ? String(customer.default_currency_id) : '',
          status: customer.status === 'ARCHIVED' ? 'ARCHIVED' : 'ACTIVE',
        }
      : (unfinished?.values ?? EMPTY_CUSTOMER_FORM),
  );
  const [fields, setFields] = useState<CustomFieldValues>(customer ? customFieldValues(customer.custom_fields) : (unfinished?.fields ?? {}));
  const [showOptions, setShowOptions] = useState(
    customer ? Boolean(customer.default_currency_id) || customer.status !== 'ACTIVE' || Object.keys(customFieldValues(customer.custom_fields)).length > 0 : (unfinished?.showOptions ?? false),
  );
  const [identityErrors, setIdentityErrors] = useState<PartyIdentityErrors>({});
  const [errors, setErrors] = useState<CustomerFormErrors>({});
  const [partyError, setPartyError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const formRef = useRef<HTMLFormElement>(null);

  const currenciesQuery = useCurrenciesIndex({ per_page: 250, sort: 'code' });
  const currencies = currenciesQuery.data?.status === 200 ? currenciesQuery.data.data.data : [];
  const partyQuery = usePartiesShow(Number(partyId), undefined, { query: { enabled: !!partyId && !customer } });
  const chosenParty = customer?.party ?? (partyQuery.data?.status === 200 ? partyQuery.data.data.data : undefined);

  const createParty = usePartiesStore();
  const createCustomer = useCustomerAccountsStore();
  const updateCustomer = useCustomerAccountsUpdate();
  const busy = createParty.isPending || createCustomer.isPending || updateCustomer.isPending;

  // Unfinished work survives closing the sheet for the life of the tab; a successful save or an explicit discard clears it.
  useEffect(() => {
    if (!isCreate) return;
    const draft: UnfinishedCustomer = { partyMode, partyId, identity, values, fields, showOptions };
    if (isPristine(draft)) clearDraft(DRAFT_NAME);
    else writeDraft(DRAFT_NAME, draft);
  }, [isCreate, partyMode, partyId, identity, values, fields, showOptions]);

  // Offer the chosen partner's own code as the customer code, but only until the person types one.
  useEffect(() => {
    if (isCreate && partyId && chosenParty?.code && !values.code) setValues((current) => ({ ...current, code: suggestCustomerCode(chosenParty.code) }));
  }, [isCreate, partyId, chosenParty?.code, values.code]);

  const setValue = <K extends keyof CustomerFormValues>(key: K, value: CustomerFormValues[K]) => setValues((current) => ({ ...current, [key]: value }));

  function discard() {
    setPartyMode('existing');
    setPartyId('');
    setIdentity(EMPTY_PARTY_IDENTITY);
    setValues(EMPTY_CUSTOMER_FORM);
    setFields({});
    setShowOptions(false);
    setResumed(false);
    setConfirmingDiscard(false);
    clearDraft(DRAFT_NAME);
  }

  const leave = (to: string) => navigate({ pathname: to, search });

  async function submit(event: FormEvent) {
    event.preventDefault();
    setFormError(null);
    setPartyError(null);

    const identityProblems = isCreate && partyMode === 'new' ? validatePartyIdentity(identity) : {};
    const problems = validateCustomerForm(values, authority, isCreate);
    if (isCreate && partyMode === 'existing' && !partyId) setPartyError('Choose a trading partner or register a new one.');
    setIdentityErrors(identityProblems);
    setErrors(problems);
    if ((isCreate && partyMode === 'existing' && !partyId) || Object.keys(identityProblems).length > 0 || Object.keys(problems).length > 0) {
      // Land on the first field that is wrong, so a keyboard user hears the problem rather than hunting for it.
      window.setTimeout(() => formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus(), 0);
      return;
    }

    try {
      let resolvedParty = Number(partyId);
      if (isCreate && partyMode === 'new') {
        const created = await createParty.mutateAsync({ data: partyCreatePayload(identity) });
        if (created.status !== 201) {
          setFormError("Couldn't register the trading partner.");
          return;
        }
        resolvedParty = created.data.data.id;
        // The partner now exists: keep it chosen so a refused account (a duplicate code, say) is retried against this partner, never a second one.
        setPartyId(String(resolvedParty));
        setPartyMode('existing');
      }

      const body = customerAccountPayload(values, authority, { customFields: fields });
      const result = customer
        ? await updateCustomer.mutateAsync({ customerAccount: customer.id, data: body })
        : await createCustomer.mutateAsync({
            data: { ...body, party_id: resolvedParty, payment_terms_days: Number(values.paymentTerms), receivable_account_id: Number(values.receivableAccountId) },
          });

      if (result.status === 200 || result.status === 201) {
        clearDraft(DRAFT_NAME);
        await queryClient.invalidateQueries();
        leave(`/customers/${result.data.data.id}`);
      }
    } catch (failure) {
      if (isApiError(failure) && failure.body?.errors) {
        const server = failure.body.errors;
        const first = (key: string) => server[key]?.[0];
        setErrors({
          code: first('code'),
          payment_terms_days: first('payment_terms_days'),
          credit_limit: first('credit_limit'),
          receivable_account_id: first('receivable_account_id'),
        });
        setIdentityErrors({ code: first('code'), name: first('name'), tax_id: first('tax_id'), registration_no: first('registration_no'), website: first('website') });
        setPartyError(first('party_id') ?? null);
        const known = ['code', 'payment_terms_days', 'credit_limit', 'receivable_account_id', 'name', 'tax_id', 'registration_no', 'website', 'party_id'];
        const rest = Object.entries(server).filter(([key]) => !known.includes(key)).map(([, messages]) => messages[0]);
        setFormError(rest.length > 0 ? rest.join(' ') : null);
      } else {
        setFormError(getApiErrorMessage(failure) ?? 'The customer could not be saved.');
      }
    }
  }

  const textField = (id: string, label: string, key: 'code' | 'paymentTerms' | 'creditLimit', errorKey: keyof CustomerFormErrors, extra: { required?: boolean; inputMode?: 'numeric' | 'decimal'; hint?: string; suffix?: string; disabled?: boolean } = {}) => (
    <div className="space-y-1.5">
      <div className="flex min-h-5 items-center gap-1.5">
        <Label htmlFor={id}>{label}</Label>
        {extra.hint && <FieldHint label={label}>{extra.hint}</FieldHint>}
      </div>
      <div className="flex items-center gap-2">
        <Input
          id={id}
          className="h-11 md:h-10"
          autoComplete="off"
          required={extra.required}
          inputMode={extra.inputMode}
          disabled={extra.disabled}
          value={values[key]}
          aria-invalid={errors[errorKey] ? true : undefined}
          aria-describedby={errors[errorKey] ? `${id}-error` : undefined}
          onChange={(event) => setValue(key, event.target.value)}
        />
        {extra.suffix && <span className="shrink-0 text-sm text-muted-foreground">{extra.suffix}</span>}
      </div>
      {errors[errorKey] && (
        <p id={`${id}-error`} role="alert" className="text-sm text-destructive">
          {errors[errorKey]}
        </p>
      )}
    </div>
  );

  const currencyLabel = currencies.find((currency) => String(currency.id) === values.defaultCurrencyId);
  const optionsSummary = customerOptionsSummary(values, currencyLabel ? `${currencyLabel.code}` : undefined, Object.keys(fields).length);

  const formBody = (
    <form ref={formRef} onSubmit={(event) => void submit(event)} className="space-y-6" noValidate aria-label={isCreate ? 'New customer' : 'Edit customer'}>
      {isCreate && resumed && (
        <div role="status" className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary/20 bg-primary-soft px-4 py-3 text-primary-soft-foreground">
          <div className="flex min-w-0 items-start gap-3">
            <History aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
            <div className="min-w-0 text-sm">
              <p className="font-medium">{confirmingDiscard ? "Discard everything you've entered?" : 'Continuing your unfinished customer'}</p>
              <p className="text-xs opacity-80">{confirmingDiscard ? 'What you entered will be cleared and cannot be brought back.' : 'What you entered last time is still here.'}</p>
            </div>
          </div>
          {confirmingDiscard ? (
            <div className="flex items-center gap-2">
              <Button type="button" size="sm" variant="destructive" className="h-11 md:h-8" onClick={discard}>
                <RotateCcw className="mr-1 h-3.5 w-3.5" />
                Yes, start over
              </Button>
              <Button type="button" size="sm" variant="outline" className="h-11 md:h-8" autoFocus onClick={() => setConfirmingDiscard(false)}>
                Keep it
              </Button>
            </div>
          ) : (
            <Button type="button" size="sm" variant="outline" className="h-11 md:h-8" onClick={() => setConfirmingDiscard(true)}>
              <RotateCcw className="mr-1 h-3.5 w-3.5" />
              Start over
            </Button>
          )}
        </div>
      )}

      <SectionStack>
        <DetailSection title="Trading partner" description={isCreate ? 'Who this customer is. One partner can be a customer and a supplier.' : 'The identity is shared; edit it from Trading partners.'}>
          {customer ? (
            <div className="space-y-1 sm:col-span-2">
              <p className="font-medium text-foreground">{customer.party?.name}</p>
              <p className="text-sm text-muted-foreground">Partner code: {customer.party?.code}</p>
            </div>
          ) : (
            <>
              <div className="flex flex-wrap gap-2 sm:col-span-2" role="group" aria-label="Trading partner source">
                <Button type="button" size="sm" className="h-11 md:h-9" variant={partyMode === 'existing' ? 'default' : 'outline'} aria-pressed={partyMode === 'existing'} onClick={() => setPartyMode('existing')}>
                  Choose existing
                </Button>
                {authority.registerParty && (
                  <Button type="button" size="sm" className="h-11 md:h-9" variant={partyMode === 'new' ? 'default' : 'outline'} aria-pressed={partyMode === 'new'} onClick={() => setPartyMode('new')}>
                    Register new
                  </Button>
                )}
              </div>
              {partyMode === 'existing' ? (
                <div className="space-y-1.5 sm:col-span-2">
                  <Label htmlFor="customer-party">Trading partner</Label>
                  <PartyCombobox id="customer-party" label="Trading partner" placeholder="Search trading partners…" className="h-11 w-full md:h-10" value={partyId} onValueChange={(value) => { setPartyId(value); setPartyError(null); }} />
                  {partyError && (
                    <p role="alert" className="text-sm text-destructive">
                      {partyError}
                    </p>
                  )}
                  {!authority.registerParty && <p className="text-xs text-muted-foreground">To add a partner that is not listed, ask someone who maintains trading partners.</p>}
                </div>
              ) : (
                <>
                  <PartyIdentityFields idPrefix="customer-new-party" values={identity} onChange={setIdentity} errors={identityErrors} codeHint="Leave blank to generate one from the name." />
                  <PartyCandidatePanel
                    name={identity.name}
                    onUse={(party) => {
                      setPartyId(String(party.id));
                      setPartyMode('existing');
                    }}
                  />
                </>
              )}
            </>
          )}
        </DetailSection>

        <DetailSection title="Customer account" description="Terms and mapping that apply when you sell to this partner.">
          {textField('customer-code', 'Customer code', 'code', 'code', { required: true, hint: 'Your own number for this customer. It can differ from the partner code and must be unique.' })}
          {authority.editTerms
            ? textField('customer-terms', 'Payment terms', 'paymentTerms', 'payment_terms_days', { required: true, inputMode: 'numeric', suffix: 'days' })
            : customer && (
                <div className="space-y-1.5">
                  <Label>Payment terms</Label>
                  <p className="text-sm">{customer.payment_terms_days} days</p>
                  <p className="text-xs text-muted-foreground">You can’t change payment terms.</p>
                </div>
              )}
          {authority.viewCredit && (authority.editCredit ? (
            textField('customer-credit', 'Credit limit', 'creditLimit', 'credit_limit', { inputMode: 'decimal', hint: 'The most this customer may owe. Leave empty for no limit.' })
          ) : (
            <div className="space-y-1.5">
              <Label>Credit limit</Label>
              <p className="text-sm tabular-nums">{customer?.credit_limit ?? 'No limit'}</p>
              <p className="text-xs text-muted-foreground">You can’t change credit limits.</p>
            </div>
          ))}
          {authority.viewMapping && (
            <div className="space-y-1.5 sm:col-span-2">
              <div className="flex min-h-5 items-center gap-1.5">
                <Label htmlFor="customer-account">Receivable control account</Label>
                <FieldHint label="Receivable control account">The balance-owed (asset) account an invoice to this customer debits. It is a reference only; Accounting owns what posts to it.</FieldHint>
              </div>
              <AccountCombobox
                id="customer-account"
                label="Receivable control account"
                placeholder="Search accounts by code or name…"
                className="h-11 w-full md:h-10"
                enabled={authority.viewMapping}
                disabled={!authority.editMapping}
                value={values.receivableAccountId}
                onValueChange={(value) => setValue('receivableAccountId', value)}
              />
              {errors.receivable_account_id && (
                <p role="alert" className="text-sm text-destructive">
                  {errors.receivable_account_id}
                </p>
              )}
            </div>
          )}
        </DetailSection>

        <div className="space-y-3">
          <MoreOptionsToggle open={showOptions} onOpenChange={setShowOptions} controls="customer-more-options" summary={optionsSummary} />
          <Collapse open={showOptions} id="customer-more-options">
            <SectionStack>
              <DetailSection title="Defaults and status">
                <div className="space-y-1.5">
                  <Label htmlFor="customer-currency">Default currency</Label>
                  <Combobox
                    {...lookupState(currenciesQuery, 'currencies')}
                    id="customer-currency"
                    aria-label="Default currency"
                    className="h-11 w-full md:h-10"
                    value={values.defaultCurrencyId || 'none'}
                    onValueChange={(value) => setValue('defaultCurrencyId', value === 'none' ? '' : value)}
                    searchPlaceholder="Search currencies…"
                    emptyText="No currencies found."
                    options={[{ value: 'none', label: 'Company default' }, ...currencies.map((currency) => ({ value: String(currency.id), label: `${currency.code} — ${currency.name}` }))]}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="customer-status">Status</Label>
                  <Select value={values.status} onValueChange={(value) => setValue('status', value as 'ACTIVE' | 'ARCHIVED')}>
                    <SelectTrigger id="customer-status" className="h-11 md:h-10" aria-label="Status">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="ACTIVE">Active</SelectItem>
                      <SelectItem value="ARCHIVED">Archived</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </DetailSection>
              <CustomFieldInputs entity="customer_accounts" value={fields} onChange={setFields} />
            </SectionStack>
          </Collapse>
        </div>
      </SectionStack>

      {formError && (
        <p role="alert" className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive" data-testid="customer-form-error">
          {formError}
        </p>
      )}

      <div className="sticky bottom-0 -mx-4 flex items-center justify-end gap-2 border-t bg-background px-4 py-3">
        <Button type="button" variant="outline" className="h-11 md:h-9" disabled={busy} onClick={() => leave(customer ? `/customers/${customer.id}` : '/customers')}>
          Cancel
        </Button>
        <Button type="submit" className="h-11 md:h-9" disabled={busy}>
          {busy ? 'Saving…' : isCreate ? 'Create customer' : 'Save changes'}
        </Button>
      </div>
    </form>
  );

  const title = isCreate ? 'New customer' : `Edit ${customer.party?.name ?? customer.code}`;
  const description = isCreate ? 'Register a trading partner as a customer.' : `Customer ${customer.code}`;

  if (presentation === 'sheet') {
    return (
      <RecordSheet title={title} description={description} size="lg" className="erp-record" dismissOnOutsideClick={false} onClose={() => leave(customer ? `/customers/${customer.id}` : '/customers')}>
        <div className="px-4 pb-6 pt-4">{formBody}</div>
      </RecordSheet>
    );
  }

  return (
    <ScreenTemplate kind="form" className="erp-screen">
      <PageHeader title={title} description={description} backTo={customer ? `/customers/${customer.id}${search}` : `/customers${search}`} />
      {formBody}
    </ScreenTemplate>
  );
}
