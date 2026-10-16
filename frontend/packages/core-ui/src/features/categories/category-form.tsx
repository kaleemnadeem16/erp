import { useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useCategoriesIndex, useChartOfAccountsIndex, ValuationMethod } from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Combobox } from '../../components/ui/combobox';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { SectionStack } from '../../components/ui/section-stack';
import { lookupState, type LookupState } from '../../lib/api-errors';
import { usePermission } from '../../hooks/use-permission';

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
/** Every descendant of `id` within `categories`, walking parent_id links — not just its direct children. */
function descendantCategoryIds(
  id: number | undefined,
  categories: { id: number; parent_id: number | null }[],
): Set<number> {
  const result = new Set<number>();
  if (id === undefined) return result;
  const childrenByParent = new Map<number, number[]>();
  for (const category of categories) {
    if (category.parent_id === null) continue;
    const siblings = childrenByParent.get(category.parent_id) ?? [];
    siblings.push(category.id);
    childrenByParent.set(category.parent_id, siblings);
  }
  const queue = [id];
  while (queue.length > 0) {
    const current = queue.shift() as number;
    for (const childId of childrenByParent.get(current) ?? []) {
      if (!result.has(childId)) {
        result.add(childId);
        queue.push(childId);
      }
    }
  }
  return result;
}
export function categoryPayload(v: CategoryFormValues, mayManageMappings: boolean) {
  const id = (value: string) => (value === NONE ? null : Number(value));
  return {
    code: v.code.trim(),
    name: v.name.trim(),
    parent_id: id(v.parent_id),
    default_valuation_method:
      v.default_valuation_method === NONE ? null : (v.default_valuation_method as ValuationMethod),
    ...(mayManageMappings
      ? {
          income_account_id: id(v.income_account_id),
          expense_account_id: id(v.expense_account_id),
          inventory_account_id: id(v.inventory_account_id),
        }
      : {}),
  };
}

export interface CategoryFormProps {
  defaultValues?: Partial<CategoryFormValues>;
  excludeId?: number;
  onSubmit: (v: CategoryFormValues, f: UseFormReturn<CategoryFormValues>) => void;
  onCancel?: () => void;
  isSubmitting?: boolean;
  formError?: string | null;
  mode?: 'create' | 'edit';
}

export function CategoryForm({
  defaultValues,
  excludeId,
  onSubmit,
  onCancel,
  isSubmitting,
  formError,
  mode = 'edit',
}: CategoryFormProps) {
  const mayManageMappings = usePermission('accounting.account-mapping.manage');
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
  const allCategories = categoriesResult.data?.status === 200 ? categoriesResult.data.data.data : [];
  // Excluding just excludeId isn't enough — picking a descendant as the parent
  // would create a cycle just as surely, and the backend rejects it (422, see
  // GuardsCompanyScopedHierarchy). Exclude the full descendant subtree too.
  const excludedParentIds = descendantCategoryIds(excludeId, allCategories);
  const categories = allCategories.filter((c) => c.id !== excludeId && !excludedParentIds.has(c.id));
  const accountsResult = useChartOfAccountsIndex(
    { per_page: 100, sort: 'code' },
    { query: { enabled: mayManageMappings } },
  );
  const accounts = accountsResult.data?.status === 200 ? accountsResult.data.data.data : [];
  const select = (
    name: 'parent_id' | 'income_account_id' | 'expense_account_id' | 'inventory_account_id',
    label: string,
    options: { id: number; code: string; name: string }[],
    lookup: LookupState,
  ) => (
    <FormField
      control={form.control}
      name={name}
      render={({ field }) => (
        <FormItem>
          <FormLabel>{label}</FormLabel>
          <FormControl>
            <Combobox
              {...lookup}
              ref={field.ref}
              value={field.value}
              onValueChange={field.onChange}
              options={[
                { value: NONE, label: 'None' },
                ...options.map((o) => ({
                  value: String(o.id),
                  label: `${o.code} — ${o.name}`,
                })),
              ]}
              searchPlaceholder={`Search ${label.toLowerCase()}s…`}
              emptyText="No results found."
            />
          </FormControl>
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
        <SectionStack>
          <DetailSection title="General">
            <FormField
              control={form.control}
              name="code"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Code</FormLabel>
                  <FormControl>
                    <Input className="h-10" {...field} />
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
                    <Input className="h-10" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            {select('parent_id', 'Parent category', categories, lookupState(categoriesResult, 'categories'))}
            <FormField
              control={form.control}
              name="default_valuation_method"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Default valuation method</FormLabel>
                  <Select value={field.value} onValueChange={field.onChange}>
                    <FormControl>
                      <SelectTrigger className="h-10" aria-label="Default valuation method">
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
          {mayManageMappings && (
            <DetailSection
              title="GL account defaults"
              description="Blank values inherit from the nearest category ancestor."
            >
              {select('income_account_id', 'Income account', accounts, lookupState(accountsResult, 'accounts'))}
              {select('expense_account_id', 'Expense account', accounts, lookupState(accountsResult, 'accounts'))}
              {select('inventory_account_id', 'Inventory account', accounts, lookupState(accountsResult, 'accounts'))}
            </DetailSection>
          )}
        </SectionStack>
        <div className="flex items-center justify-end gap-2 pt-2">
          {onCancel && (
            <Button type="button" variant="outline" onClick={onCancel} disabled={isSubmitting}>
              Cancel
            </Button>
          )}
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Saving…' : mode === 'create' ? 'Create category' : 'Save category'}
          </Button>
        </div>
      </form>
    </Form>
  );
}

