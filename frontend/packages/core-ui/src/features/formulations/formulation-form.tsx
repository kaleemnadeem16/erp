import { useForm, type UseFormReturn } from 'react-hook-form';
import { CustomerCombobox, ItemCombobox, UomCombobox } from '../../components/lookups/entity-comboboxes';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { formulationLineSchema, formulationLinesPayload, FormulationLinesFields } from './formulation-lines-fields';
import { DecimalInput } from '../../components/ui/decimal-input';

const NONE = 'NONE';

const schema = z.object({
  output_item_id: z.string().min(1, 'Output item is required'),
  code: z.string().min(1, 'Code is required').max(255),
  name: z.string().min(1, 'Name is required').max(255),
  output_qty: z.string().refine((v) => !v || (/^\d{1,12}(\.\d{1,6})?$/.test(v) && Number(v) > 0), 'Enter a positive quantity'),
  output_uom_id: z.string().min(1, 'Output unit is required'),
  customer_id: z.string(),
  lines: z.array(formulationLineSchema)
});

export type FormulationFormValues = z.infer<typeof schema>;

export const FORMULATION_FORM_FIELDS = [
  'output_item_id',
  'code',
  'name',
  'output_qty',
  'output_uom_id',
  'customer_id',
  'lines'
] as const satisfies readonly (keyof FormulationFormValues)[];

export function formulationPayload(v: FormulationFormValues) {
  return {
    code: v.code.trim(),
    name: v.name.trim(),
    output_qty: v.output_qty ? v.output_qty.trim() : '1',
    output_uom_id: Number(v.output_uom_id),
    customer_id: v.customer_id === NONE ? null : Number(v.customer_id),
    lines: formulationLinesPayload(v.lines)
  };
}

export function FormulationForm({
  onSubmit,
  onCancel,
  isSubmitting,
  formError
}: {
  onSubmit: (v: FormulationFormValues, f: UseFormReturn<FormulationFormValues>) => void;
  onCancel: () => void;
  isSubmitting?: boolean;
  formError?: string | null;
}) {
  const form = useForm<FormulationFormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      output_item_id: '',
      code: '',
      name: '',
      output_qty: '1',
      output_uom_id: '',
      customer_id: NONE,
      lines: []
    }
  });
  const outputItemId = Number(form.watch('output_item_id')) || undefined;

  return (
    <Form {...form}>
      <form className="space-y-6" noValidate aria-label="New formulation" onSubmit={form.handleSubmit((v) => onSubmit(v, form))}>
        {formError && <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{formError}</div>}
        <DetailSection title="Formulation" description="A recipe: the inputs required to produce one base unit of the output item.">
          <FormField
            control={form.control}
            name="output_item_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Output item</FormLabel>
                <FormControl>
                  <ItemCombobox label="Output item" placeholder="Select output item" className="h-11 w-full md:h-10" value={field.value} onValueChange={(value, item) => {
                      field.onChange(value);
                      if (item) form.setValue('output_uom_id', String(item.stock_uom_id), { shouldDirty: true });
                    }} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="code"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Code</FormLabel>
                <FormControl>
                  <Input className="h-11 md:h-10" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="name"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Name</FormLabel>
                <FormControl>
                  <Input className="h-11 md:h-10" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="output_qty"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Output quantity</FormLabel>
                <FormControl>
                  <DecimalInput className="h-11 md:h-10" rule={{ spec: 'assemblyTechnical', sign: 'positive' }} {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="output_uom_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Output unit</FormLabel>
                <FormControl>
                  <UomCombobox label="Output unit" placeholder="Defaults from output item" className="h-11 w-full md:h-10" value={field.value} onValueChange={field.onChange} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="customer_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Customer override</FormLabel>
                <FormControl>
                  <CustomerCombobox
                    label="Customer override"
                    placeholder="Company default (no customer)"
                    className="h-11 w-full md:h-10"
                    leadingOptions={[{ value: NONE, label: 'Company default (no customer)' }]}
                    value={field.value}
                    onValueChange={field.onChange}
                  />
                </FormControl>
                <p className="text-xs text-muted-foreground">
                  Leave as company default for a standard recipe. Select a customer only when that customer needs a different recipe for the same output item.
                </p>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>
        <DetailSection title="Version 1 lines" description="The recipe's ingredients. You can activate this version once it has at least one line." fullWidth>
          <FormulationLinesFields control={form.control} excludeItemId={outputItemId} />
        </DetailSection>
        <div className="sticky bottom-0 z-10 -mx-4 flex items-center justify-end gap-2 border-t bg-background px-4 py-3">
          <Button type="button" variant="outline" className="h-11 md:h-9" disabled={isSubmitting} onClick={onCancel}>
            Cancel
          </Button>
          <Button type="submit" className="h-11 md:h-9" disabled={isSubmitting}>
            {isSubmitting ? 'Saving…' : 'Create formulation'}
          </Button>
        </div>
      </form>
    </Form>
  );
}
