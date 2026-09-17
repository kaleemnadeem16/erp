import { useState } from 'react';
import { useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Plus, X } from 'lucide-react';
import { useItemsIndex, AvcoPoolScope, ValuationMethod, InventoryCutoverMode } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Combobox } from '../../components/ui/combobox';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';

const inventoryCutoverFormSchema = z.object({
  mode: z.string().min(1, 'Mode is required'),
  cutover_at: z.string().min(1, 'Cutover date is required'),
  source_system: z.string().min(1, 'Source system is required').max(255),
  control_quantity: z.string().min(1, 'Control quantity is required'),
  control_value: z.string().min(1, 'Control value is required'),
  base_control_value: z.string().min(1, 'Base control value is required'),
});

export type InventoryCutoverFormValues = z.infer<typeof inventoryCutoverFormSchema>;

export const INVENTORY_CUTOVER_FORM_FIELDS = [
  'mode', 'cutover_at', 'source_system', 'control_quantity', 'control_value', 'base_control_value',
] as const satisfies readonly (keyof InventoryCutoverFormValues)[];

const DEFAULT_VALUES: InventoryCutoverFormValues = {
  mode: InventoryCutoverMode.OPENING_STATE,
  cutover_at: '',
  source_system: '',
  control_quantity: '',
  control_value: '',
  base_control_value: '',
};

export interface PolicyRow {
  itemCode: string;
  method: string;
  scope: string;
}

/**
 * target_valuation_policy is an object keyed by item code, not a list — the
 * generated request type came out as `string[]` because Scramble can't infer
 * a dynamic-key map from a validation closure (see
 * StoreInventoryCutoverRequest's own comment on the backend). This editor
 * works with a plain row array and the caller assembles the keyed object.
 */
export function policyRowsToPolicy(rows: PolicyRow[]): Record<string, { method: string; scope?: string | null }> {
  const policy: Record<string, { method: string; scope?: string | null }> = {};
  for (const row of rows) {
    if (!row.itemCode || !row.method) continue;
    policy[row.itemCode] = { method: row.method, scope: row.method === ValuationMethod.AVCO ? row.scope || null : null };
  }
  return policy;
}

