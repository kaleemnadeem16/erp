import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Trash2 } from 'lucide-react';
import { useResourceRatesDestroy, useResourceRatesShow, useResourceRatesStore, type ResourceRateResource } from '@erp/core-api';

import { ConfirmActionDialog } from '../../components/confirm-action-dialog';
import { DetailField } from '../../components/detail/detail-field';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { ItemCombobox, UomCombobox } from '../../components/lookups/entity-comboboxes';
import { RecordPresenter } from '../../components/page/record-presenter';
import { Button } from '../../components/ui/button';
import { DateField } from '../../components/ui/date-field';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { Input } from '../../components/ui/input';
import { SectionStack } from '../../components/ui/section-stack';
import { Skeleton } from '../../components/ui/skeleton';
import { usePermission } from '../../hooks/use-permission';
import { applyApiErrorsToForm, isApiError } from '../../lib/api-errors';
import { formatDate } from '../../lib/format-date';
import type { Presentation } from '../../lib/presentation';

const LIST = '/resource-rates';

const schema = z.object({
  item_id: z.string().min(1, 'Item is required'),
  rate: z.string().refine((v) => /^\d{1,9}(\.\d{1,6})?$/.test(v) && Number(v) >= 0, 'Enter a rate of 0 or more'),
  rate_uom_id: z.string().min(1, 'Unit is required'),
  effective_from: z.string().min(1, 'Effective date is required'),
});
type FormValues = z.infer<typeof schema>;
const FORM_FIELDS = ['item_id', 'rate', 'rate_uom_id', 'effective_from'] as const;

/** Add a rate (`/resource-rates/new`) or read one (`/resource-rates/:id`). A rate has no update endpoint — a correction is a new dated row — so the record only offers Delete. */
export function ResourceRateFormScreen({ presentation = 'sheet' }: { presentation?: Presentation } = {}) {
  const { id } = useParams<{ id: string }>();
  const rateId = Number(id);
  const query = useResourceRatesShow(rateId, { include: 'item,rateUom' }, { query: { enabled: Boolean(id) && Number.isFinite(rateId) && rateId > 0 } });
  const rate = query.data?.status === 200 ? query.data.data.data : undefined;

  if (!id) return <ResourceRateCreate presentation={presentation} />;
  if (!rate) {
    const unavailable = !isApiError(query.error) || query.error.status === 404 || query.error.status === 403;

    return (
      <RecordPresenter presentation={presentation} title="Resource rate" listPath={LIST} listLabel="Resource rates" size="lg">
        {query.isLoading ? (
          <Skeleton className="h-40 w-full" />
        ) : (
          <p role="alert" className="text-sm text-destructive">
            {unavailable ? "This resource rate isn't available." : "Couldn't load this resource rate."}
          </p>
        )}
      </RecordPresenter>
    );
  }

  return <ResourceRateRecord rate={rate} presentation={presentation} />;
}

