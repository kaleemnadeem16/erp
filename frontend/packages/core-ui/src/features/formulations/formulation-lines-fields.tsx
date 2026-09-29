import { useFieldArray, useFormContext, useWatch, type Control, type FieldPath, type FieldValues } from 'react-hook-form';
import { Trash2 } from 'lucide-react';
import { z } from 'zod';
import { useItemsIndex, useUomsIndex } from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Combobox } from '../../components/ui/combobox';
import { FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { lookupState } from '../../lib/api-errors';

export const formulationLineSchema = z.object({
  input_item_id: z.string().min(1, 'Item is required'),
  qty: z.string().refine((v) => /^\d{1,12}(\.\d{1,6})?$/.test(v) && Number(v) > 0, 'Enter a positive quantity with up to 6 decimal places'),
  uom_id: z.string().min(1, 'Unit is required'),
  wastage_pct: z.string().refine((v) => !v || (/^\d{1,4}(\.\d{1,4})?$/.test(v) && Number(v) >= 0), 'Enter a wastage percentage of 0 or more')
});

export type FormulationLineFormValues = z.infer<typeof formulationLineSchema>;

export function emptyFormulationLine(): FormulationLineFormValues {
  return { input_item_id: '', qty: '', uom_id: '', wastage_pct: '' };
}

export function formulationLinesPayload(lines: FormulationLineFormValues[]) {
  return lines.map((line, sequence) => ({
    input_item_id: Number(line.input_item_id),
    qty: line.qty.trim(),
    uom_id: Number(line.uom_id),
    wastage_pct: line.wastage_pct ? line.wastage_pct.trim() : '0',
    sequence
  }));
}

/**
 * Shared repeatable-rows editor for a formulation version's lines — used by
 * both the create-formulation form (version 1) and the new-version form
 * (version N+1). Generic over the parent's own field-values shape (as long
 * as it has a `lines: FormulationLineFormValues[]` field) rather than
 * `Control<any>` — react-hook-form's `Control<T>` is effectively invariant
 * (the `validate` callback's `name` parameter makes it so), so a
 * `Control<SpecificShape>` isn't assignable to `Control<any>` at the
 * boundary; being generic here instead lets each caller's own concrete
 * type flow through untouched.
 */
export function FormulationLinesFields<TFieldValues extends FieldValues & { lines: FormulationLineFormValues[] }>({
  control,
  excludeItemId
}: {
  control: Control<TFieldValues>;
  /** The formulation's own output cannot be a direct ingredient. */
  excludeItemId?: number;
}) {
  // useFieldArray needs its name to resolve to an array-typed field
  // (ArrayPath<T>), a stricter constraint than the FieldPath<T> the
  // individual <FormField> names below use — TS can't prove `'lines'`
  // satisfies that for an arbitrary T bounded only by "has a lines array
  // field", so this one call narrows control to the fixed shape that
  // actually matters here. The FormField calls below keep using the
  // caller's real TFieldValues.
  const { fields, append, remove } = useFieldArray({
    control: control as unknown as Control<{
      lines: FormulationLineFormValues[];
    }>,
    name: 'lines'
  });
  const form = useFormContext<{ lines: FormulationLineFormValues[] }>();
  const watchedLines = useWatch({
    control: control as unknown as Control<{
      lines: FormulationLineFormValues[];
    }>,
    name: 'lines'
  });
  const itemsResult = useItemsIndex({ per_page: 100, sort: 'name' });
  const items = itemsResult.data?.status === 200 ? itemsResult.data.data.data : [];
  const uomsResult = useUomsIndex({ per_page: 100, sort: 'name' });
  const uoms = uomsResult.data?.status === 200 ? uomsResult.data.data.data : [];
  const itemOptions = items.filter((i) => i.id !== excludeItemId).map((i) => ({ value: String(i.id), label: `${i.code} — ${i.name}` }));

  return (
    <div className="space-y-4">
      {fields.map((field, index) => {
        const selectedItem = items.find((item) => item.id === Number(watchedLines?.[index]?.input_item_id));
        const stockUom = uoms.find((uom) => uom.id === selectedItem?.stock_uom_id);
        const uomOptions = uoms
          .filter((uom) => !stockUom || uom.dimension === stockUom.dimension)
          .map((uom) => ({
            value: String(uom.id),
            label: `${uom.name} (${uom.code})`
          }));

        return (
          <fieldset key={field.id} className="grid min-w-0 gap-3 rounded-lg border bg-panel/60 p-4 sm:grid-cols-2 lg:grid-cols-5 [&>*]:min-w-0">
            <legend className="px-1 text-sm font-medium">Line {index + 1}</legend>
            <FormField
              control={control}
              name={`lines.${index}.input_item_id` as FieldPath<TFieldValues>}
              render={({ field: itemField }) => (
                <FormItem className="lg:col-span-2">
                  <FormLabel>Item</FormLabel>
                  <FormControl>
                    <Combobox {...lookupState(itemsResult, 'items')}
                      ref={itemField.ref}
                      value={itemField.value}
                      onValueChange={(value) => {
                        itemField.onChange(value);
                        const item = items.find((candidate) => candidate.id === Number(value));
                        if (item) form.setValue(`lines.${index}.uom_id`, String(item.stock_uom_id), { shouldDirty: true });
                      }}
                      options={itemOptions}
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
              control={control}
              name={`lines.${index}.qty` as FieldPath<TFieldValues>}
              render={({ field: qtyField }) => (
                <FormItem>
                  <FormLabel>Quantity</FormLabel>
                  <FormControl>
                    <Input inputMode="decimal" {...qtyField} value={qtyField.value ?? ''} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={control}
              name={`lines.${index}.uom_id` as FieldPath<TFieldValues>}
              render={({ field: uomField }) => (
                <FormItem>
                  <FormLabel>Unit</FormLabel>
                  <FormControl>
                    <Combobox {...lookupState(uomsResult, 'units')}
                      ref={uomField.ref}
                      value={uomField.value}
                      onValueChange={uomField.onChange}
                      options={uomOptions}
                      placeholder="Defaults from item"
                      searchPlaceholder="Search units…"
                      emptyText="No units found."
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={control}
              name={`lines.${index}.wastage_pct` as FieldPath<TFieldValues>}
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
            <div className="flex items-end justify-end lg:col-start-5">
              <Button type="button" variant="outline" size="sm" onClick={() => remove(index)} disabled={fields.length === 0}>
                <Trash2 className="size-4" />
                Remove
              </Button>
            </div>
          </fieldset>
        );
      })}
      <Button type="button" variant="outline" onClick={() => append(emptyFormulationLine())}>
        Add line
      </Button>
    </div>
  );
}
