import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
  OverReceiptMode,
  usePurchasingPolicyChangesIndex,
  usePurchasingSettingsShow,
  usePurchasingSettingsUpdate,
} from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Checkbox } from '../../components/ui/checkbox';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { PageHeader } from '../../components/page/page-header';
import { DetailField } from '../../components/detail/detail-field';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { Skeleton } from '../../components/ui/skeleton';
import { applyApiErrorsToForm, isApiError } from '../../lib/api-errors';
import { PolicyChangeHistory } from './policy-change-history';

const formSchema = z.object({
  over_receipt_mode: z.enum(OverReceiptMode),
  over_receipt_tolerance_percent: z.string(),
  purchase_order_required: z.boolean(),
  reason: z.string().trim().min(3, 'Explain why this policy is changing').max(1000),
});

type FormValues = z.infer<typeof formSchema>;

const FORM_FIELDS = ['over_receipt_mode', 'over_receipt_tolerance_percent', 'purchase_order_required', 'reason'] as const;

/** Over-receipt tolerance policy (ADR-governed, `purchasing.over-receipt-policy.manage`) — current values + audit trail. */
export function PurchasingSettingsScreen() {
  const settingsQuery = usePurchasingSettingsShow();
  const settings = settingsQuery.data?.status === 200 ? settingsQuery.data.data.data : undefined;
  // retry: false — a 403 (missing purchasing.policy-history.view) will never
  // succeed on retry, and the default retry-3 would otherwise leave the
  // "you don't have permission" state hidden behind loading skeletons for
  // several seconds.
  const changesQuery = usePurchasingPolicyChangesIndex(
    { sort: '-changed_at', per_page: 10 },
    { query: { retry: false } }
  );
  const changes = changesQuery.data?.status === 200 ? changesQuery.data.data.data : [];
  const changesForbidden = isApiError(changesQuery.error) && changesQuery.error.status === 403;
  const updateSettings = usePurchasingSettingsUpdate();
  const [formError, setFormError] = useState<string | null>(null);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      over_receipt_mode: OverReceiptMode.BLOCK,
      over_receipt_tolerance_percent: '0',
      purchase_order_required: true,
      reason: '',
    },
  });

  // Settings arrive after the form mounts — sync once loaded, not on every render.
  useEffect(() => {
    if (!settings) return;
    form.reset({
      over_receipt_mode: settings.over_receipt_mode as OverReceiptMode,
      over_receipt_tolerance_percent: settings.over_receipt_tolerance_percent,
      purchase_order_required: settings.purchase_order_required,
      reason: '',
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [settings]);

  const overReceiptMode = form.watch('over_receipt_mode');

  function handleSubmit(values: FormValues) {
    setFormError(null);
    updateSettings.mutate(
      { data: values },
      {
        onSuccess: () => {
          form.setValue('reason', '');
          changesQuery.refetch();
        },
        onError: (error) => setFormError(applyApiErrorsToForm(error, form.setError, FORM_FIELDS)),
      }
    );
  }

  if (settingsQuery.isLoading) {
    return (
      <div className="space-y-4 p-4 md:p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  return (
    <div className="space-y-4 p-4 md:p-6">
      <PageHeader
        title="Purchasing settings"
        description="Over-receipt tolerance policy — how much more than ordered may be received, and under what authority."
      />

      <DetailPageLayout>
        <Form {...form}>
          <form onSubmit={form.handleSubmit(handleSubmit)}>
            <DetailSection title="Over-receipt policy">
              {formError && (
                <div className="sm:col-span-2 rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
                  {formError}
                </div>
              )}
              <FormField
                control={form.control}
                name="over_receipt_mode"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Mode</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={OverReceiptMode.BLOCK}>Block — never receive more than ordered</SelectItem>
                        <SelectItem value={OverReceiptMode.ALLOW_WITHIN_TOLERANCE}>Allow within tolerance</SelectItem>
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
              {overReceiptMode === OverReceiptMode.ALLOW_WITHIN_TOLERANCE && (
                <FormField
                  control={form.control}
                  name="over_receipt_tolerance_percent"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Tolerance %</FormLabel>
                      <FormControl>
                        <Input {...field} inputMode="decimal" />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              )}
              <FormField
                control={form.control}
                name="purchase_order_required"
                render={({ field }) => (
                  <FormItem className="sm:col-span-2 space-y-1">
                    <div className="flex flex-row items-center gap-2">
                      <FormControl>
                        {/* Locked on: PO-less receiving is a deferred V1 capability (ADR 0043,
                            "Deferred capabilities") — every receipt already requires an issued
                            PO regardless of this flag, so leaving it editable would let someone
                            believe they'd turned that requirement off when nothing changed. */}
                        <Checkbox checked disabled />
                      </FormControl>
                      <FormLabel className="font-normal text-muted-foreground">
                        A purchase order is required before receiving
                      </FormLabel>
                    </div>
                    <p className="text-xs text-muted-foreground">
                      Always on for now — PO-less receiving isn't built yet (ADR 0043). This will
                      become configurable once that capability ships.
                    </p>
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="reason"
                render={({ field }) => (
                  <FormItem className="sm:col-span-2">
                    <FormLabel>Reason for this change</FormLabel>
                    <FormControl>
                      <Input {...field} placeholder="Why is this policy changing?" />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <div className="sm:col-span-2 flex justify-end">
                <Button type="submit" disabled={updateSettings.isPending}>
                  {updateSettings.isPending ? 'Saving…' : 'Save changes'}
                </Button>
              </div>
            </DetailSection>
          </form>
        </Form>

        <PolicyChangeHistory changes={changes} isLoading={changesQuery.isLoading} isForbidden={changesForbidden} />
      </DetailPageLayout>
    </div>
  );
}
