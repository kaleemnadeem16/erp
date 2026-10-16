import { useForm, type UseFormReturn } from 'react-hook-form';
import { ItemCombobox } from '../../components/lookups/entity-comboboxes';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useCustomerAccountsIndex, useUomsIndex } from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Combobox } from '../../components/ui/combobox';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { formulationLineSchema, formulationLinesPayload, FormulationLinesFields } from './formulation-lines-fields';
import { lookupState } from '../../lib/api-errors';
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
  isSubmitting,
  formError
}: {
  onSubmit: (v: FormulationFormValues, f: UseFormReturn<FormulationFormValues>) => void;
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
  const uomsResult = useUomsIndex({ per_page: 100, sort: 'name' });
  const uoms = uomsResult.data?.status === 200 ? uomsResult.data.data.data : [];
  const customersResult = useCustomerAccountsIndex({
    per_page: 100,
    sort: 'code'
  });
  const customers = customersResult.data?.status === 200 ? customersResult.data.data.data : [];
  const outputItemId = Number(form.watch('output_item_id')) || undefined;

  return (
    <Form {...form}>
      <form className="space-y-6" onSubmit={form.handleSubmit((v) => onSubmit(v, form))}>
        {formError && <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{formError}</div>}
        <DetailSection title="Formulation" description="A recipe: the inputs required to produce one base unit of the output item.">
          <FormField
            control={form.control}
            name="output_item_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Output item</FormLabel>
                <FormControl>
                  <ItemCombobox label="Output item" placeholder="Select output item" value={field.value} onValueChange={(value, item) => {
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
                  <Input {...field} />
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
                  <Input {...field} />
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
                  <DecimalInput rule={{ spec: 'assemblyTechnical', sign: 'positive' }} {...field} />
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
                  <Combobox {...lookupState(uomsResult, 'units')}
                    ref={field.ref}
                    value={field.value}
                    onValueChange={field.onChange}
                    options={uoms.map((u) => ({
                      value: String(u.id),
                      label: `${u.name} (${u.code})`
                    }))}
                    placeholder="Defaults from output item"
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
            name="customer_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Customer override</FormLabel>
                <FormControl>
                  <Combobox {...lookupState(customersResult, 'customers')}
                    ref={field.ref}
                    value={field.value}
                    onValueChange={field.onChange}
                    options={[
                      { value: NONE, label: 'Company default (no customer)' },
                      ...customers.map((c) => ({
                        value: String(c.id),
                        label: c.code
                      }))
                    ]}
                    searchPlaceholder="Search customers…"
                    emptyText="No customers found."
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
        <div className="flex justify-end">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Saving…' : 'Create formulation'}
          </Button>
        </div>
      </form>
    </Form>
  );
}
