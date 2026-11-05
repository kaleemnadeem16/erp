import { useRef, useState, type FormEvent } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import {
  useCurrenciesIndex,
  useExchangeRatesDestroy,
  useExchangeRatesShow,
  useExchangeRatesStore,
  useExchangeRatesUpdate,
  type ExchangeRateResource,
} from '@erp/core-api';
import { Trash2 } from 'lucide-react';

import { DetailField } from '../../components/detail/detail-field';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { RecordPresenter } from '../../components/page/record-presenter';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { DateField } from '../../components/ui/date-field';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../../components/ui/dialog';
import { FieldHint } from '../../components/ui/field-hint';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { SectionStack } from '../../components/ui/section-stack';
import { Skeleton } from '../../components/ui/skeleton';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage, isApiError, lookupState } from '../../lib/api-errors';
import { formatDate } from '../../lib/format-date';
import type { Presentation } from '../../lib/presentation';
import {
  pairLabel,
  RATE_TYPES,
  rateTypeLabel,
  todayIso,
  validateExchangeRateForm,
  type ExchangeRateFormErrors,
  type ExchangeRateFormValues,
  type RateTypeValue,
} from './exchange-rate-rules';

const LIST = '/settings/exchange-rates';

/** Create (`/settings/exchange-rates/new`) or open (`/settings/exchange-rates/:id`) a rate: only the rate itself can change afterwards, and a rate can be deleted. */
export function ExchangeRateFormScreen({ presentation = 'sheet' }: { presentation?: Presentation } = {}) {
  const { id } = useParams<{ id: string }>();
  const rateId = Number(id);
  const query = useExchangeRatesShow(rateId, { include: 'from_currency,to_currency' }, { query: { enabled: Number.isFinite(rateId) && rateId > 0 } });
  const rate = query.data?.status === 200 ? query.data.data.data : undefined;

  if (id && !rate) {
    const unavailable = !isApiError(query.error) || query.error.status === 404 || query.error.status === 403;

    return (
      <RecordPresenter presentation={presentation} title="Exchange rate" listPath={LIST} listLabel="Exchange rates" size="lg" form>
        {query.isLoading ? (
          <Skeleton className="h-40 w-full" />
        ) : (
          <p role="alert" className="text-sm text-destructive">
            {unavailable ? "This exchange rate isn't available." : "Couldn't load this exchange rate."}
          </p>
        )}
      </RecordPresenter>
    );
  }

  return <ExchangeRateEditor key={id ?? 'new'} rate={rate} presentation={presentation} />;
}

