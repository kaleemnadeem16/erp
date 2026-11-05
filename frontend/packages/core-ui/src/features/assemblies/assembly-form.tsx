import { useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AssemblyGroupCombobox, UomCombobox } from '../../components/lookups/entity-comboboxes';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Textarea } from '../../components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';

const NONE = 'NONE';

const schema = z.object({
  code: z.string().min(1, 'Code is required').max(255),
  name: z.string().min(1, 'Name is required').max(255),
  description: z.string(),
  work_uom_id: z.string().min(1, 'Work unit is required'),
  assembly_group_id: z.string(),
  margin_type: z.enum(['MARGIN', 'MARKUP']),
  margin_pct: z
    .string()
    .refine((v) => /^\d{1,9}(\.\d{1,4})?$/.test(v) && Number(v) >= 0, 'Enter a percentage of 0 or more'),
});

export type AssemblyFormValues = z.infer<typeof schema>;

export const ASSEMBLY_FORM_FIELDS = [
  'code',
  'name',
  'description',
  'work_uom_id',
  'assembly_group_id',
  'margin_type',
  'margin_pct',
] as const satisfies readonly (keyof AssemblyFormValues)[];

export function assemblyPayload(v: AssemblyFormValues) {
  return {
    code: v.code.trim(),
    name: v.name.trim(),
    description: v.description.trim() || null,
    work_uom_id: Number(v.work_uom_id),
    assembly_group_id: v.assembly_group_id === NONE ? null : Number(v.assembly_group_id),
    margin_type: v.margin_type,
    margin_pct: v.margin_pct,
  };
}

export function AssemblyForm({
  mode,
  defaultValues,
  onSubmit,
  onCancel,
  isSubmitting,
  formError,
}: {
  mode: 'create' | 'edit';
  defaultValues?: Partial<AssemblyFormValues>;
  onSubmit: (v: AssemblyFormValues, f: UseFormReturn<AssemblyFormValues>) => void;
  onCancel: () => void;
  isSubmitting?: boolean;
  formError?: string | null;
}) {
  const form = useForm<AssemblyFormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      code: '',
      name: '',
      description: '',
      work_uom_id: '',
      assembly_group_id: NONE,
      margin_type: 'MARGIN',
      margin_pct: '0',
      ...defaultValues,
    },
  });
  return (
    <Form {...form}>
      <form className="space-y-6" noValidate aria-label={mode === 'create' ? 'New assembly' : 'Edit assembly'} onSubmit={form.handleSubmit((v) => onSubmit(v, form))}>
        {formError && (
          <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
            {formError}
          </div>
        )}
        <DetailSection title="Assembly" description="A sellable unit of work, priced per work unit.">
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
            name="description"
            render={({ field }) => (
              <FormItem className="sm:col-span-2">
                <FormLabel>Description</FormLabel>
                <FormControl>
                  <Textarea rows={3} {...field} />
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
                  <UomCombobox label="Work unit" placeholder="Select unit" className="h-11 w-full md:h-10" value={field.value} onValueChange={field.onChange} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="assembly_group_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Assembly group</FormLabel>
                <FormControl>
                  <AssemblyGroupCombobox
                    label="Assembly group"
                    placeholder="Ungrouped"
                    className="h-11 w-full md:h-10"
                    leadingOptions={[{ value: NONE, label: 'Ungrouped' }]}
                    value={field.value}
                    onValueChange={field.onChange}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="margin_type"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Margin type</FormLabel>
                <Select value={field.value} onValueChange={field.onChange}>
                  <FormControl>
                    <SelectTrigger className="h-11 md:h-10">
                      <SelectValue />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    <SelectItem value="MARGIN">Margin — % of selling price</SelectItem>
                    <SelectItem value="MARKUP">Markup — % of cost</SelectItem>
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="margin_pct"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Margin / markup %</FormLabel>
                <FormControl>
                  <Input inputMode="decimal" className="h-11 md:h-10" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>
        <div className="sticky bottom-0 z-10 -mx-4 flex items-center justify-end gap-2 border-t bg-background px-4 py-3">
          <Button type="button" variant="outline" className="h-11 md:h-9" disabled={isSubmitting} onClick={onCancel}>
            Cancel
          </Button>
          <Button type="submit" className="h-11 md:h-9" disabled={isSubmitting}>
            {isSubmitting ? 'Saving…' : mode === 'create' ? 'Create assembly' : 'Save assembly'}
          </Button>
        </div>
      </form>
    </Form>
  );
}
