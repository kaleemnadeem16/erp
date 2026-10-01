import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
  useApprovalSubjectTypesIndex,
  useStockMixPoliciesIndex,
  useStockMixPoliciesStore,
  type StockMixLevel,
  type StoreStockMixPolicyRequest,
  type StockMixPolicyResource,
} from '@erp/core-api';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { ListError } from '../../components/data-table';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Textarea } from '../../components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import { formatDateTime } from '../../lib/format-date';

const INHERIT = 'INHERIT';
const LEVELS: Array<{ value: string; label: string; help: string }> = [
  { value: 'NOT_ALLOWED', label: 'Not allowed', help: 'Follow the proposal. The "Choose the stock yourself" control is not offered and a hand-built mix is refused.' },
  { value: 'INFORMATION', label: 'Allowed, show the cost', help: 'People may choose stock by hand. The mix’s cost is shown against the proposal’s; nothing is required. This is the built-in default.' },
  { value: 'REASON_REQUIRED', label: 'Reason needed when it costs much more', help: 'When a mix costs at least this much more per unit than the proposal, the person must give a reason.' },
  { value: 'APPROVAL_REQUIRED', label: 'Approval needed when it costs much more', help: 'At or above this much more, the mix would need approval under your approval policy. Needs approvals switched on for hand-chosen stock.' },
];
const NEEDS_PERCENT = new Set(['REASON_REQUIRED', 'APPROVAL_REQUIRED']);

const schema = z.object({
  level: z.string().min(1),
  threshold: z.string().trim(),
  reason: z.string().trim().min(1, 'A reason is required').max(1000),
});
type FormValues = z.infer<typeof schema>;

/**
 * How tightly the company controls stock chosen by hand (ADR 0076). Company-wide for now; the stored shape already
 * covers category and item scope, so those can be added later without redesign. A governed setting: every change needs
 * a reason, keeps the previous setting as history and affects only previews made afterwards.
 */
export function StockMixControlPanel({ mayManage }: { mayManage: boolean }) {
  const [error, setError] = useState<string | null>(null);
  const history = useStockMixPoliciesIndex({ 'filter[scope_type]': 'COMPANY', include: 'changedBy', per_page: 50 });
  const rows: StockMixPolicyResource[] = history.data?.status === 200 ? history.data.data.data : [];
  const current = rows.find((row) => row.status === 'ACTIVE');
  const store = useStockMixPoliciesStore();
  const subjects = useApprovalSubjectTypesIndex({ query: { enabled: mayManage } });
  const subjectRows = subjects.data?.status === 200 ? subjects.data.data.data : [];
  const approvalsOn = subjectRows.some((subject) => subject.code === 'STOCK_MIX' && subject.mode !== 'NONE');

  const currentCode = current?.level?.code ?? INHERIT;
  const currentThreshold = current?.threshold_percent ? String(Number(current.threshold_percent)) : '';
  const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { level: currentCode, threshold: currentThreshold, reason: '' } });
  // The form is created before the setting has loaded, so it re-reads what is in force once it arrives or changes.
  useEffect(() => { form.reset({ level: currentCode, threshold: currentThreshold, reason: '' }); }, [currentCode, currentThreshold, form]);
  const level = form.watch('level');

  function submit(values: FormValues) {
    setError(null);
    if (NEEDS_PERCENT.has(values.level) && (values.threshold === '' || !(Number(values.threshold) > 0))) {
      form.setError('threshold', { message: 'Enter a percentage above zero' });
      return;
    }
    const data: StoreStockMixPolicyRequest = {
      scope_type: 'COMPANY',
      level: values.level === INHERIT ? null : (values.level as StockMixLevel),
      ...(NEEDS_PERCENT.has(values.level) ? { threshold_percent: values.threshold } : {}),
      reason: values.reason,
    };
    store.mutate(
      { data },
      {
        onSuccess: () => { void history.refetch(); },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, ['level', 'threshold', 'reason'] as const)),
      },
    );
  }

  const label = current?.level ? String(current.level.label) : 'Allowed, show the cost (built-in default)';
  const help = LEVELS.find((entry) => entry.value === level)?.help ?? 'The company setting is removed and the built-in default (allowed, show the cost) applies.';

  return (
    <>
      <DetailSection
        title="Choosing stock by hand"
        description={history.isLoading ? 'Loading the setting…' : `Currently: ${label}${current?.threshold_percent ? ` — ${Number(current.threshold_percent)}% above the proposal` : ''}.`}
        fullWidth
      >
        <div className="space-y-3 sm:col-span-2">
          <p className="text-sm text-muted-foreground">
            Whether people may pick the stock to draw themselves instead of using a preset’s proposal, and how much extra cost needs a reason or an approval.
            Set for the whole company; category and item settings can be added later. It only matters for items costed per delivery or batch — with an average
            cost every source costs the same.
          </p>
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
                <FormField control={form.control} name="level" render={({ field }) => (
                  <FormItem>
                    <FormLabel>Stock choice level</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl><SelectTrigger aria-label="Stock choice level"><SelectValue /></SelectTrigger></FormControl>
                      <SelectContent>
                        <SelectItem value={INHERIT}>Remove the setting (built-in default)</SelectItem>
                        {LEVELS.map((entry) => (
                          <SelectItem key={entry.value} value={entry.value} disabled={entry.value === 'APPROVAL_REQUIRED' && !approvalsOn}>
                            {entry.label}{entry.value === 'APPROVAL_REQUIRED' && !approvalsOn ? ' (switch on approvals first)' : ''}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <p className="text-xs text-muted-foreground">{help}</p>
                    <FormMessage />
                  </FormItem>
                )} />
                {NEEDS_PERCENT.has(level) && (
                  <FormField control={form.control} name="threshold" render={({ field }) => (
                    <FormItem className="max-w-xs">
                      <FormLabel>Percentage above the proposal</FormLabel>
                      <FormControl><Input {...field} inputMode="decimal" placeholder="10" /></FormControl>
                      <p className="text-xs text-muted-foreground">How many percent more per unit than the proposal a mix may cost (for example 10).</p>
                      <FormMessage />
                    </FormItem>
                  )} />
                )}
                <FormField control={form.control} name="reason" render={({ field }) => (
                  <FormItem>
                    <FormLabel>Reason for the stock choice change</FormLabel>
                    <FormControl><Textarea {...field} rows={2} placeholder="Why this level? It is kept with the change." /></FormControl>
                    <FormMessage />
                  </FormItem>
                )} />
                <Button type="submit" disabled={store.isPending}>{store.isPending ? 'Saving…' : 'Save'}</Button>
                <p className="text-xs text-muted-foreground">Applies to previews made from now on. Earlier previews are not recalculated.</p>
              </form>
            </Form>
          )}
        </div>
      </DetailSection>

      <DetailSection title="History of this setting" description="Every change, newest first." fullWidth>
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
                    <span className="font-medium">
                      {row.level ? String(row.level.label) : 'Setting removed'}{row.threshold_percent ? ` — ${Number(row.threshold_percent)}%` : ''}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      {formatDateTime(row.effective_from)}{row.changed_by_user ? ` — ${row.changed_by_user.name}` : ''}{row.status === 'ACTIVE' ? ' · in force' : ''}
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