function ResourceRateCreate({ presentation }: { presentation: Presentation }) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const store = useResourceRatesStore();
  const [formError, setFormError] = useState<string | null>(null);
  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { item_id: '', rate: '', rate_uom_id: '', effective_from: new Date().toISOString().slice(0, 10) },
  });
  const back = () => navigate({ pathname: LIST, search: window.location.search });

  async function submit(values: FormValues) {
    setFormError(null);
    try {
      await store.mutateAsync({ data: { item_id: Number(values.item_id), rate: values.rate.trim(), rate_uom_id: Number(values.rate_uom_id), effective_from: values.effective_from } });
      await queryClient.invalidateQueries();
      back();
    } catch (failure) {
      setFormError(applyApiErrorsToForm(failure, form.setError, FORM_FIELDS));
    }
  }

  return (
    <RecordPresenter
      presentation={presentation}
      title="New resource rate"
      description="The standard rate for a labour or plant item from a date. To correct a rate, add a newer one."
      listPath={LIST}
      listLabel="Resource rates"
      size="lg"
      form
    >
      <Form {...form}>
        <form onSubmit={form.handleSubmit(submit)} noValidate className="space-y-6" aria-label="New resource rate">
          <SectionStack>
            <DetailSection title="Rate">
              <FormField
                control={form.control}
                name="item_id"
                render={({ field }) => (
                  <FormItem className="sm:col-span-2">
                    <FormLabel>Item</FormLabel>
                    <FormControl>
                      <ItemCombobox label="Item" placeholder="Select a labour or plant item" types={['LABOUR', 'PLANT']} className="h-11 w-full md:h-10" value={field.value} onValueChange={field.onChange} />
                    </FormControl>
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
                      <Input inputMode="decimal" autoComplete="off" className="h-11 md:h-10" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="rate_uom_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Per unit</FormLabel>
                    <FormControl>
                      <UomCombobox label="Per unit" placeholder="Select a unit" className="h-11 w-full md:h-10" value={field.value} onValueChange={field.onChange} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="effective_from"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Effective from</FormLabel>
                    <FormControl>
                      <DateField aria-label="Effective from" className="h-11 w-full md:h-10" value={field.value} onChange={field.onChange} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </DetailSection>
          </SectionStack>

          {formError && (
            <p role="alert" className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive">
              {formError}
            </p>
          )}

          <div className="sticky bottom-0 -mx-4 flex items-center justify-end gap-2 border-t bg-background px-4 py-3">
            <Button type="button" variant="outline" className="h-11 md:h-9" disabled={store.isPending} onClick={back}>
              Cancel
            </Button>
            <Button type="submit" className="h-11 md:h-9" disabled={store.isPending}>
              {store.isPending ? 'Saving…' : 'Add rate'}
            </Button>
          </div>
        </form>
      </Form>
    </RecordPresenter>
  );
}

function ResourceRateRecord({ rate, presentation }: { rate: ResourceRateResource; presentation: Presentation }) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const mayManage = usePermission('estimating.resource-rate.manage');
  const destroy = useResourceRatesDestroy();
  const [confirmDelete, setConfirmDelete] = useState(false);
  const item = rate.item ? `${rate.item.code} — ${rate.item.name}` : `Item #${rate.item_id}`;

  return (
    <RecordPresenter
      presentation={presentation}
      title={item}
      description={`Effective from ${formatDate(rate.effective_from) ?? rate.effective_from}`}
      actions={
        mayManage ? (
          <Button type="button" variant="outline" size="sm" className="h-11 md:h-8" onClick={() => setConfirmDelete(true)}>
            <Trash2 aria-hidden="true" className="mr-1.5 h-4 w-4" />
            Delete
          </Button>
        ) : undefined
      }
      listPath={LIST}
      listLabel="Resource rates"
      size="lg"
    >
      <SectionStack>
        <DetailSection title="Rate">
          <DetailField label="Item">{item}</DetailField>
          <DetailField label="Rate">{rate.rate == null ? 'Restricted' : `${rate.rate} per ${rate.rate_uom?.name ?? rate.rate_uom_id}`}</DetailField>
          <DetailField label="Effective from">{formatDate(rate.effective_from) ?? rate.effective_from}</DetailField>
        </DetailSection>
      </SectionStack>
      <p className="text-xs text-muted-foreground">A rate cannot be edited. To correct it, add a newer rate for the same item; costing uses the latest one dated on or before the costing date.</p>

      <ConfirmActionDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title="Delete this resource rate?"
        description="The rate is removed and cannot be brought back. Costing from now on uses the most recent rate dated on or before the costing date; if this was the only one, that item has no rate until another is entered."
        confirmLabel="Delete rate"
        pendingLabel="Deleting…"
        failureMessage="The rate could not be deleted."
        testId="delete-resource-rate-dialog"
        onConfirm={async () => {
          await destroy.mutateAsync({ resourceRate: rate.id });
          await queryClient.invalidateQueries();
          navigate({ pathname: LIST, search: window.location.search });
        }}
      />
    </RecordPresenter>
  );
}
