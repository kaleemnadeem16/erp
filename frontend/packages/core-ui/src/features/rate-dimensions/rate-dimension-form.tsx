import { useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Button } from '../../components/ui/button';
import { Checkbox } from '../../components/ui/checkbox';
import { Input } from '../../components/ui/input';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';

const schema = z.object({
  code: z.string().min(1, 'Code is required').max(255),
  name: z.string().min(1, 'Name is required').max(255),
  sort_order: z
    .string()
    .refine((v) => !v || (/^\d+$/.test(v) && Number(v) <= 65535), 'Sort order must be between 0 and 65535'),
  is_required: z.boolean(),
});

export type RateDimensionFormValues = z.infer<typeof schema>;

export const RATE_DIMENSION_FORM_FIELDS = [
  'code',
  'name',
  'sort_order',
  'is_required',
] as const satisfies readonly (keyof RateDimensionFormValues)[];

export function rateDimensionPayload(v: RateDimensionFormValues) {
  return {
    code: v.code.trim(),
    name: v.name.trim(),
    sort_order: v.sort_order ? Number(v.sort_order) : 0,
    is_required: v.is_required,
  };
}

export function RateDimensionForm({
  mode,
  defaultValues,
  onSubmit,
  isSubmitting,
  formError,
}: {
  mode: 'create' | 'edit';
  defaultValues?: Partial<RateDimensionFormValues>;
  onSubmit: (v: RateDimensionFormValues, f: UseFormReturn<RateDimensionFormValues>) => void;
  isSubmitting?: boolean;
  formError?: string | null;
}) {
  const form = useForm<RateDimensionFormValues>({
    resolver: zodResolver(schema),
    defaultValues: { code: '', name: '', sort_order: '', is_required: true, ...defaultValues },
  });
  return (
    <Form {...form}>
      <form className="space-y-6" onSubmit={form.handleSubmit((v) => onSubmit(v, form))}>
        {formError && (
          <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
            {formError}
          </div>
        )}
        <DetailSection
          title="Dimension"
          description="A company-defined axis application rates can vary by — e.g. Surface Type, Thickness."
        >
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
            name="sort_order"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Sort order</FormLabel>
                <FormControl>
                  <Input type="number" min="0" max="65535" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="is_required"
            render={({ field }) => (
              <FormItem className="flex items-center gap-3 space-y-0">
                <FormControl>
                  <Checkbox checked={field.value} onCheckedChange={(v) => field.onChange(!!v)} />
                </FormControl>
                <FormLabel>Required — a rate must specify a value on this axis to exact-match</FormLabel>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>
        <div className="flex justify-end">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Saving…' : mode === 'create' ? 'Create dimension' : 'Save dimension'}
          </Button>
        </div>
      </form>
    </Form>
  );
}
