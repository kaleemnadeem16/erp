import { useEffect, useMemo, useState } from 'react';
import { Lock } from 'lucide-react';
import { useCompanyChangesIndex, useCompanyShow, useCompanyUpdate, useCurrenciesIndex } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Skeleton } from '../../components/ui/skeleton';
import { Textarea } from '../../components/ui/textarea';
import { PageHeader } from '../../components/page/page-header';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { getApiErrorMessage, isApiError } from '../../lib/api-errors';
import { PolicyChangeHistory } from '../settings/policy-change-history';
import { asCompanyView, type CompanyFieldDescription, type CompanyValues } from './company-types';
import { countryOptions, offsetLabel, timeZoneNames } from './time-zones';

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

type Draft = Record<string, string>;

function toDraft(values: CompanyValues): Draft {
  return Object.fromEntries(Object.entries(values).map(([key, value]) => [key, value === null || value === undefined ? '' : String(value)]));
}

/**
 * Settings → Company: the company's identity and contact details plus the few settings every module shares (time zone, base
 * currency, fiscal year start). Everything on it comes from the API's field description, so a field's label, widget, who may edit
 * it and why it is locked are decided by the backend. Module policies (inventory, purchasing…) stay on their own screens.
 */
export function CompanySettingsScreen() {
  const companyQuery = useCompanyShow();
  const company = companyQuery.data?.status === 200 ? asCompanyView(companyQuery.data.data.data) : undefined;
  const changesQuery = useCompanyChangesIndex({ per_page: 10 }, { query: { retry: false } });
  const changes = changesQuery.data?.status === 200 ? changesQuery.data.data.data : [];
  const changesForbidden = isApiError(changesQuery.error) && changesQuery.error.status === 403;
  const currenciesQuery = useCurrenciesIndex({ per_page: 250, sort: 'code' });
  const currencies = currenciesQuery.data?.status === 200 ? currenciesQuery.data.data.data : [];
  const update = useCompanyUpdate();

  const [draft, setDraft] = useState<Draft>({});
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    if (company) setDraft(toDraft(company.values));
    // Re-sync only when the saved values change, not on every keystroke.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyQuery.data]);

  const zones = useMemo(() => timeZoneNames().map((zone) => ({ value: zone, label: `${zone}  (${offsetLabel(zone)})` })), []);
  const countries = useMemo(() => countryOptions(), []);

  if (companyQuery.isLoading || !company) {
    return (
      <div className="space-y-4 p-4 md:p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  const saved_values = toDraft(company.values);
  const editable = (field: CompanyFieldDescription) => field.can_edit && !field.locked;
  const changedFields = company.fields.filter((field) => editable(field) && (draft[field.key] ?? '') !== (saved_values[field.key] ?? ''));
  const needsReason = changedFields.some((field) => field.governed);

  function setValue(key: string, value: string) {
    setSaved(false);
    setDraft((current) => ({ ...current, [key]: value }));
  }

  function save() {
    setFormError(null);
    setErrors({});
    const data: Record<string, unknown> = {};
    for (const field of changedFields) {
      const raw = draft[field.key] ?? '';
      data[field.key] = raw === '' ? null : field.type === 'CURRENCY' || field.type === 'MONTH' ? Number(raw) : raw;
    }
    if (needsReason) data.reason = reason.trim();
    update.mutate(
      { data: data as never },
      {
        onSuccess: () => {
          setReason('');
          setSaved(true);
          changesQuery.refetch();
        },
        onError: (error) => {
          const next: Record<string, string> = {};
          if (isApiError(error) && error.body?.errors) {
            for (const [name, messages] of Object.entries(error.body.errors)) next[name] = messages[0] ?? '';
          }
          setErrors(next);
          // Field errors sit beside their field; anything else (a lock, a conflict) is shown above the button.
          setFormError(Object.keys(next).length > 0 ? null : getApiErrorMessage(error));
        },
      }
    );
  }

  function renderInput(field: CompanyFieldDescription) {
    const value = draft[field.key] ?? '';
    const disabled = !editable(field);
    const id = `company-${field.key}`;
    switch (field.type) {
      case 'TIMEZONE':
        return <Combobox id={id} aria-label={field.label} disabled={disabled} value={value} onValueChange={(v) => setValue(field.key, v)} options={zones} placeholder="Choose a time zone" searchPlaceholder="Search time zones, e.g. Karachi" />;
      case 'COUNTRY':
        return <Combobox id={id} aria-label={field.label} disabled={disabled} value={value} onValueChange={(v) => setValue(field.key, v)} options={countries} placeholder="Choose a country" searchPlaceholder="Search countries" />;
      case 'CURRENCY':
        return (
          <Combobox
            id={id}
            aria-label={field.label}
            disabled={disabled}
            value={value}
            onValueChange={(v) => setValue(field.key, v)}
            options={currencies.map((currency) => ({ value: String(currency.id), label: `${currency.code} — ${currency.name}` }))}
            placeholder="Choose a currency"
            searchPlaceholder="Search currencies"
          />
        );
      case 'MONTH':
        return (
          <Select value={value} onValueChange={(v) => setValue(field.key, v)} disabled={disabled}>
            <SelectTrigger id={id} aria-label={field.label}>
              <SelectValue placeholder="Choose a month" />
            </SelectTrigger>
            <SelectContent>
              {MONTHS.map((month, index) => (
                <SelectItem key={month} value={String(index + 1)}>
                  {month}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        );
      default:
        return (
          <Input
            id={id}
            disabled={disabled}
            value={value}
            maxLength={field.max_length ?? undefined}
            type={field.type === 'EMAIL' ? 'email' : field.type === 'URL' ? 'url' : field.type === 'PHONE' ? 'tel' : 'text'}
            className={field.type === 'CODE' ? 'uppercase' : undefined}
            onChange={(event) => setValue(field.key, field.type === 'CODE' ? event.target.value.toUpperCase() : event.target.value)}
          />
        );
    }
  }

  return (
    <div className="space-y-4 p-4 md:p-6">
      <PageHeader title="Company" description="Who the company is and the settings every part of the system shares." />

      {company.timezone_is_default && (
        <div role="status" className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
          The company time zone is still the default (UTC). Document numbers, backdating limits and accounting days all follow it, so set it to where the business operates.
        </div>
      )}

      <DetailPageLayout>
        {company.sections.map((section) => (
          <DetailSection key={section.key} title={section.label} description={section.description} fullWidth>
            {company.fields
              .filter((field) => field.section === section.key)
              .map((field) => (
                <div key={field.key} className="space-y-1">
                  <Label htmlFor={`company-${field.key}`}>{field.label}</Label>
                  {renderInput(field)}
                  {field.locked && field.locked_reason ? (
                    <p className="flex gap-1 text-xs text-muted-foreground" data-testid={`locked-${field.key}`}>
                      <Lock className="mt-0.5 h-3 w-3 shrink-0" aria-hidden /> {field.locked_reason}
                    </p>
                  ) : (
                    field.description && <p className="text-xs text-muted-foreground">{field.description}</p>
                  )}
                  {!field.can_edit && !field.locked && <p className="text-xs text-muted-foreground">You don't have permission to change this.</p>}
                  {errors[field.key] && <p className="text-xs text-destructive">{errors[field.key]}</p>}
                </div>
              ))}
          </DetailSection>
        ))}

        <div className="col-span-full space-y-3">
          {formError && <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{formError}</div>}
          {needsReason && (
            <div className="space-y-1">
              <Label htmlFor="company-reason">Reason for the change</Label>
              <Textarea id="company-reason" value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Why are these settings changing? It is kept in the history." />
              {errors.reason && <p className="text-xs text-destructive">{errors.reason}</p>}
            </div>
          )}
          <div className="flex items-center gap-3">
            <Button onClick={save} disabled={changedFields.length === 0 || update.isPending || (needsReason && reason.trim().length < 3)}>
              {update.isPending ? 'Saving…' : 'Save changes'}
            </Button>
            {saved && <span className="text-sm text-muted-foreground" role="status">Saved.</span>}
          </div>
        </div>

        <div className="col-span-full">
          <PolicyChangeHistory
            changes={changes.map((change) => ({ ...change, policy: change.section, reason: change.reason ?? '' }))}
            isLoading={changesQuery.isLoading}
            isForbidden={changesForbidden}
          />
        </div>
      </DetailPageLayout>
    </div>
  );
}
