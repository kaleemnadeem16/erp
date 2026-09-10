import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
  AvcoPoolScope,
  InventoryLocationMode,
  useInventoryPolicyChangesIndex,
  useInventorySettingsShow,
  useInventorySettingsUpdate,
} from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { PageHeader } from '../../components/page/page-header';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { Skeleton } from '../../components/ui/skeleton';
import { applyApiErrorsToForm, isApiError } from '../../lib/api-errors';
import { PolicyChangeHistory } from './policy-change-history';

const formSchema = z.object({
  inventory_location_mode: z.enum(InventoryLocationMode),
  default_avco_pool_scope: z.enum(AvcoPoolScope),
  reason: z.string().trim().min(3, 'Explain why this policy is changing').max(1000),
});

type FormValues = z.infer<typeof formSchema>;

const FORM_FIELDS = ['inventory_location_mode', 'default_avco_pool_scope', 'reason'] as const;

/**
 * Location structure and default AVCO pool scope (ADR 0047, closes Q17) —
 * current values + audit trail. The other policy groups on this same
 * settings resource (negative-stock, backdating, recalculation) belong to
 * earlier ADRs and their own screens; this one is scoped to what ADR 0047
 * actually governs.
 */
export function InventorySettingsScreen() {
  const settingsQuery = useInventorySettingsShow();
  const settings = settingsQuery.data?.status === 200 ? settingsQuery.data.data.data : undefined;
  // retry: false — a 403 (missing inventory.policy-history.view) will never
  // succeed on retry, and the default retry-3 would otherwise leave the
  // "you don't have permission" state hidden behind loading skeletons for
  // several seconds.
  const changesQuery = useInventoryPolicyChangesIndex(
    { sort: '-changed_at', per_page: 25 },
    { query: { retry: false } }
  );
  const allChanges = changesQuery.data?.status === 200 ? changesQuery.data.data.data : [];
  const changes = allChanges.filter((change) => change.policy === 'LOCATION_STRUCTURE' || change.policy === 'DEFAULT_AVCO_POOL_SCOPE');
  const changesForbidden = isApiError(changesQuery.error) && changesQuery.error.status === 403;
  const updateSettings = useInventorySettingsUpdate();
  const [formError, setFormError] = useState<string | null>(null);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      inventory_location_mode: InventoryLocationMode.SIMPLE,
      default_avco_pool_scope: AvcoPoolScope.COMPANY_ITEM,
      reason: '',
    },
  });

  useEffect(() => {
    if (!settings) return;
    form.reset({
      inventory_location_mode: settings.inventory_location_mode as InventoryLocationMode,
      default_avco_pool_scope: settings.default_avco_pool_scope as AvcoPoolScope,
      reason: '',
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [settings]);

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
        title="Inventory settings"
        description="Location structure and default AVCO pool scope — governed defaults for how stock is organised and valued."
      />

      <DetailPageLayout>
        <Form {...form}>
          <form onSubmit={form.handleSubmit(handleSubmit)}>
            <DetailSection title="Location & valuation defaults">
              {formError && (
                <div className="sm:col-span-2 rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
                  {formError}
                </div>
              )}
              <FormField
                control={form.control}
                name="inventory_location_mode"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Location mode</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={InventoryLocationMode.SIMPLE}>Simple — one location per warehouse</SelectItem>
                        <SelectItem value={InventoryLocationMode.STRUCTURED}>Structured — location hierarchy</SelectItem>
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="default_avco_pool_scope"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Default AVCO pool scope</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={AvcoPoolScope.COMPANY_ITEM}>Company + item</SelectItem>
                        <SelectItem value={AvcoPoolScope.WAREHOUSE_ITEM}>Warehouse + item</SelectItem>
                        <SelectItem value={AvcoPoolScope.COMPANY_ITEM_BATCH}>Company + item + batch</SelectItem>
                      </SelectContent>
                    </Select>
                    <FormMessage />
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
