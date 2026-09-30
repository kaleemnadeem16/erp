import { useEffect, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
  MaterialSelectionScope,
  MaterialSelectionStrategy,
  useCategoriesIndex,
  useItemsIndex,
  useMaterialSelectionPoliciesIndex,
  useMaterialSelectionPoliciesStore,
  type MaterialSelectionPolicyResource,
} from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { ListError } from '../../components/data-table';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { Label } from '../../components/ui/label';
import { Textarea } from '../../components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { applyApiErrorsToForm, lookupState } from '../../lib/api-errors';
import { formatDateTime } from '../../lib/format-date';
import { useAuthStore } from '../../stores/auth-store';

const SCOPE_LABELS: Record<string, string> = { COMPANY: 'Whole company', ITEM_CATEGORY: 'A category', ITEM: 'One item' };
const STRATEGY_LABELS: Record<string, string> = {
  MINIMISE_WASTE: 'Minimise waste',
  LOWEST_INTERNAL_COST: 'Lowest internal cost',
  FEWEST_PACKAGES: 'Fewest packages',
};
const STRATEGY_HELP: Record<string, string> = {
  MINIMISE_WASTE: 'Use open remnants and earliest-expiry stock first, then the least surplus and lowest cost. The recommended default.',
  LOWEST_INTERNAL_COST: 'Draw the cheapest stock first.',
  FEWEST_PACKAGES: 'Open as few new packages, and make as few picks, as possible.',
};
const INHERIT = 'INHERIT';

const schema = z.object({
  strategy: z.string().min(1),
  reason: z.string().trim().min(1, 'A reason is required').max(1000),
});
type FormValues = z.infer<typeof schema>;

/**
 * Which stock-selection preset the Material Preview proposes by default (ADR 0053, stored by ADR 0071). A setting
 * for an item beats its category's (nearest ancestor first), which beats the company's, which beats the built-in
 * "Minimise waste". Every change needs a reason, keeps the previous setting as history and only affects previews
 * made afterwards.
 */