function ExchangeRateEditor({ rate, presentation }: { rate?: ExchangeRateResource; presentation: Presentation }) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const mayManage = usePermission('currency.exchange-rate.manage');
  const creating = !rate;
  const [values, setValues] = useState<ExchangeRateFormValues>({
    fromCurrencyId: rate ? String(rate.from_currency_id) : '',
    toCurrencyId: rate ? String(rate.to_currency_id) : '',
    rateDate: rate?.rate_date ?? todayIso(),
    rateType: rate && RATE_TYPES.includes(rate.rate_type as RateTypeValue) ? (rate.rate_type as RateTypeValue) : 'SPOT',
    rate: rate?.rate ?? '',
  });
  const [errors, setErrors] = useState<ExchangeRateFormErrors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const formRef = useRef<HTMLFormElement>(null);
  const currenciesQuery = useCurrenciesIndex({ per_page: 250, sort: 'code' });
  const currencies = currenciesQuery.data?.status === 200 ? currenciesQuery.data.data.data : [];
  const store = useExchangeRatesStore();
  const update = useExchangeRatesUpdate();
  const destroy = useExchangeRatesDestroy();
  const busy = store.isPending || update.isPending;
  const set = <K extends keyof ExchangeRateFormValues>(key: K, value: ExchangeRateFormValues[K]) => setValues((current) => ({ ...current, [key]: value }));
  const back = () => navigate({ pathname: LIST, search: window.location.search });
  const options = currencies.map((currency) => ({ value: String(currency.id), label: `${currency.code} — ${currency.name}` }));

  async function submit(event: FormEvent) {
    event.preventDefault();
    setFormError(null);
    const problems = validateExchangeRateForm(values, creating);
    setErrors(problems);
    if (Object.keys(problems).length > 0) {
      window.setTimeout(() => formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus(), 0);
      return;
    }
    try {
      const rateText = values.rate.trim();
      const result = rate
        ? await update.mutateAsync({ exchangeRate: rate.id, data: { rate: rateText } })
        : await store.mutateAsync({ data: { from_currency_id: Number(values.fromCurrencyId), to_currency_id: Number(values.toCurrencyId), rate: rateText, rate_date: values.rateDate, rate_type: values.rateType } });
      if (result.status === 200 || result.status === 201) {
        await queryClient.invalidateQueries();
        back();
      }
    } catch (failure) {
      if (isApiError(failure) && failure.body?.errors) {
        const server = failure.body.errors;
        const first = (key: string) => server[key]?.[0];
        setErrors({ from_currency_id: first('from_currency_id'), to_currency_id: first('to_currency_id'), rate_date: first('rate_date'), rate_type: first('rate_type'), rate: first('rate') });
        const known = ['from_currency_id', 'to_currency_id', 'rate_date', 'rate_type', 'rate'];
        const rest = Object.entries(server).filter(([key]) => !known.includes(key)).map(([, messages]) => messages[0]);
        setFormError(rest.length > 0 ? rest.join(' ') : null);
      } else {
        setFormError(getApiErrorMessage(failure) ?? 'The rate could not be saved.');
      }
    }
  }

  async function remove() {
    if (!rate) return;
    setFormError(null);
    try {
      await destroy.mutateAsync({ exchangeRate: rate.id });
      await queryClient.invalidateQueries();
      setConfirmDelete(false);
      back();
    } catch (failure) {
      setConfirmDelete(false);
      setFormError(getApiErrorMessage(failure) ?? 'The rate could not be deleted.');
    }
  }

  const error = (key: keyof ExchangeRateFormErrors, id: string) =>
    errors[key] ? (
      <p id={`${id}-error`} role="alert" className="text-sm text-destructive">
        {errors[key]}
      </p>
    ) : null;

  const title = creating ? 'New exchange rate' : `${pairLabel(rate.from_currency, rate.to_currency, rate.from_currency_id, rate.to_currency_id)} on ${formatDate(rate.rate_date) ?? rate.rate_date}`;

  return (
    <RecordPresenter
      presentation={presentation}
      title={title}
      description={creating ? 'Enter the rate by hand. One rate per currency pair, date and type.' : `${rateTypeLabel(rate.rate_type)} rate · entered ${rate.source ?? 'manually'}`}
      actions={
        rate && mayManage ? (
          <Button type="button" variant="outline" size="sm" className="h-11 md:h-8" onClick={() => setConfirmDelete(true)}>
            <Trash2 aria-hidden="true" className="mr-1.5 h-4 w-4" />
            Delete
          </Button>
        ) : undefined
      }
      listPath={LIST}
      listLabel="Exchange rates"
      size="lg"
      form
    >
      <form ref={formRef} onSubmit={(event) => void submit(event)} noValidate className="space-y-6" aria-label={creating ? 'New exchange rate' : 'Edit exchange rate'}>
        <SectionStack>
          <DetailSection title={creating ? 'Currencies and date' : 'Rate'}>
            {creating ? (
              <>
                <div className="space-y-1.5">
                  <Label htmlFor="rate-from">From currency</Label>
                  <Combobox
                    {...lookupState(currenciesQuery, 'currencies')}
                    id="rate-from"
                    aria-label="From currency"
                    aria-invalid={errors.from_currency_id ? true : undefined}
                    className="h-11 w-full md:h-10"
                    value={values.fromCurrencyId}
                    onValueChange={(value) => set('fromCurrencyId', value)}
                    placeholder="Choose a currency"
                    searchPlaceholder="Search currencies…"
                    emptyText="No currencies found."
                    options={options}
                  />
                  {error('from_currency_id', 'rate-from')}
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="rate-to">To currency</Label>
                  <Combobox
                    {...lookupState(currenciesQuery, 'currencies')}
                    id="rate-to"
                    aria-label="To currency"
                    aria-invalid={errors.to_currency_id ? true : undefined}
                    className="h-11 w-full md:h-10"
                    value={values.toCurrencyId}
                    onValueChange={(value) => set('toCurrencyId', value)}
                    placeholder="Choose a currency"
                    searchPlaceholder="Search currencies…"
                    emptyText="No currencies found."
                    options={options}
                  />
                  {error('to_currency_id', 'rate-to')}
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="rate-date">Rate date</Label>
                  <DateField id="rate-date" aria-label="Rate date" value={values.rateDate} onChange={(value) => set('rateDate', value)} className="h-11 w-full md:h-10" />
                  {error('rate_date', 'rate-date')}
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="rate-type">Rate type</Label>
                  <Combobox
                    id="rate-type"
                    aria-label="Rate type"
                    className="h-11 w-full md:h-10"
                    value={values.rateType}
                    onValueChange={(value) => set('rateType', value as RateTypeValue)}
                    searchPlaceholder="Search types…"
                    emptyText="No types."
                    options={RATE_TYPES.map((type) => ({ value: type, label: rateTypeLabel(type) }))}
                  />
                </div>
              </>
            ) : (
              <>
                <DetailField label="Currency pair">{pairLabel(rate.from_currency, rate.to_currency, rate.from_currency_id, rate.to_currency_id)}</DetailField>
                <DetailField label="Rate date">{formatDate(rate.rate_date)}</DetailField>
                <DetailField label="Rate type">{rateTypeLabel(rate.rate_type)}</DetailField>
                <DetailField label="Source">{rate.source ?? undefined}</DetailField>
              </>
            )}
            <div className="space-y-1.5 sm:col-span-2">
              <div className="flex min-h-5 items-center gap-1.5">
                <Label htmlFor="rate-value">Rate</Label>
                <FieldHint label="Rate">How many units of the “to” currency one unit of the “from” currency buys. Up to 10 digits before the point and 9 after.</FieldHint>
              </div>
              <Input
                id="rate-value"
                className="h-11 md:h-10"
                inputMode="decimal"
                autoComplete="off"
                disabled={!mayManage}
                value={values.rate}
                aria-invalid={errors.rate ? true : undefined}
                aria-describedby={errors.rate ? 'rate-value-error' : undefined}
                onChange={(event) => set('rate', event.target.value)}
              />
              {error('rate', 'rate-value')}
              {!creating && <p className="text-xs text-muted-foreground">Only the rate can be changed. To correct the date, type or currencies, delete this rate and enter a new one.</p>}
            </div>
          </DetailSection>
        </SectionStack>

        {formError && (
          <p role="alert" className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive" data-testid="rate-form-error">
            {formError}
          </p>
        )}

        <div className="sticky bottom-0 -mx-4 flex items-center justify-end gap-2 border-t bg-background px-4 py-3">
          <Button type="button" variant="outline" className="h-11 md:h-9" disabled={busy} onClick={back}>
            {mayManage ? 'Cancel' : 'Close'}
          </Button>
          {mayManage && (
            <Button type="submit" className="h-11 md:h-9" disabled={busy}>
              {busy ? 'Saving…' : creating ? 'Save rate' : 'Save changes'}
            </Button>
          )}
        </div>
      </form>

      <Dialog open={confirmDelete} onOpenChange={(open) => !open && !destroy.isPending && setConfirmDelete(false)}>
        <DialogContent className="max-h-[90dvh] max-w-md overflow-y-auto" data-testid="delete-rate-dialog">
          <DialogHeader>
            <DialogTitle>Delete this rate?</DialogTitle>
            <DialogDescription>
              The rate is removed from this list and from rate lookups, and it cannot be brought back. Documents that already used it keep the rate they were made with: nothing is revalued.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="gap-2 sm:gap-0">
            <Button variant="outline" className="h-11 md:h-9" disabled={destroy.isPending} onClick={() => setConfirmDelete(false)}>
              Keep it
            </Button>
            <Button variant="destructive" className="h-11 md:h-9" disabled={destroy.isPending} onClick={() => void remove()}>
              {destroy.isPending ? 'Deleting…' : 'Delete rate'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </RecordPresenter>
  );
}
