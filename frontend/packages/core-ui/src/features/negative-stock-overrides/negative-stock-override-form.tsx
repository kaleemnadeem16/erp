import { useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useItemsIndex, useLocationsIndex, NegativeStockMovementClass, NegativeStockReasonCode } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Textarea } from '../../components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';

const negativeStockOverrideFormSchema = z.object({
  item_id: z.string().min(1, 'Item is required'),
  location_id: z.string().min(1, 'Location is required'),
  movement_class: z.string().min(1, 'Movement class is required'),
  requested_qty: z.string().min(1, 'Requested quantity is required'),
  source_type: z.string().min(1, 'Source type is required').max(255),
  source_id: z.string().min(1, 'Source ID is required'),
  reason_code: z.string().min(1, 'Reason is required'),
  explanation: z.string().min(1, 'An explanation is required'),
});

export type NegativeStockOverrideFormValues = z.infer<typeof negativeStockOverrideFormSchema>;

export const NEGATIVE_STOCK_OVERRIDE_FORM_FIELDS = [
  'item_id', 'location_id', 'movement_class', 'requested_qty',
  'source_type', 'source_id', 'reason_code', 'explanation',
] as const satisfies readonly (keyof NegativeStockOverrideFormValues)[];

const DEFAULT_VALUES: NegativeStockOverrideFormValues = {
  item_id: '', location_id: '', movement_class: '', requested_qty: '',
  source_type: '', source_id: '', reason_code: '', explanation: '',
};

/**
 * Create-only — a negative-stock override request is immutable once made
 * (docs/domain/negative-stock.md). source_type/source_id tie this request
 * to whatever originated the anticipated shortage (a despatch, a job
 * issue, ...) — there's no picker for that since it isn't a resource this
 * app exposes yet, so it's a plain reference the requester fills in.
 */
export function NegativeStockOverrideForm({
  onSubmit,
  isSubmitting,
  formError,
}: {
  onSubmit: (values: NegativeStockOverrideFormValues, form: UseFormReturn<NegativeStockOverrideFormValues>) => void;
  isSubmitting?: boolean;
  formError?: string | null;
}) {
  const form = useForm<NegativeStockOverrideFormValues>({
    resolver: zodResolver(negativeStockOverrideFormSchema),
    defaultValues: DEFAULT_VALUES,
  });

  const itemsQuery = useItemsIndex({ per_page: 100, sort: 'name', 'filter[status]': 'ACTIVE' });
  const items = itemsQuery.data?.status === 200 ? itemsQuery.data.data.data : [];
  const locationsQuery = useLocationsIndex({ per_page: 100, sort: 'code' });
  const locations = locationsQuery.data?.status === 200 ? locationsQuery.data.data.data : [];

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit((values) => onSubmit(values, form))} className="space-y-6">
        {formError && (
          <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{formError}</div>
        )}

        <DetailSection title="Shortage">
          <FormField
            control={form.control}
            name="item_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Item</FormLabel>
                <Select value={field.value} onValueChange={field.onChange}>
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue placeholder="Select an item" />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    {items.map((item) => (
                      <SelectItem key={item.id} value={String(item.id)}>
                        {item.code} — {item.name}
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
            name="location_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Location</FormLabel>
                <Select value={field.value} onValueChange={field.onChange}>
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue placeholder="Select a location" />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    {locations.map((location) => (
                      <SelectItem key={location.id} value={String(location.id)}>
                        {location.code}
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
            name="requested_qty"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Requested quantity</FormLabel>
                <FormControl>
                  <Input type="number" step="0.0001" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="movement_class"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Movement class</FormLabel>
                <Select value={field.value} onValueChange={field.onChange}>
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue placeholder="Select a movement class" />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    {Object.values(NegativeStockMovementClass).map((value) => (
                      <SelectItem key={value} value={value}>
                        {value}
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
            name="reason_code"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Reason</FormLabel>
                <Select value={field.value} onValueChange={field.onChange}>
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue placeholder="Select a reason" />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    {Object.values(NegativeStockReasonCode).map((value) => (
                      <SelectItem key={value} value={value}>
                        {value}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>

        <DetailSection title="Source" description="What originated this anticipated shortage — a despatch, a job material issue, and so on.">
          <FormField
            control={form.control}
            name="source_type"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Source type</FormLabel>
                <FormControl>
                  <Input placeholder="SALES_DESPATCH" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="source_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Source ID</FormLabel>
                <FormControl>
                  <Input type="number" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="explanation"
            render={({ field }) => (
              <FormItem className="sm:col-span-2">
                <FormLabel>Explanation</FormLabel>
                <FormControl>
                  <Textarea {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>

        <div className="flex justify-end">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Requesting…' : 'Request override'}
          </Button>
        </div>
      </form>
    </Form>
  );
}
