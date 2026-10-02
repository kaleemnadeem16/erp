import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
  OverheadDriverType,
  OverheadPolicyStatus,
  OverheadScopeType,
  useOverheadPoliciesActivate,
  useOverheadPoliciesIndex,
  useOverheadPoliciesStore,
  type OverheadPolicyResource,
} from '@erp/core-api';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { Skeleton } from '../../components/ui/skeleton';
import { applyApiErrorsToForm, getApiErrorMessage, isApiError } from '../../lib/api-errors';

const DRIVER_LABELS: Record<string, string> = {
  PCT_MATERIAL: 'of material cost',
  PCT_LABOUR: 'of labour cost',
  PCT_DIRECT_COST: 'of direct cost',
  PER_LABOUR_HOUR: 'per labour hour',
  PER_WORK_UNIT: 'per work unit',
};

const PERCENTAGE_DRIVERS: string[] = [
  OverheadDriverType.PCT_MATERIAL,
  OverheadDriverType.PCT_LABOUR,
  OverheadDriverType.PCT_DIRECT_COST,
];

function driverLabel(driver: string): string {
  return DRIVER_LABELS[driver] ?? driver;
}

function policySummary(driver: string, rate?: string): string {
  if (rate == null) return 'Rate restricted';
  return PERCENTAGE_DRIVERS.includes(driver) ? `${rate}% ${driverLabel(driver)}` : `${rate} ${driverLabel(driver)}`;
}

const schema = z.object({
  driver_type: z.enum(OverheadDriverType),
  rate: z.string().refine((v) => /^\d{1,9}(\.\d{1,6})?$/.test(v) && Number(v) >= 0, 'Enter a rate of 0 or more'),
  reason: z.string().trim().min(3, 'Explain why this policy is being set').max(1000),
});

type FormValues = z.infer<typeof schema>;

const FORM_FIELDS = ['driver_type', 'rate', 'reason'] as const;

/**
 * Scope-bound overhead policy management — embedded on the Estimating
 * settings screen (COMPANY, scopeId null), an Assembly Group's edit screen
 * (ASSEMBLY_GROUP) and an Assembly's edit screen (ASSEMBLY). See ADR 0049's
 * scope hierarchy. Reading anything here (current policy, drafts, history)
 * needs `estimating.policy-history.view`; creating or activating needs
 * `estimating.overhead-policy.manage` — the same two-permission split
 * InventorySettingsScreen/PurchasingSettingsScreen use, surfaced here as a
 * 403 on whichever action was attempted rather than a pre-check, since this
 * frontend has no client-side permission-introspection mechanism yet.
 */