export function MaterialSelectionSettingsScreen() {
  const user = useAuthStore((state) => state.user);
  const permissions = Array.isArray(user?.permissions) ? user.permissions : [];
  const mayManage = permissions.includes('estimating.selection-policy.manage');
  const mayView = mayManage || permissions.includes('estimating.policy-history.view');

  const [scopeType, setScopeType] = useState<string>(MaterialSelectionScope.COMPANY);
  const [scopeId, setScopeId] = useState('');

  const categories = useCategoriesIndex({ per_page: 100, sort: 'code' }, { query: { enabled: mayView } });
  const items = useItemsIndex({ per_page: 100, sort: 'code' }, { query: { enabled: mayView } });
  const categoryRows = categories.data?.status === 200 ? categories.data.data.data : [];
  const itemRows = items.data?.status === 200 ? items.data.data.data : [];
  const active = useMaterialSelectionPoliciesIndex({ 'filter[status]': 'ACTIVE', per_page: 100 }, { query: { enabled: mayView } });
  const activeRows = (active.data?.status === 200 ? active.data.data.data : []).filter((row) => row.strategy !== null);

  const options = (scopeType === 'ITEM_CATEGORY' ? categoryRows : itemRows).map((row) => ({ value: String(row.id), label: `${row.code} — ${row.name}` }));
  const scopeLabel = useMemo(() => {
    if (scopeType === 'COMPANY') return 'the whole company';
    return options.find((option) => option.value === scopeId)?.label ?? '';
  }, [scopeType, scopeId, options]);
  const chosen = scopeType === 'COMPANY' || scopeId !== '';

  if (!mayView) {
    return (
      <div className="space-y-4 p-4 md:p-6">
        <PageHeader title="Material selection" description="Which stock the Material Preview proposes first." />
        <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-4 text-sm">
          You do not have permission to view this. Ask an administrator for <code>estimating.selection-policy.manage</code>.
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-4 p-4 md:p-6">
      <PageHeader
        title="Material selection"
        description="Which of the three selection presets the Material Preview proposes first for a material. Set it for the whole company, a category or one item; the most specific setting wins."
      />
      <DetailPageLayout>
        <DetailSection title="Where a preference is set" description="Every setting in force. Materials with none use Minimise waste." fullWidth>
          <div className="space-y-2 text-sm sm:col-span-2">
            {active.isError ? (
              <ListError message="Couldn't load the settings." error={active.error} onRetry={() => void active.refetch()} />
            ) : activeRows.length === 0 ? (
              <p className="text-muted-foreground">Nothing set: every material uses the built-in default, Minimise waste.</p>
            ) : (
              <ul className="divide-y rounded-md border">
                {activeRows.map((row) => (
                  <li key={row.id} className="flex items-center justify-between gap-2 p-2">
                    <span>
                      {row.scope_name ?? `#${row.scope_id}`} <span className="text-xs text-muted-foreground">({SCOPE_LABELS[row.scope_type]})</span> —{' '}
                      <Badge variant="secondary">{String(row.strategy?.label ?? "")}</Badge>
                    </span>
                    <Button type="button" size="sm" variant="ghost" onClick={() => { setScopeType(row.scope_type); setScopeId(row.scope_id === null ? '' : String(row.scope_id)); }}>
                      Show
                    </Button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </DetailSection>

        <DetailSection title="Choose where to set it" description="An item's setting beats its category's, which beats the company's." fullWidth>
          <div className="grid gap-3 sm:col-span-2 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="selection-scope">Preference applies to</Label>
              <Select value={scopeType} onValueChange={(value) => { setScopeType(value); setScopeId(''); }}>
                <SelectTrigger id="selection-scope" aria-label="Preference applies to"><SelectValue /></SelectTrigger>
                <SelectContent>{Object.entries(SCOPE_LABELS).map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}</SelectContent>
              </Select>
            </div>
            {scopeType !== 'COMPANY' && (
              <div className="space-y-2">
                <Label>{scopeType === 'ITEM_CATEGORY' ? 'Category' : 'Item'}</Label>
                <Combobox
                  {...lookupState(scopeType === 'ITEM_CATEGORY' ? categories : items, scopeType === 'ITEM_CATEGORY' ? 'categories' : 'items')}
                  aria-label={scopeType === 'ITEM_CATEGORY' ? 'Category' : 'Item'}
                  value={scopeId}
                  onValueChange={setScopeId}
                  options={options}
                  placeholder={scopeType === 'ITEM_CATEGORY' ? 'Select category' : 'Select item'}
                  searchPlaceholder="Search…"
                  emptyText="Nothing found."
                />
              </div>
            )}
          </div>
        </DetailSection>

        {chosen && (
          <SelectionPanel
            key={`${scopeType}-${scopeId}`}
            scopeType={scopeType}
            scopeId={scopeType === 'COMPANY' ? null : Number(scopeId)}
            scopeLabel={scopeLabel}
            mayManage={mayManage}
            onChanged={() => void active.refetch()}
          />
        )}
      </DetailPageLayout>
    </div>
  );
}

function SelectionPanel({ scopeType, scopeId, scopeLabel, mayManage, onChanged }: {
  scopeType: string;
  scopeId: number | null;
  scopeLabel: string;
  mayManage: boolean;
  onChanged: () => void;
}) {
  const [error, setError] = useState<string | null>(null);
  const history = useMaterialSelectionPoliciesIndex({
    'filter[scope_type]': scopeType as MaterialSelectionScope,
    ...(scopeId === null ? {} : { 'filter[scope_id]': scopeId }),
    include: 'changedBy',
    per_page: 50,
  });
  const rows: MaterialSelectionPolicyResource[] = history.data?.status === 200 ? history.data.data.data : [];
  const current = rows.find((row) => row.status === 'ACTIVE');
  const store = useMaterialSelectionPoliciesStore();
  const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { strategy: current?.strategy?.code ?? INHERIT, reason: '' } });

  // The form is created before the history has loaded, so it re-reads the setting in force once it arrives or changes.
  const currentCode = current?.strategy?.code ?? INHERIT;
  useEffect(() => { form.reset({ strategy: currentCode, reason: '' }); }, [currentCode, form]);

  function submit(values: FormValues) {
    setError(null);
    store.mutate(
      {
        data: {
          scope_type: scopeType as MaterialSelectionScope,
          ...(scopeId === null ? {} : { scope_id: scopeId }),
          strategy: values.strategy === INHERIT ? null : (values.strategy as MaterialSelectionStrategy),
          reason: values.reason,
        },
      },
      {
        onSuccess: () => {
          form.reset({ strategy: values.strategy, reason: '' });
          void history.refetch();
          onChanged();
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, ['strategy', 'reason'] as const)),
      },
    );
  }

  return (
    <>
      <DetailSection
        title={`Preference for ${scopeLabel}`}
        description={
          history.isLoading
            ? 'Loading the setting…'
            : current?.strategy ? `Currently ${String(current.strategy.label)}.` : 'Nothing is set here, so the next level up applies.'
        }
        fullWidth
      >
        <div className="space-y-3 sm:col-span-2">
          {history.isError ? (
            <ListError message="Couldn't load this setting." error={history.error} onRetry={() => void history.refetch()} />
          ) : history.isLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !mayManage ? (
            <p className="text-sm text-muted-foreground">You can view this setting but not change it.</p>
          ) : (
            <Form {...form}>
              <form onSubmit={form.handleSubmit(submit)} className="space-y-3">
                {error && <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}
                <FormField control={form.control} name="strategy" render={({ field }) => (
                  <FormItem>
                    <FormLabel>Selection preset</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl><SelectTrigger aria-label="Selection preset"><SelectValue /></SelectTrigger></FormControl>
                      <SelectContent>
                        <SelectItem value={INHERIT}>No preference here (use the next level up)</SelectItem>
                        {Object.entries(STRATEGY_LABELS).map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}
                      </SelectContent>
                    </Select>
                    <p className="text-xs text-muted-foreground">{STRATEGY_HELP[field.value] ?? 'The setting is removed; the category, then the company, then the built-in default applies.'}</p>
                    <FormMessage />
                  </FormItem>
                )} />
                <FormField control={form.control} name="reason" render={({ field }) => (
                  <FormItem>
                    <FormLabel>Reason for the change</FormLabel>
                    <FormControl><Textarea {...field} rows={2} placeholder="Why this preference? It is kept with the change." /></FormControl>
                    <FormMessage />
                  </FormItem>
                )} />
                <Button type="submit" disabled={store.isPending}>{store.isPending ? 'Saving…' : 'Save preference'}</Button>
                <p className="text-xs text-muted-foreground">Applies to previews made from now on. Earlier previews are not recalculated.</p>
              </form>
            </Form>
          )}
        </div>
      </DetailSection>

      <DetailSection title="History" description="Every change to this preference, newest first." fullWidth>
        <div className="sm:col-span-2">
          {history.isLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : rows.length === 0 ? (
            <p className="text-sm text-muted-foreground">No changes recorded yet.</p>
          ) : (
            <ul className="space-y-2">
              {rows.map((row) => (
                <li key={row.id} className="rounded-md border p-3 text-sm">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="font-medium">{row.strategy ? String(row.strategy.label) : 'Preference removed'}</span>
                    <span className="text-xs text-muted-foreground">
                      {formatDateTime(row.effective_from)}{row.changed_by_user ? ` — ${row.changed_by_user.name}` : ''}
                      {row.status === 'ACTIVE' ? ' · in force' : ''}
                    </span>
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground">"{row.reason}"</p>
                </li>
              ))}
            </ul>
          )}
        </div>
      </DetailSection>
    </>
  );
}
