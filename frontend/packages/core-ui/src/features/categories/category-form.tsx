import { useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useCategoriesIndex, useChartOfAccountsIndex, ValuationMethod } from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';

const NONE = 'NONE';
const schema = z.object({
  code: z.string().trim().min(1, 'Code is required').max(255),
  name: z.string().trim().min(1, 'Name is required').max(255),
  parent_id: z.string(),
  default_valuation_method: z.string(),
  income_account_id: z.string(),
  expense_account_id: z.string(),
  inventory_account_id: z.string(),
});
export type CategoryFormValues = z.infer<typeof schema>;
export const CATEGORY_FORM_FIELDS = [
  'code',
  'name',
  'parent_id',
  'default_valuation_method',
  'income_account_id',
  'expense_account_id',
  'inventory_account_id',
] as const satisfies readonly (keyof CategoryFormValues)[];
export function categoryPayload(v: CategoryFormValues) {
  const id = (value: string) => (value === NONE ? null : Number(value));
  return {
    code: v.code.trim(),
    name: v.name.trim(),
    parent_id: id(v.parent_id),
    default_valuation_method:
      v.default_valuation_method === NONE ? null : (v.default_valuation_method as ValuationMethod),
    income_account_id: id(v.income_account_id),
    expense_account_id: id(v.expense_account_id),
    inventory_account_id: id(v.inventory_account_id),
  };
}
export function CategoryForm({
  defaultValues,
  excludeId,
  onSubmit,
  isSubmitting,
  formError,
}: {
  defaultValues?: Partial<CategoryFormValues>;
  excludeId?: number;
  onSubmit: (v: CategoryFormValues, f: UseFormReturn<CategoryFormValues>) => void;
  isSubmitting?: boolean;
  formError?: string | null;
}) {
  const form = useForm<CategoryFormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      code: '',
      name: '',
      parent_id: NONE,
      default_valuation_method: NONE,
      income_account_id: NONE,
      expense_account_id: NONE,
      inventory_account_id: NONE,
      ...defaultValues,
    },
  });
  const categoriesResult = useCategoriesIndex({ per_page: 100, sort: 'name' });
  const categories = (categoriesResult.data?.status === 200 ? categoriesResult.data.data.data : []).filter(
    (c) => c.id !== excludeId
  );
  const accountsResult = useChartOfAccountsIndex({ per_page: 100, sort: 'code' });
  const accounts = accountsResult.data?.status === 200 ? accountsResult.data.data.data : [];
  const select = (
    name: 'parent_id' | 'income_account_id' | 'expense_account_id' | 'inventory_account_id',
    label: string,
    options: { id: number; code: string; name: string }[]
  ) => (
    <FormField
      control={form.control}
      name={name}
      render={({ field }) => (
        <FormItem>
          <FormLabel>{label}</FormLabel>
          <Select value={field.value} onValueChange={field.onChange}>
            <FormControl>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
            </FormControl>
            <SelectContent>
              <SelectItem value={NONE}>None</SelectItem>
              {options.map((o) => (
                <SelectItem key={o.id} value={String(o.id)}>
                  {o.code} — {o.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <FormMessage />
        </FormItem>
      )}
    />
  );
  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit((v) => onSubmit(v, form))} className="space-y-6">
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
          {select('parent_id', 'Parent category', categories)}
          <FormField
            control={form.control}
            name="default_valuation_method"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Default valuation method</FormLabel>
                <Select value={field.value} onValueChange={field.onChange}>
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    <SelectItem value={NONE}>Inherit from parent</SelectItem>
                    {Object.values(ValuationMethod).map((v) => (
                      <SelectItem key={v} value={v}>
                        {v}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>
        <DetailSection
          title="GL account defaults"
          description="Blank values inherit from the nearest category ancestor."
        >
          {select('income_account_id', 'Income account', accounts)}
          {select('expense_account_id', 'Expense account', accounts)}
          {select('inventory_account_id', 'Inventory account', accounts)}
        </DetailSection>
        <div className="flex justify-end">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Saving…' : 'Save category'}
          </Button>
        </div>
      </form>
    </Form>
  );
}