export function OverheadPolicyPanel({ scopeType, scopeId }: { scopeType: OverheadScopeType; scopeId: number | null }) {
  const [creating, setCreating] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const query = useOverheadPoliciesIndex(
    {
      'filter[scope_type]': scopeType,
      ...(scopeId !== null ? { 'filter[scope_id]': scopeId } : {}),
      sort: '-created_at',
      per_page: 50,
    },
    { query: { retry: false } }
  );
  const rows = query.data?.status === 200 ? query.data.data.data : [];
  const forbidden = isApiError(query.error) && query.error.status === 403;

  const active = rows.find((r) => r.status === OverheadPolicyStatus.ACTIVE);
  const drafts = rows.filter((r) => r.status === OverheadPolicyStatus.DRAFT);
  const history = rows.filter((r) => r.status === OverheadPolicyStatus.SUPERSEDED);

  const storeMutation = useOverheadPoliciesStore();
  const activateMutation = useOverheadPoliciesActivate();

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { driver_type: OverheadDriverType.PCT_DIRECT_COST, rate: '0', reason: '' },
  });

  function submitDraft(values: FormValues) {
    setActionError(null);
    storeMutation.mutate(
      { data: { scope_type: scopeType, scope_id: scopeId ?? undefined, ...values } },
      {
        onSuccess: () => {
          setCreating(false);
          form.reset({ driver_type: OverheadDriverType.PCT_DIRECT_COST, rate: '0', reason: '' });
          void query.refetch();
        },
        onError: (e) => setActionError(applyApiErrorsToForm(e, form.setError, FORM_FIELDS)),
      }
    );
  }

  function activate(policy: OverheadPolicyResource) {
    setActionError(null);
    activateMutation.mutate(
      { overheadPolicy: policy.id },
      {
        onSuccess: () => void query.refetch(),
        onError: (e) => setActionError(getApiErrorMessage(e) ?? 'Could not activate this policy.'),
      }
    );
  }

  if (query.isLoading) {
    return (
      <DetailSection title="Overhead policy">
        <div className="sm:col-span-2 space-y-2">
          <Skeleton className="h-12 w-full" />
        </div>
      </DetailSection>
    );
  }

  if (forbidden) {
    return (
      <DetailSection title="Overhead policy">
        <p className="sm:col-span-2 text-sm text-muted-foreground">
          You don't have permission to view overhead policies.
        </p>
      </DetailSection>
    );
  }

  return (
    <DetailSection
      title="Overhead policy"
      description="Resolved through Assembly → Assembly Group → Company → none (ADR 0049); at most one active policy per scope."
      fullWidth
    >
      <div className="sm:col-span-2 space-y-4">
        {actionError && (
          <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
            {actionError}
          </div>
        )}

        {active ? (
          <div className="rounded-md border p-3 text-sm">
            <div className="flex items-center justify-between gap-2">
              <span className="font-medium">{policySummary(active.driver_type, active.rate)}</span>
              <Badge variant="success">ACTIVE</Badge>
            </div>
            <p className="mt-1 text-xs text-muted-foreground">"{active.reason}"</p>
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">No policy set — overhead defaults to $0.</p>
        )}

        {drafts.length > 0 && (
          <div className="space-y-2">
            <div className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
              Draft — not yet active
            </div>
            {drafts.map((draft) => (
              <div key={draft.id} className="flex items-center justify-between gap-2 rounded-md border p-3 text-sm">
                <div>
                  <div className="font-medium">{policySummary(draft.driver_type, draft.rate)}</div>
                  <div className="text-xs text-muted-foreground">"{draft.reason}"</div>
                </div>
                <Button type="button" size="sm" onClick={() => activate(draft)} disabled={activateMutation.isPending}>
                  Activate
                </Button>
              </div>
            ))}
          </div>
        )}

        {creating ? (
          <Form {...form}>
            <form className="space-y-3 rounded-md border p-3" onSubmit={form.handleSubmit(submitDraft)}>
              <FormField
                control={form.control}
                name="driver_type"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Driver</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {Object.values(OverheadDriverType).map((driver) => (
                          <SelectItem key={driver} value={driver}>
                            {driverLabel(driver)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="rate"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Rate</FormLabel>
                    <FormControl>
                      <Input inputMode="decimal" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="reason"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Reason</FormLabel>
                    <FormControl>
                      <Input {...field} placeholder="Why is this policy being set?" />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <div className="flex justify-end gap-2">
                <Button type="button" variant="ghost" onClick={() => setCreating(false)}>
                  Cancel
                </Button>
                <Button type="submit" disabled={storeMutation.isPending}>
                  {storeMutation.isPending ? 'Saving…' : 'Save as draft'}
                </Button>
              </div>
            </form>
          </Form>
        ) : (
          <Button type="button" variant="outline" onClick={() => setCreating(true)}>
            Set a new policy
          </Button>
        )}

        {history.length > 0 && (
          <div className="space-y-2">
            <div className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">History</div>
            {history.map((row) => (
              <div key={row.id} className="rounded-md border p-3 text-xs text-muted-foreground">
                <div className="flex items-center justify-between gap-2">
                  <span>{policySummary(row.driver_type, row.rate)}</span>
                  <span>
                    {row.effective_from} → {row.effective_to}
                  </span>
                </div>
                <p className="mt-1">"{row.reason}"</p>
              </div>
            ))}
          </div>
        )}
      </div>
    </DetailSection>
  );
}
