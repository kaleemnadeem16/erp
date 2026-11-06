import { useRef, useState, type FormEvent } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { useChartOfAccountsShow, useChartOfAccountsStore, useChartOfAccountsUpdate, useCurrenciesIndex, type ChartOfAccountResource } from '@erp/core-api';

import { DetailSection } from '../../components/detail/detail-page-layout';
import { AccountCombobox } from '../../components/lookups/entity-comboboxes';
import { RecordPresenter } from '../../components/page/record-presenter';
import { Button } from '../../components/ui/button';
import { Checkbox } from '../../components/ui/checkbox';
import { Collapse } from '../../components/ui/collapse';
import { Combobox } from '../../components/ui/combobox';
import { FieldHint } from '../../components/ui/field-hint';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { MoreOptionsToggle } from '../../components/ui/more-options-toggle';
import { SectionStack } from '../../components/ui/section-stack';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Skeleton } from '../../components/ui/skeleton';
import { usePermission } from '../../hooks/use-permission';
import { getApiErrorMessage, isApiError, lookupState } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import { AccountStatusBadge } from './account-columns';
import { accountPayload, accountTypeLabel, ACCOUNT_TYPES, EMPTY_ACCOUNT_FORM, validateAccountForm, type AccountFormErrors, type AccountFormValues } from './account-rules';

const LIST = '/settings/chart-of-accounts';

/** Create (`/settings/chart-of-accounts/new`) or edit (`/settings/chart-of-accounts/:id`) an account; archive or activate from the sheet header. */
export function ChartOfAccountFormScreen({ presentation = 'sheet' }: { presentation?: Presentation } = {}) {
  const { id } = useParams<{ id: string }>();
  const accountId = Number(id);
  const query = useChartOfAccountsShow(accountId, { include: 'parent' }, { query: { enabled: Number.isFinite(accountId) && accountId > 0 } });
  const account = query.data?.status === 200 ? query.data.data.data : undefined;

  if (id && !account) {
    const unavailable = !isApiError(query.error) || query.error.status === 404 || query.error.status === 403;

    return (
      <RecordPresenter presentation={presentation} title="Account" listPath={LIST} listLabel="Chart of accounts" size="lg" form>
        {query.isLoading ? (
          <Skeleton className="h-40 w-full" />
        ) : (
          <p role="alert" className="text-sm text-destructive">
            {unavailable ? "This account isn't available." : "Couldn't load this account."}
          </p>
        )}
      </RecordPresenter>
    );
  }

  return <AccountEditor key={id ?? 'new'} account={account} presentation={presentation} />;
}