/** Create-only — an inventory cutover header is immutable once created (docs/domain/inventory-cutover.md). */
export function InventoryCutoverForm({
  onSubmit,
  isSubmitting,
  formError,
}: {
  onSubmit: (values: InventoryCutoverFormValues, policyRows: PolicyRow[], form: UseFormReturn<InventoryCutoverFormValues>) => void;
  isSubmitting?: boolean;
  formError?: string | null;
}) {
  const form = useForm<InventoryCutoverFormValues>({
    resolver: zodResolver(inventoryCutoverFormSchema),
    defaultValues: DEFAULT_VALUES,
  });
  const [policyRows, setPolicyRows] = useState<PolicyRow[]>([{ itemCode: '', method: '', scope: '' }]);
  const [policyError, setPolicyError] = useState<string | null>(null);

  const itemsQuery = useItemsIndex({ per_page: 100, sort: 'name', 'filter[status]': 'ACTIVE' });
  const items = itemsQuery.data?.status === 200 ? itemsQuery.data.data.data : [];

  function updateRow(index: number, patch: Partial<PolicyRow>) {
    setPolicyRows((rows) => rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
  }

  function handleSubmit(values: InventoryCutoverFormValues) {
    const complete = policyRows.filter((row) => row.itemCode && row.method);
    if (complete.length === 0) {
      setPolicyError('At least one item needs a target valuation policy.');
      return;
    }
    // Defense in depth: policyRowsToPolicy keys by item code, so a duplicate
    // silently overwrites an earlier row with no warning. The item picker
    // below already prevents picking the same item twice, but this catches
    // it regardless — e.g. if a row is edited after being filtered out once.
    const seen = new Set<string>();
    const duplicate = complete.find((row) => (seen.has(row.itemCode) ? true : (seen.add(row.itemCode), false)));
    if (duplicate) {
      setPolicyError(`"${duplicate.itemCode}" has more than one policy row — each item can only appear once.`);
      return;
    }
    setPolicyError(null);
    onSubmit(values, complete, form);
  }

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(handleSubmit)} className="space-y-6">
        {formError && (
          <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{formError}</div>
        )}

        <DetailSection title="General">
          <FormField
            control={form.control}
            name="mode"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Mode</FormLabel>
                <Select value={field.value} onValueChange={field.onChange}>
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    <SelectItem value={InventoryCutoverMode.OPENING_STATE}>Opening state</SelectItem>
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="cutover_at"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Cutover at</FormLabel>
                <FormControl>
                  <Input type="datetime-local" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="source_system"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Source system</FormLabel>
                <FormControl>
                  <Input placeholder="Legacy ERP" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>

        <DetailSection title="Control totals" description="What the predecessor system's own report says the opening balance totals — attached batches must reconcile to these exactly.">
          <FormField
            control={form.control}
            name="control_quantity"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Control quantity</FormLabel>
                <FormControl>
                  <Input type="number" step="0.0001" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="control_value"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Control value</FormLabel>
                <FormControl>
                  <Input type="number" step="0.0001" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="base_control_value"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Base control value</FormLabel>
                <FormControl>
                  <Input type="number" step="0.0001" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>

        <DetailSection title="Target valuation policy" description="What each item's valuation method and AVCO pool scope should be after this cutover.">
          <div className="space-y-2 sm:col-span-2">
            {policyRows.map((row, index) => {
              // An item already given a policy on another row can't be picked again here —
              // policyRowsToPolicy keys by item code, so a repeat would silently overwrite
              // whichever row set it first.
              const usedByOtherRows = new Set(
                policyRows.filter((_, i) => i !== index).map((other) => other.itemCode).filter(Boolean),
              );
              const availableItems = items.filter((item) => !usedByOtherRows.has(item.code));
              return (
              <div key={index} className="flex flex-wrap items-end gap-2">
                <div className="flex flex-col gap-1">
                  <span className="text-xs font-medium text-muted-foreground">Item</span>
                  <Combobox
                    className="h-9 w-56"
                    value={row.itemCode}
                    onValueChange={(value) => updateRow(index, { itemCode: value })}
                    placeholder="Select an item"
                    searchPlaceholder="Search items…"
                    emptyText="No items found."
                    options={availableItems.map((item) => ({ value: item.code, label: `${item.code} — ${item.name}` }))}
                  />
                </div>
                <div className="flex flex-col gap-1">
                  <span className="text-xs font-medium text-muted-foreground">Method</span>
                  <Select value={row.method} onValueChange={(value) => updateRow(index, { method: value, scope: '' })}>
                    <SelectTrigger className="h-9 w-32">
                      <SelectValue placeholder="Method" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value={ValuationMethod.AVCO}>AVCO</SelectItem>
                      <SelectItem value={ValuationMethod.FIFO}>FIFO</SelectItem>
                      <SelectItem value={ValuationMethod.SPECIFIC}>SPECIFIC</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                {row.method === ValuationMethod.AVCO && (
                  <div className="flex flex-col gap-1">
                    <span className="text-xs font-medium text-muted-foreground">AVCO scope</span>
                    <Select value={row.scope} onValueChange={(value) => updateRow(index, { scope: value })}>
                      <SelectTrigger className="h-9 w-44">
                        <SelectValue placeholder="Scope" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value={AvcoPoolScope.COMPANY_ITEM}>Company + item</SelectItem>
                        <SelectItem value={AvcoPoolScope.WAREHOUSE_ITEM}>Warehouse + item</SelectItem>
                        <SelectItem value={AvcoPoolScope.COMPANY_ITEM_BATCH}>Company + item + batch</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                )}
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  className="h-9 w-9 shrink-0"
                  onClick={() => setPolicyRows((rows) => rows.filter((_, i) => i !== index))}
                  disabled={policyRows.length === 1}
                  aria-label="Remove row"
                >
                  <X className="h-4 w-4" />
                </Button>
              </div>
              );
            })}
            <Button type="button" variant="outline" size="sm" onClick={() => setPolicyRows((rows) => [...rows, { itemCode: '', method: '', scope: '' }])}>
              <Plus className="h-4 w-4" />
              Add item
            </Button>
            {policyError && <p className="text-sm text-destructive">{policyError}</p>}
          </div>
        </DetailSection>

        <div className="flex justify-end">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Creating…' : 'Create cutover'}
          </Button>
        </div>
      </form>
    </Form>
  );
}
