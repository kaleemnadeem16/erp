import { useFieldArray, useFormContext, useWatch, type Control, type FieldPath, type FieldValues } from 'react-hook-form';
import { Trash2 } from 'lucide-react';
import { z } from 'zod';
import { useApplicationRatesIndex, useFormulationsIndex, useItemsIndex, useUomsIndex } from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Combobox } from '../../components/ui/combobox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';

const NONE = 'NONE';

export const assemblyComponentSchema = z.object({
  kind: z.enum(['MATERIAL', 'LABOUR', 'PLANT', 'SUBCONTRACT']),
  item_id: z.string(),
  formulation_id: z.string(),
  application_rate_id: z.string(),
  qty_per_work_unit: z
    .string()
    .refine((v) => /^\d{1,12}(\.\d{1,6})?$/.test(v) && Number(v) > 0, 'Enter a positive quantity with up to 6 decimal places'),
  uom_id: z.string().min(1, 'Unit is required'),
  wastage_pct: z.string(),
  multiplier: z.string(),
  rate_override: z.string(),
});

export type AssemblyComponentFormValues = z.infer<typeof assemblyComponentSchema>;

export function emptyAssemblyComponent(): AssemblyComponentFormValues {
  return {
    kind: 'MATERIAL', item_id: '', formulation_id: NONE, application_rate_id: NONE,
    qty_per_work_unit: '', uom_id: '', wastage_pct: '0', multiplier: '1', rate_override: '',
  };
}

/** docs/domain/assemblies.md#cost-roll-up: which fields apply depends on kind. */
export function assemblyComponentsPayload(components: AssemblyComponentFormValues[]) {
  return components.map((c, sequence) => {
    const base = {
      kind: c.kind,
      qty_per_work_unit: c.kind === 'SUBCONTRACT' ? '1' : c.qty_per_work_unit.trim(),
      uom_id: Number(c.uom_id),
      sequence,
    };

    if (c.kind === 'MATERIAL') {
      return {
        ...base,
        item_id: c.item_id ? Number(c.item_id) : undefined,
        formulation_id: c.formulation_id && c.formulation_id !== NONE ? Number(c.formulation_id) : undefined,
        application_rate_id: c.application_rate_id && c.application_rate_id !== NONE ? Number(c.application_rate_id) : undefined,
        wastage_pct: c.wastage_pct ? c.wastage_pct.trim() : '0',
        multiplier: c.multiplier ? c.multiplier.trim() : '1',
        rate_override: c.rate_override.trim() || undefined,
      };
    }

    if (c.kind === 'LABOUR' || c.kind === 'PLANT') {
      return {
        ...base,
        item_id: Number(c.item_id),
        rate_override: c.rate_override.trim() || undefined,
      };
    }

    // SUBCONTRACT — rate_override is the component's only value.
    return { ...base, rate_override: c.rate_override.trim() };
  });
}

