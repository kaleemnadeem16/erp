import { useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useItemsIndex, useSupplierAccountsIndex, useUomsIndex } from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Checkbox } from '../../components/ui/checkbox';
import { Input } from '../../components/ui/input';
import { Combobox } from '../../components/ui/combobox';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';
const NONE = 'NONE';
const schema = z.object({
  item_id: z.string().min(1, 'Item is required'),
  supplier_account_id: z.string().min(1, 'Supplier is required'),
  supplier_item_code: z.string().max(255),
  priority: z
    .string()
    .refine(
      (v) => !v || (/^\d+$/.test(v) && Number(v) >= 1 && Number(v) <= 32767),
      'Priority must be between 1 and 32767'
    ),
  lead_time_days: z
    .string()
    .refine((v) => !v || (/^\d+$/.test(v) && Number(v) <= 32767), 'Lead time must be between 0 and 32767'),
  minimum_order_qty: z
    .string()
    .refine(
      (v) => !v || (/^\d{1,15}(\.\d{1,4})?$/.test(v) && Number(v) > 0),
      'Enter a positive quantity with up to 4 decimal places'
    ),
  purchase_uom_id: z.string(),
  active: z.boolean(),
});
export type ItemSupplierFormValues = z.infer<typeof schema>;
export const ITEM_SUPPLIER_FORM_FIELDS = [
  'item_id',
  'supplier_account_id',
  'supplier_item_code',
  'priority',
  'lead_time_days',
  'minimum_order_qty',
  'purchase_uom_id',
  'active',
] as const satisfies readonly (keyof ItemSupplierFormValues)[];
export function itemSupplierPayload(v: ItemSupplierFormValues) {
  return {
    supplier_item_code: v.supplier_item_code.trim() || null,
    priority: v.priority ? Number(v.priority) : null,
    lead_time_days: v.lead_time_days ? Number(v.lead_time_days) : null,
    minimum_order_qty: v.minimum_order_qty ? v.minimum_order_qty.trim() : null,
    purchase_uom_id: v.purchase_uom_id === NONE ? null : Number(v.purchase_uom_id),
    active: v.active,
  };
}
export function ItemSupplierForm({
  mode,
  defaultValues,
  onSubmit,
  isSubmitting,
  formError,
}: {
  mode: 'create' | 'edit';
  defaultValues?: Partial<ItemSupplierFormValues>;
  onSubmit: (v: ItemSupplierFormValues, f: UseFormReturn<ItemSupplierFormValues>) => void;
  isSubmitting?: boolean;
  formError?: string | null;
}) {
  const form = useForm<ItemSupplierFormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      item_id: '',
      supplier_account_id: '',
      supplier_item_code: '',
      priority: '',
      lead_time_days: '',
      minimum_order_qty: '',
      purchase_uom_id: NONE,
      active: true,
      ...defaultValues,
    },
  });
  const itemsResult = useItemsIndex({ per_page: 100, sort: 'name' });
  const items = itemsResult.data?.status === 200 ? itemsResult.data.data.data : [];
  const suppliersResult = useSupplierAccountsIndex({ per_page: 100, sort: 'code', include: 'party' });
  const suppliers = suppliersResult.data?.status === 200 ? suppliersResult.data.data.data : [];
  const uomsResult = useUomsIndex({ per_page: 100, sort: 'name' });
  const uoms = uomsResult.data?.status === 200 ? uomsResult.data.data.data : [];
  const select = (name: 'item_id' | 'supplier_account_id', label: string, options: { id: number; label: string }[]) => (
    <FormField
      control={form.control}
      name={name}
      render={({ field }) => (
        <FormItem>
          <FormLabel>{label}</FormLabel>
          <FormControl>
            <Combobox
              ref={field.ref}
              disabled={mode === 'edit'}
              value={field.value}
              onValueChange={field.onChange}
              options={options.map((o) => ({ value: String(o.id), label: o.label }))}
              placeholder={`Select ${label.toLowerCase()}`}
              searchPlaceholder={`Search ${label.toLowerCase()}s…`}
              emptyText={`No ${label.toLowerCase()}s found.`}
            />
          </FormControl>
          <FormMessage />
        </FormItem>
      )}
    />
  );
  return (
    <Form {...form}>
      <form className="space-y-6" onSubmit={form.handleSubmit((v) => onSubmit(v, form))}>
        {formError && (
          <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
            {formError}
          </div>
        )}
        <DetailSection
          title="Relationship"
          description={
            mode === 'edit'
              ? 'Item and supplier are immutable; archive and create a new relationship to change either.'
              : undefined
          }
        >
          {select(
            'item_id',
            'Item',
            items.map((i) => ({ id: i.id, label: `${i.code} — ${i.name}` }))
          )}
          {select(
            'supplier_account_id',
            'Supplier',
            suppliers.map((s) => ({ id: s.id, label: `${s.code}${s.party?.name ? ` — ${s.party.name}` : ''}` }))
          )}
          <FormField
            control={form.control}
            name="supplier_item_code"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Supplier item code</FormLabel>
                <FormControl>
                  <Input {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="priority"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Priority</FormLabel>
                <FormControl>
                  <Input type="number" min="1" max="32767" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>
        <DetailSection title="Sourcing terms">
          <FormField
            control={form.control}
            name="lead_time_days"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Lead time override (days)</FormLabel>
                <FormControl>
                  <Input type="number" min="0" max="32767" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="minimum_order_qty"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Minimum order quantity</FormLabel>
                <FormControl>
                  <Input inputMode="decimal" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="purchase_uom_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Purchase unit</FormLabel>
                <FormControl>
                  <Combobox
                    ref={field.ref}
                    value={field.value}
                    onValueChange={field.onChange}
                    options={[
                      { value: NONE, label: 'Use stock unit' },
                      ...uoms.map((u) => ({ value: String(u.id), label: `${u.name} (${u.code})` })),
                    ]}
                    searchPlaceholder="Search units…"
                    emptyText="No units found."
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="active"
            render={({ field }) => (
              <FormItem className="flex items-center gap-3 space-y-0">
                <FormControl>
                  <Checkbox checked={field.value} onCheckedChange={(v) => field.onChange(!!v)} />
                </FormControl>
                <FormLabel>Active</FormLabel>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>
        <div className="flex justify-end">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Saving…' : 'Save relationship'}
          </Button>
        </div>
      </form>
    </Form>
  );
}
