import { useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useItemsIndex, useLocationsIndex, RecalculationMovementKind } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';

const recalculationRequestFormSchema = z.object({
  item_id: z.string().min(1, 'Item is required'),
  location_id: z.string().min(1, 'Location is required'),
  movement_kind: z.string().min(1, 'Movement kind is required'),
  destination_location_id: z.string().optional(),
  qty: z.string().min(1, 'Quantity is required'),
  unit_cost: z.string().optional(),
  base_unit_cost: z.string().optional(),
  currency_id: z.string().optional(),
  fx_rate: z.string().optional(),
  batch_no: z.string().optional(),
  serial_no: z.string().optional(),
  moved_at: z.string().min(1, 'The true historical date is required'),
  source_type: z.string().min(1, 'Source type is required').max(255),
  source_id: z.string().min(1, 'Source ID is required'),
});

export type RecalculationRequestFormValues = z.infer<typeof recalculationRequestFormSchema>;

export const RECALCULATION_REQUEST_FORM_FIELDS = [
  'item_id', 'location_id', 'movement_kind', 'destination_location_id', 'qty', 'unit_cost',
  'base_unit_cost', 'currency_id', 'fx_rate', 'batch_no', 'serial_no', 'moved_at', 'source_type', 'source_id',
] as const satisfies readonly (keyof RecalculationRequestFormValues)[];

const DEFAULT_VALUES: RecalculationRequestFormValues = {
  item_id: '', location_id: '', movement_kind: RecalculationMovementKind.RECEIPT, destination_location_id: '',
  qty: '', unit_cost: '', base_unit_cost: '', currency_id: '', fx_rate: '', batch_no: '', serial_no: '',
  moved_at: '', source_type: '', source_id: '',
};

/**
 * Create-only — a recalculation request is immutable once made
 * (docs/domain/stock-recalculation.md). A RECEIPT pins historical cost/FX
 * facts the caller supplies; ISSUE/TRANSFER costs are derived from replayed
 * history instead, so those fields only show for RECEIPT. Currency has no
 * picker resource in this frontend yet, so it's a raw numeric ID like
 * source_type/source_id — same precedent as inventory cutover's Import
 * Batch ID and negative-stock overrides' responsible-owner field.
 */
export function RecalculationRequestForm({
  onSubmit,
  isSubmitting,
  formError,
}: {
  onSubmit: (values: RecalculationRequestFormValues, form: UseFormReturn<RecalculationRequestFormValues>) => void;
  isSubmitting?: boolean;
  formError?: string | null;
}) {
  const form = useForm<RecalculationRequestFormValues>({
    resolver: zodResolver(recalculationRequestFormSchema),
    defaultValues: DEFAULT_VALUES,
  });
  const movementKind = form.watch('movement_kind');

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

        <DetailSection title="Movement">
          <FormField
            control={form.control}
            name="movement_kind"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Movement kind</FormLabel>
                <Select value={field.value} onValueChange={field.onChange}>
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    <SelectItem value={RecalculationMovementKind.RECEIPT}>Receipt</SelectItem>
                    <SelectItem value={RecalculationMovementKind.ISSUE}>Issue</SelectItem>
                    <SelectItem value={RecalculationMovementKind.TRANSFER}>Transfer</SelectItem>
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>
            )}
          />
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
          {movementKind === RecalculationMovementKind.TRANSFER && (
            <FormField
              control={form.control}
              name="destination_location_id"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Destination location</FormLabel>
                  <Select value={field.value} onValueChange={field.onChange}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue placeholder="Select a destination" />
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
          )}
          <FormField
            control={form.control}
            name="qty"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Quantity</FormLabel>
                <FormControl>
                  <Input type="number" step="0.0001" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="moved_at"
            render={({ field }) => (
              <FormItem>
                <FormLabel>True historical date</FormLabel>
                <FormControl>
                  <Input type="datetime-local" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="batch_no"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Batch (optional)</FormLabel>
                <FormControl>
                  <Input {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="serial_no"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Serial (optional)</FormLabel>
                <FormControl>
                  <Input {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>

        {movementKind === RecalculationMovementKind.RECEIPT && (
          <DetailSection title="Pinned historical cost" description="A receipt's cost and FX are historical facts you're asserting, not derived.">
            <FormField
              control={form.control}
              name="unit_cost"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Unit cost</FormLabel>
                  <FormControl>
                    <Input type="number" step="0.0001" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="base_unit_cost"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Base unit cost</FormLabel>
                  <FormControl>
                    <Input type="number" step="0.0001" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="currency_id"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Currency ID</FormLabel>
                  <FormControl>
                    <Input type="number" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="fx_rate"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>FX rate</FormLabel>
                  <FormControl>
                    <Input type="number" step="0.000000001" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          </DetailSection>
        )}

        <DetailSection title="Source" description="What originated this correction — the receipt/issue/transfer record it represents.">
          <FormField
            control={form.control}
            name="source_type"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Source type</FormLabel>
                <FormControl>
                  <Input placeholder="GOODS_RECEIPT_LINE" {...field} />
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
        </DetailSection>

        <div className="flex justify-end">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Requesting…' : 'Request recalculation'}
          </Button>
        </div>
      </form>
    </Form>
  );
}
