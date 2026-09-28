import { useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useWarehousesIndex } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Combobox } from '../../components/ui/combobox';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';

const physicalCountFormSchema = z.object({
  warehouse_id: z.string().min(1, 'Warehouse is required'),
  reference: z.string().max(255).optional(),
});

export type PhysicalCountFormValues = z.infer<typeof physicalCountFormSchema>;

export const PHYSICAL_COUNT_FORM_FIELDS = ['warehouse_id', 'reference'] as const satisfies readonly (keyof PhysicalCountFormValues)[];

const DEFAULT_VALUES: PhysicalCountFormValues = { warehouse_id: '', reference: '' };

/** Create-only — a physical count header is immutable once created (docs/domain/physical-count.md). */
export function PhysicalCountForm({
  onSubmit,
  isSubmitting,
  formError,
}: {
  onSubmit: (values: PhysicalCountFormValues, form: UseFormReturn<PhysicalCountFormValues>) => void;
  isSubmitting?: boolean;
  formError?: string | null;
}) {
  const form = useForm<PhysicalCountFormValues>({
    resolver: zodResolver(physicalCountFormSchema),
    defaultValues: DEFAULT_VALUES,
  });

  const warehousesQuery = useWarehousesIndex({ per_page: 100, sort: 'name', 'filter[active]': true });
  const warehouses = warehousesQuery.data?.status === 200 ? warehousesQuery.data.data.data : [];

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit((values) => onSubmit(values, form))} className="space-y-6">
        {formError && (
          <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
            {formError}
          </div>
        )}

        <DetailSection title="General">
          <FormField
            control={form.control}
            name="warehouse_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Warehouse</FormLabel>
                <FormControl>
                  <Combobox
                    ref={field.ref}
                    value={field.value}
                    onValueChange={field.onChange}
                    options={warehouses.map((warehouse) => ({ value: String(warehouse.id), label: warehouse.name }))}
                    placeholder="Select a warehouse"
                    searchPlaceholder="Search warehouses…"
                    emptyText="No warehouses found."
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="reference"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Reference</FormLabel>
                <FormControl>
                  <Input placeholder="Optional" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>

        <div className="flex justify-end">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Creating…' : 'Create count'}
          </Button>
        </div>
      </form>
    </Form>
  );
}
