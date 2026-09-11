import { useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

import { Button } from '../../components/ui/button';
import { Checkbox } from '../../components/ui/checkbox';
import { Input } from '../../components/ui/input';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { applyApiErrorsToForm, isApiError } from '../../lib/api-errors';

const warehouseFieldsSchema = z.object({
  code: z.string().trim().min(1, 'Code is required').max(255),
  name: z.string().trim().min(1, 'Name is required').max(255),
  is_default: z.boolean(),
  active: z.boolean(),
  location_code: z.string().trim().max(255),
  location_name: z.string().trim().max(255),
});

const createWarehouseFormSchema = warehouseFieldsSchema.superRefine((values, context) => {
  if (!values.location_code)
    context.addIssue({
      code: 'custom',
      path: ['location_code'],
      message: 'Initial location code is required',
    });
  if (!values.location_name)
    context.addIssue({
      code: 'custom',
      path: ['location_name'],
      message: 'Initial location name is required',
    });
});

export type WarehouseFormValues = z.infer<typeof warehouseFieldsSchema>;

export const WAREHOUSE_FORM_FIELDS = [
  'code',
  'name',
  'is_default',
  'active',
  'location_code',
  'location_name',
] as const satisfies readonly (keyof WarehouseFormValues)[];

/**
 * `is_default` has a partial unique index (one default warehouse per
 * company) that a concurrent submit can still race past `code`'s own
 * request-time `CompanyScoped::unique()` check. That race lands in
 * bootstrap/app.php's generic UniqueConstraintViolationException handler,
 * which — unlike a normal validation 422 — has no way to know which field
 * caused it and returns a non-field-attributable `conflict` key instead.
 * `code` is always validated before the insert is attempted, so within this
 * form a `conflict` error can only mean the is_default race; map it onto the
 * checkbox's own FormMessage rather than showing it as a generic banner.
 */
export function applyWarehouseFormErrors(error: unknown, form: UseFormReturn<WarehouseFormValues>): string | null {
  if (isApiError(error) && error.body?.errors?.conflict) {
    form.setError('is_default', { type: 'server', message: 'Another warehouse is already set as default.' });
    return null;
  }
  return applyApiErrorsToForm(error, form.setError, WAREHOUSE_FORM_FIELDS);
}

const DEFAULT_VALUES: WarehouseFormValues = {
  code: '',
  name: '',
  is_default: false,
  active: true,
  location_code: '',
  location_name: '',
};

export function WarehouseForm({
  mode,
  defaultValues,
  onSubmit,
  isSubmitting,
  formError,
}: {
  mode: 'create' | 'edit';
  defaultValues?: Partial<WarehouseFormValues>;
  onSubmit: (values: WarehouseFormValues, form: UseFormReturn<WarehouseFormValues>) => void;
  isSubmitting?: boolean;
  formError?: string | null;
}) {
  const form = useForm<WarehouseFormValues>({
    resolver: zodResolver(mode === 'create' ? createWarehouseFormSchema : warehouseFieldsSchema),
    defaultValues: { ...DEFAULT_VALUES, ...defaultValues },
  });

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
            name="is_default"
            render={({ field }) => (
              <FormItem className="flex items-center gap-3 space-y-0">
                <FormControl>
                  <Checkbox checked={field.value} onCheckedChange={(value) => field.onChange(!!value)} />
                </FormControl>
                <FormLabel>Default warehouse</FormLabel>
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
                  <Checkbox checked={field.value} onCheckedChange={(value) => field.onChange(!!value)} />
                </FormControl>
                <FormLabel>Active</FormLabel>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>

        {mode === 'create' && (
          <DetailSection
            title="Initial location"
            description="Every warehouse starts with one real stock-holding location. You can add structure later when structured inventory locations are enabled."
          >
            <FormField
              control={form.control}
              name="location_code"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Location code</FormLabel>
                  <FormControl>
                    <Input {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="location_name"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Location name</FormLabel>
                  <FormControl>
                    <Input {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          </DetailSection>
        )}

        <div className="flex justify-end">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Saving…' : mode === 'create' ? 'Create warehouse' : 'Save changes'}
          </Button>
        </div>
      </form>
    </Form>
  );
}