export function AssemblyComponentsFields<TFieldValues extends FieldValues & { components: AssemblyComponentFormValues[] }>({
  control,
}: {
  control: Control<TFieldValues>;
}) {
  const { fields, append, remove } = useFieldArray({
    control: control as unknown as Control<{ components: AssemblyComponentFormValues[] }>,
    name: 'components',
  });
  const form = useFormContext<{ components: AssemblyComponentFormValues[] }>();
  const watched = useWatch({
    control: control as unknown as Control<{ components: AssemblyComponentFormValues[] }>,
    name: 'components',
  });

  const itemsResult = useItemsIndex({ per_page: 200, sort: 'name' });
  const items = itemsResult.data?.status === 200 ? itemsResult.data.data.data : [];
  const uomsResult = useUomsIndex({ per_page: 100, sort: 'name' });
  const uoms = uomsResult.data?.status === 200 ? uomsResult.data.data.data : [];
  const formulationsResult = useFormulationsIndex({ per_page: 100, sort: 'code' });
  const formulations = formulationsResult.data?.status === 200 ? formulationsResult.data.data.data : [];
  const applicationRatesResult = useApplicationRatesIndex({ per_page: 100 });
  const applicationRates = applicationRatesResult.data?.status === 200 ? applicationRatesResult.data.data.data : [];

  const materialItems = items.filter((i) => ['RAW', 'SEMI_FINISHED', 'FINISHED'].includes(i.type));
  const labourItems = items.filter((i) => i.type === 'LABOUR');
  const plantItems = items.filter((i) => i.type === 'PLANT');
  const uomOptions = uoms.map((u) => ({ value: String(u.id), label: `${u.name} (${u.code})` }));

  return (
    <div className="space-y-4">
      {fields.map((field, index) => {
        const kind = watched?.[index]?.kind ?? 'MATERIAL';
        const itemOptions = (kind === 'LABOUR' ? labourItems : kind === 'PLANT' ? plantItems : materialItems).map((i) => ({
          value: String(i.id),
          label: `${i.code} — ${i.name}`,
        }));

        return (
          <fieldset key={field.id} className="grid min-w-0 gap-3 rounded-lg border bg-panel/60 p-4 sm:grid-cols-2 lg:grid-cols-4 [&>*]:min-w-0">
            <legend className="px-1 text-sm font-medium">Component {index + 1}</legend>
            <FormField
              control={control}
              name={`components.${index}.kind` as FieldPath<TFieldValues>}
              render={({ field: kindField }) => (
                <FormItem>
                  <FormLabel>Kind</FormLabel>
                  <Select
                    value={kindField.value}
                    onValueChange={(value) => {
                      kindField.onChange(value);
                      form.setValue(`components.${index}.item_id`, '');
                      form.setValue(`components.${index}.rate_override`, '');
                    }}
                  >
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      <SelectItem value="MATERIAL">Material</SelectItem>
                      <SelectItem value="LABOUR">Labour</SelectItem>
                      <SelectItem value="PLANT">Plant</SelectItem>
                      <SelectItem value="SUBCONTRACT">Subcontract</SelectItem>
                    </SelectContent>
                  </Select>
                  <FormMessage />
                </FormItem>
              )}
            />

            {kind !== 'SUBCONTRACT' && (
              <FormField
                control={control}
                name={`components.${index}.item_id` as FieldPath<TFieldValues>}
                render={({ field: itemField }) => (
                  <FormItem>
                    <FormLabel>Item</FormLabel>
                    <FormControl>
                      <Combobox
                        ref={itemField.ref}
                        value={itemField.value}
                        onValueChange={(value) => {
                          itemField.onChange(value);
                          const item = items.find((candidate) => String(candidate.id) === value);
                          if (item) form.setValue(`components.${index}.uom_id`, String(item.stock_uom_id), { shouldDirty: true });
                        }}
                        options={kind === 'MATERIAL' ? [{ value: NONE, label: 'None — use application rate instead' }, ...itemOptions] : itemOptions}
                        placeholder="Select item"
                        searchPlaceholder="Search items…"
                        emptyText="No items found."
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            )}

            {kind === 'MATERIAL' && (
              <FormField
                control={control}
                name={`components.${index}.formulation_id` as FieldPath<TFieldValues>}
                render={({ field: formulationField }) => (
                  <FormItem>
                    <FormLabel>Priced via formulation</FormLabel>
                    <FormControl>
                      <Combobox
                        ref={formulationField.ref}
                        value={formulationField.value}
                        onValueChange={formulationField.onChange}
                        options={[
                          { value: NONE, label: 'No — price from stock (AVCO)' },
                          ...formulations.map((f) => ({ value: String(f.id), label: `${f.code} — ${f.name}` })),
                        ]}
                        searchPlaceholder="Search formulations…"
                        emptyText="No formulations found."
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            )}

            {kind === 'MATERIAL' && (
              <FormField
                control={control}
                name={`components.${index}.application_rate_id` as FieldPath<TFieldValues>}
                render={({ field: rateField }) => (
                  <FormItem>
                    <FormLabel>Or, live quantity from an application rate</FormLabel>
                    <FormControl>
                      <Combobox
                        ref={rateField.ref}
                        value={rateField.value}
                        onValueChange={rateField.onChange}
                        options={[
                          { value: NONE, label: 'No — use the quantity below' },
                          ...applicationRates.map((r) => ({ value: String(r.id), label: `#${r.id} — item ${r.item_id}, ${r.qty_per_work_unit}/unit` })),
                        ]}
                        searchPlaceholder="Search application rates…"
                        emptyText="No application rates found."
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            )}

            {kind !== 'SUBCONTRACT' && (
              <>
                <FormField
                  control={control}
                  name={`components.${index}.qty_per_work_unit` as FieldPath<TFieldValues>}
                  render={({ field: qtyField }) => (
                    <FormItem>
                      <FormLabel>{kind === 'MATERIAL' ? 'Quantity per work unit' : 'Hours per work unit'}</FormLabel>
                      <FormControl>
                        <Input inputMode="decimal" {...qtyField} value={qtyField.value ?? ''} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={control}
                  name={`components.${index}.uom_id` as FieldPath<TFieldValues>}
                  render={({ field: uomField }) => (
                    <FormItem>
                      <FormLabel>Unit</FormLabel>
                      <FormControl>
                        <Combobox
                          ref={uomField.ref}
                          value={uomField.value}
                          onValueChange={uomField.onChange}
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
              </>
            )}

            {kind === 'MATERIAL' && (
              <>
                <FormField
                  control={control}
                  name={`components.${index}.wastage_pct` as FieldPath<TFieldValues>}
                  render={({ field: wastageField }) => (
                    <FormItem>
                      <FormLabel>Wastage %</FormLabel>
                      <FormControl>
                        <Input inputMode="decimal" placeholder="0" {...wastageField} value={wastageField.value ?? ''} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={control}
                  name={`components.${index}.multiplier` as FieldPath<TFieldValues>}
                  render={({ field: multiplierField }) => (
                    <FormItem>
                      <FormLabel>Multiplier</FormLabel>
                      <FormControl>
                        <Input inputMode="decimal" placeholder="1" {...multiplierField} value={multiplierField.value ?? ''} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </>
            )}

            {kind === 'SUBCONTRACT' && (
              <FormField
                control={control}
                name={`components.${index}.uom_id` as FieldPath<TFieldValues>}
                render={({ field: uomField }) => (
                  <FormItem>
                    <FormLabel>Unit</FormLabel>
                    <FormControl>
                      <Combobox
                        ref={uomField.ref}
                        value={uomField.value}
                        onValueChange={(value) => {
                          uomField.onChange(value);
                          form.setValue(`components.${index}.qty_per_work_unit`, '1');
                        }}
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
            )}

            <FormField
              control={control}
              name={`components.${index}.rate_override` as FieldPath<TFieldValues>}
              render={({ field: rateOverrideField }) => (
                <FormItem>
                  <FormLabel>{kind === 'SUBCONTRACT' ? 'Rate (required)' : 'Rate override'}</FormLabel>
                  <FormControl>
                    <Input inputMode="decimal" placeholder={kind === 'SUBCONTRACT' ? '' : 'Resolved automatically'} {...rateOverrideField} value={rateOverrideField.value ?? ''} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <div className="flex items-end justify-end">
              <Button type="button" variant="outline" size="sm" onClick={() => remove(index)}>
                <Trash2 className="size-4" />
                Remove
              </Button>
            </div>
          </fieldset>
        );
      })}
      <Button type="button" variant="outline" onClick={() => append(emptyAssemblyComponent())}>
        Add component
      </Button>
    </div>
  );
}
