import { useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useItemsIndex, useRateDimensionsIndex, useUomsIndex } from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Checkbox } from '../../components/ui/checkbox';
import { Input } from '../../components/ui/input';
import { Combobox } from '../../components/ui/combobox';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { lookupState } from '../../lib/api-errors';

const NONE = 'NONE';

const schema = z.object({
  item_id: z.string().min(1, 'Item is required'),
  work_uom_id: z.string().min(1, 'Work unit is required'),
  qty_per_work_unit: z
    .string()
    .refine(
      (v) => /^\d{1,12}(\.\d{1,6})?$/.test(v) && Number(v) > 0,
      'Enter a positive quantity with up to 6 decimal places'
    ),
  uom_id: z.string().min(1, 'Consumption unit is required'),
  is_default: z.boolean(),
  dimension_values: z.record(z.string(), z.string()),
});

export type ApplicationRateFormValues = z.infer<typeof schema>;

export const APPLICATION_RATE_FORM_FIELDS = [
  'item_id',
  'work_uom_id',
  'qty_per_work_unit',
  'uom_id',
  'is_default',
  'dimension_values',
] as const satisfies readonly (keyof ApplicationRateFormValues)[];

/** Excludes item_id/work_uom_id — an application rate cannot be repointed once created. */
export function applicationRatePayload(v: ApplicationRateFormValues) {
  return {
    qty_per_work_unit: v.qty_per_work_unit.trim(),
    uom_id: Number(v.uom_id),
    is_default: v.is_default,
    dimension_value_ids: Object.values(v.dimension_values)
      .filter((value) => value !== NONE)
      .map(Number),
  };
}

export function ApplicationRateForm({
  mode,
  defaultValues,
  onSubmit,
  isSubmitting,
  formError,
}: {
  mode: 'create' | 'edit';
  defaultValues?: Partial<ApplicationRateFormValues>;
  onSubmit: (v: ApplicationRateFormValues, f: UseFormReturn<ApplicationRateFormValues>) => void;
  isSubmitting?: boolean;
  formError?: string | null;
}) {
  const form = useForm<ApplicationRateFormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      item_id: '',
      work_uom_id: '',
      qty_per_work_unit: '',
      uom_id: '',
      is_default: false,
      dimension_values: {},
      ...defaultValues,
    },
  });
  const itemsResult = useItemsIndex({ per_page: 100, sort: 'name' });
  const items = itemsResult.data?.status === 200 ? itemsResult.data.data.data : [];
  const uomsResult = useUomsIndex({ per_page: 100, sort: 'name' });
  const uoms = uomsResult.data?.status === 200 ? uomsResult.data.data.data : [];
  const dimensionsResult = useRateDimensionsIndex({ per_page: 100, sort: 'sort_order', include: 'values' });
  const dimensions = dimensionsResult.data?.status === 200 ? dimensionsResult.data.data.data : [];
  const uomOptions = uoms.map((u) => ({ value: String(u.id), label: `${u.name} (${u.code})` }));

  return (
    <Form {...form}>
      <form className="space-y-6" onSubmit={form.handleSubmit((v) => onSubmit(v, form))}>
        {formError && (
          <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
            {formError}
          </div>
        )}
        <DetailSection
          title="Rate"
          description={
            mode === 'edit'
              ? 'Item and work unit are immutable; archive and create a new rate to change either.'
              : 'How much of the item is consumed per one unit of work.'
          }
        >
          <FormField
            control={form.control}
            name="item_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Item</FormLabel>
                <FormControl>
                  <Combobox {...lookupState(itemsResult, 'items')}
                    ref={field.ref}
                    disabled={mode === 'edit'}
                    value={field.value}
                    onValueChange={field.onChange}
                    options={items.map((i) => ({ value: String(i.id), label: `${i.code} — ${i.name}` }))}
                    placeholder="Select item"
                    searchPlaceholder="Search items…"
                    emptyText="No items found."
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="work_uom_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Work unit</FormLabel>
                <FormControl>
                  <Combobox {...lookupState(uomsResult, 'units')}
                    ref={field.ref}
                    disabled={mode === 'edit'}
                    value={field.value}
                    onValueChange={field.onChange}
                    options={uomOptions}
                    placeholder="Select work unit"
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
            name="qty_per_work_unit"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Quantity consumed per work unit</FormLabel>
                <FormControl>
                  <Input inputMode="decimal" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="uom_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Consumption unit</FormLabel>
                <FormControl>
                  <Combobox {...lookupState(uomsResult, 'units')}
                    ref={field.ref}
                    value={field.value}
                    onValueChange={field.onChange}
                    options={uomOptions}
                    placeholder="Select unit"
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
            name="is_default"
            render={({ field }) => (
              <FormItem className="flex items-center gap-3 space-y-0">
                <FormControl>
                  <Checkbox checked={field.value} onCheckedChange={(v) => field.onChange(!!v)} />
                </FormControl>
                <FormLabel>Default — fallback when no combination of dimension values matches</FormLabel>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>
        {dimensions.length > 0 && (
          <DetailSection
            title="Dimension values"
            description="Which combination this rate applies to. Leave a dimension not tagged to leave it out of this rate's combination."
          >
            {dimensions.map((dimension) => (
              <FormField
                key={dimension.id}
                control={form.control}
                name={`dimension_values.${dimension.id}`}
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>
                      {dimension.name}
                      {dimension.is_required && <span className="text-muted-foreground"> (required axis)</span>}
                    </FormLabel>
                    <FormControl>
                      <Combobox {...lookupState(dimensionsResult, 'rate dimensions')}
                        ref={field.ref}
                        value={field.value ?? NONE}
                        onValueChange={field.onChange}
                        options={[
                          { value: NONE, label: 'Not tagged' },
                          ...(dimension.values ?? []).map((value) => ({
                            value: String(value.id),
                            label: value.name,
                          })),
                        ]}
                        searchPlaceholder={`Search ${dimension.name.toLowerCase()}…`}
                        emptyText="No values defined."
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            ))}
          </DetailSection>
        )}
        <div className="flex justify-end">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Saving…' : mode === 'create' ? 'Create rate' : 'Save rate'}
          </Button>
        </div>
      </form>
    </Form>
  );
}