function AccountEditor({ account, presentation }: { account?: ChartOfAccountResource; presentation: Presentation }) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const mayManage = usePermission('accounting.chart-of-account.manage');
  const creating = !account;
  const [values, setValues] = useState<AccountFormValues>(
    account
      ? {
          code: account.code,
          name: account.name,
          type: (ACCOUNT_TYPES as readonly string[]).includes(account.type) ? (account.type as AccountFormValues['type']) : '',
          parentId: account.parent_id ? String(account.parent_id) : '',
          isPostable: account.is_postable,
          currencyId: account.currency_id ? String(account.currency_id) : '',
          status: account.status === 'ARCHIVED' ? 'ARCHIVED' : 'ACTIVE',
        }
      : EMPTY_ACCOUNT_FORM,
  );
  const [showOptions, setShowOptions] = useState(Boolean(account?.currency_id));
  const [errors, setErrors] = useState<AccountFormErrors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const formRef = useRef<HTMLFormElement>(null);
  const currenciesQuery = useCurrenciesIndex({ per_page: 250, sort: 'code' });
  const currencies = currenciesQuery.data?.status === 200 ? currenciesQuery.data.data.data : [];
  const store = useChartOfAccountsStore();
  const update = useChartOfAccountsUpdate();
  const busy = store.isPending || update.isPending;
  const set = <K extends keyof AccountFormValues>(key: K, value: AccountFormValues[K]) => setValues((current) => ({ ...current, [key]: value }));
  const back = () => navigate({ pathname: LIST, search: window.location.search });

  async function submit(event: FormEvent, overrides: Partial<AccountFormValues> = {}) {
    event.preventDefault();
    setFormError(null);
    const next = { ...values, ...overrides };
    const problems = validateAccountForm(next, account?.id);
    setErrors(problems);
    if (Object.keys(problems).length > 0) {
      window.setTimeout(() => formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus(), 0);
      return;
    }
    try {
      const result = account ? await update.mutateAsync({ chartOfAccount: account.id, data: accountPayload(next) }) : await store.mutateAsync({ data: accountPayload(next) });
      if (result.status === 200 || result.status === 201) {
        await queryClient.invalidateQueries();
        back();
      }
    } catch (failure) {
      if (isApiError(failure) && failure.body?.errors) {
        const server = failure.body.errors;
        const first = (key: string) => server[key]?.[0];
        setErrors({ code: first('code'), name: first('name'), type: first('type'), parent_id: first('parent_id') });
        const known = ['code', 'name', 'type', 'parent_id'];
        const rest = Object.entries(server).filter(([key]) => !known.includes(key)).map(([, messages]) => messages[0]);
        setFormError(rest.length > 0 ? rest.join(' ') : null);
      } else {
        setFormError(getApiErrorMessage(failure) ?? 'The account could not be saved.');
      }
    }
  }

  const fieldError = (key: keyof AccountFormErrors, id: string) =>
    errors[key] ? (
      <p id={`${id}-error`} role="alert" className="text-sm text-destructive">
        {errors[key]}
      </p>
    ) : null;
  const text = (id: string, label: string, key: 'code' | 'name', errorKey: keyof AccountFormErrors) => (
    <div className="space-y-1.5">
      <Label htmlFor={id}>{label}</Label>
      <Input id={id} className="h-11 md:h-10" autoComplete="off" required disabled={!mayManage} value={values[key]} aria-invalid={errors[errorKey] ? true : undefined} aria-describedby={errors[errorKey] ? `${id}-error` : undefined} onChange={(event) => set(key, event.target.value)} />
      {fieldError(errorKey, id)}
    </div>
  );

  const currency = currencies.find((item) => String(item.id) === values.currencyId);
  const summary = [currency?.code, values.status === 'ARCHIVED' ? 'archived' : undefined].filter(Boolean).join(', ');

  return (
    <RecordPresenter
      presentation={presentation}
      title={creating ? 'New account' : `${account.code} — ${account.name}`}
      description={creating ? 'Add an account to the chart.' : `${accountTypeLabel(account.type)} account #${account.id}`}
      actions={
        account && mayManage ? (
          <div className="flex items-center gap-2">
            <AccountStatusBadge status={account.status} />
            <Button type="button" variant="outline" size="sm" className="h-11 md:h-8" disabled={busy} onClick={(event) => void submit(event, { status: account.status === 'ACTIVE' ? 'ARCHIVED' : 'ACTIVE' })}>
              {account.status === 'ACTIVE' ? 'Archive' : 'Activate'}
            </Button>
          </div>
        ) : undefined
      }
      listPath={LIST}
      listLabel="Chart of accounts"
      size="lg"
      form
    >
      <form ref={formRef} onSubmit={(event) => void submit(event)} noValidate className="space-y-6" aria-label={creating ? 'New account' : 'Edit account'}>
        <SectionStack>
          <DetailSection title="Account">
            {text('account-code', 'Account code', 'code', 'code')}
            {text('account-name', 'Name', 'name', 'name')}
            <div className="space-y-1.5">
              <Label htmlFor="account-type">Type</Label>
              <Select value={values.type || undefined} onValueChange={(value) => set('type', value as AccountFormValues['type'])} disabled={!mayManage}>
                <SelectTrigger id="account-type" className="h-11 md:h-10" aria-label="Type" aria-invalid={errors.type ? true : undefined}>
                  <SelectValue placeholder="Choose a type" />
                </SelectTrigger>
                <SelectContent>
                  {ACCOUNT_TYPES.map((type) => (
                    <SelectItem key={type} value={type}>
                      {accountTypeLabel(type)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {fieldError('type', 'account-type')}
            </div>
            <div className="space-y-1.5">
              <div className="flex min-h-5 items-center gap-1.5">
                <Label htmlFor="account-parent">Parent account</Label>
                <FieldHint label="Parent account">The heading this account is grouped under. Leave empty for a top-level account.</FieldHint>
              </div>
              <AccountCombobox
                id="account-parent"
                label="Parent account"
                allLabel="No parent (top level)"
                placeholder="No parent (top level)"
                className="h-11 w-full md:h-10"
                excludeId={account?.id}
                disabled={!mayManage}
                value={values.parentId || 'all'}
                onValueChange={(value) => set('parentId', value === 'all' ? '' : value)}
              />
              {fieldError('parent_id', 'account-parent')}
            </div>
            <div className="flex min-h-11 items-center gap-2 sm:col-span-2">
              <Checkbox id="account-postable" checked={values.isPostable} disabled={!mayManage} onCheckedChange={(checked) => set('isPostable', checked === true)} />
              <Label htmlFor="account-postable" className="font-normal">
                Postable — entries can be posted to this account (turn off for a heading that only groups others)
              </Label>
            </div>
          </DetailSection>

          <div className="space-y-3">
            <MoreOptionsToggle open={showOptions} onOpenChange={setShowOptions} controls="account-more-options" summary={summary} />
            <Collapse open={showOptions} id="account-more-options">
              <DetailSection title="Currency">
                <div className="space-y-1.5 sm:col-span-2">
                  <Label htmlFor="account-currency">Currency</Label>
                  <Combobox
                    {...lookupState(currenciesQuery, 'currencies')}
                    id="account-currency"
                    aria-label="Currency"
                    className="h-11 w-full md:h-10"
                    disabled={!mayManage}
                    value={values.currencyId || 'none'}
                    onValueChange={(value) => set('currencyId', value === 'none' ? '' : value)}
                    searchPlaceholder="Search currencies…"
                    emptyText="No currencies found."
                    options={[{ value: 'none', label: 'Any currency (company base)' }, ...currencies.map((item) => ({ value: String(item.id), label: `${item.code} — ${item.name}` }))]}
                  />
                </div>
              </DetailSection>
            </Collapse>
          </div>
        </SectionStack>

        {formError && (
          <p role="alert" className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive" data-testid="account-form-error">
            {formError}
          </p>
        )}

        <div className="sticky bottom-0 -mx-4 flex items-center justify-end gap-2 border-t bg-background px-4 py-3">
          <Button type="button" variant="outline" className="h-11 md:h-9" disabled={busy} onClick={back}>
            {mayManage ? 'Cancel' : 'Close'}
          </Button>
          {mayManage && (
            <Button type="submit" className="h-11 md:h-9" disabled={busy}>
              {busy ? 'Saving…' : creating ? 'Create account' : 'Save changes'}
            </Button>
          )}
        </div>
      </form>
    </RecordPresenter>
  );
}
