import { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Pencil, Trash2, X } from 'lucide-react';
import type { SortingState } from '@tanstack/react-table';
import {
  useItemQuantityUnitsDestroy,
  useItemQuantityUnitsIndex,
  useItemQuantityUnitsStore,
  useItemQuantityUnitsUpdate,
  useItemsIndex,
  useUomsIndex,
  type ItemQuantityUnitResource,
} from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns } from '../../components/data-table';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Tooltip, TooltipContent, TooltipTrigger } from '../../components/ui/tooltip';
import { Combobox } from '../../components/ui/combobox';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { applyApiErrorsToForm, getApiErrorMessage } from '../../lib/api-errors';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { itemQuantityUnitColumns } from './item-quantity-units-columns';

const DECIMAL = /^\d{1,10}(\.\d{1,9})?$/;
const schema = z.object({
  item_id: z.string().min(1, 'Item is required'),
  uom_id: z.string().min(1, 'Pack unit is required'),
  factor: z.string().refine((v) => DECIMAL.test(v) && Number(v) > 0, 'Enter a factor greater than 0'),
  quantity_precision: z.string().refine((v) => !v || (DECIMAL.test(v) && Number(v) > 0), 'Enter a positive precision'),
});
type FormValues = z.infer<typeof schema>;
const FORM_FIELDS = ['item_id', 'uom_id', 'factor', 'quantity_precision'] as const;
const EMPTY_VALUES: FormValues = { item_id: '', uom_id: '', factor: '', quantity_precision: '' };

interface Filters {
  item_id: string;
  [key: string]: string;
}

/**
 * ADR 0050's per-(item, UoM) pack conversion override — "1 pack unit =
 * factor × the item's own stock UoM," may cross UoM dimension. The (item,
 * UoM) pair is create-only, matching ItemSupplier's identity lock: editing
 * an existing row only ever changes factor/quantity_precision, never which
 * item or unit it's about.
 */
export function ItemQuantityUnitsListScreen() {
  const query = useListQueryState<Filters>({ defaultSort: '-created_at' });
  const result = useItemQuantityUnitsIndex({
    'filter[item_id]': query.filters.item_id ? Number(query.filters.item_id) : undefined,
    include: 'item,uom',
    sort: query.sort as 'created_at' | '-created_at' | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const columns = useMemo(() => toTanStackColumns(itemQuantityUnitColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, itemQuantityUnitColumns);

  const itemsResult = useItemsIndex({ per_page: 100, sort: 'name' });
  const items = itemsResult.data?.status === 200 ? itemsResult.data.data.data : [];
  const uomsResult = useUomsIndex({ per_page: 100, sort: 'name' });
  const uoms = uomsResult.data?.status === 200 ? uomsResult.data.data.data : [];

  const storeMutation = useItemQuantityUnitsStore();
  const updateMutation = useItemQuantityUnitsUpdate();
  const deleteMutation = useItemQuantityUnitsDestroy();
  const [formError, setFormError] = useState<string | null>(null);
  const [editing, setEditing] = useState<ItemQuantityUnitResource | null>(null);

  const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY_VALUES });

  function startEdit(row: ItemQuantityUnitResource) {
    setFormError(null);
    setEditing(row);
    form.reset({
      item_id: String(row.item_id),
      uom_id: String(row.uom_id),
      factor: row.factor,
      quantity_precision: row.quantity_precision ?? '',
    });
  }

  function cancelEdit() {
    setFormError(null);
    setEditing(null);
    form.reset(EMPTY_VALUES);
  }

  function submit(values: FormValues) {
    setFormError(null);
    const data = {
      factor: values.factor.trim(),
      quantity_precision: values.quantity_precision.trim() || null,
    };
    if (editing) {
      updateMutation.mutate(
        { itemQuantityUnit: editing.id, data },
        {
          onSuccess: () => {
            cancelEdit();
            void result.refetch();
          },
          onError: (e) => setFormError(applyApiErrorsToForm(e, form.setError, FORM_FIELDS)),
        }
      );
      return;
    }
    storeMutation.mutate(
      { data: { item_id: Number(values.item_id), uom_id: Number(values.uom_id), ...data } },
      {
        onSuccess: () => {
          form.reset({ ...EMPTY_VALUES, item_id: values.item_id });
          void result.refetch();
        },
        onError: (e) => setFormError(applyApiErrorsToForm(e, form.setError, FORM_FIELDS)),
      }
    );
  }

  function renderRowActions(row: ItemQuantityUnitResource) {
    return (
      <>
        <Tooltip>
          <TooltipTrigger asChild>
            <Button variant="ghost" size="icon" className="h-8 w-8" aria-label="Edit" onClick={() => startEdit(row)}>
              <Pencil className="h-4 w-4" />
            </Button>
          </TooltipTrigger>
          <TooltipContent>Edit</TooltipContent>
        </Tooltip>
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8"
              aria-label="Delete"
              onClick={() =>
                deleteMutation.mutate(
                  { itemQuantityUnit: row.id },
                  {
                    onSuccess: () => {
                      if (editing?.id === row.id) cancelEdit();
                      void result.refetch();
                    },
                  }
                )
              }
            >
              <Trash2 className="h-4 w-4" />
            </Button>
          </TooltipTrigger>
          <TooltipContent>Delete</TooltipContent>
        </Tooltip>
      </>
    );
  }

  return (
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Pack conversions"
        description="An item's own alternate/pack unit factor (a box of eaches, a roll priced by the metre) — resolved before the global unit-of-measure conversion. Item and pack unit are fixed once created; delete and re-add to change either."
      />

      <div className="flex flex-wrap items-center gap-2">
        <Combobox
          className="h-9 w-64"
          aria-label="Filter by item"
          value={query.filters.item_id ?? 'all'}
          onValueChange={(v) => query.setFilter('item_id', v === 'all' ? undefined : v)}
          placeholder="All items"
          searchPlaceholder="Search items…"
          emptyText="No items found."
          options={[{ value: 'all', label: 'All items' }, ...items.map((i) => ({ value: String(i.id), label: `${i.code} — ${i.name}` }))]}
        />
      </div>

      <DetailSection
        title={editing ? `Edit pack conversion — ${editing.item ? editing.item.code : editing.item_id}` : 'Add a pack conversion'}
      >
        {formError && (
          <div className="sm:col-span-2 rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
            {formError}
          </div>
        )}
        <Form {...form}>
          <form className="col-span-full grid gap-3 sm:grid-cols-4" onSubmit={form.handleSubmit(submit)}>
            <FormField
              control={form.control}
              name="item_id"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Item</FormLabel>
                  <FormControl>
                    <Combobox
                      ref={field.ref}
                      disabled={!!editing}
                      value={field.value}
                      onValueChange={field.onChange}
                      options={items.map((i) => ({ value: String(i.id), label: `${i.code} — ${i.name}` }))}
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
              control={form.control}
              name="uom_id"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Pack unit</FormLabel>
                  <FormControl>
                    <Combobox
                      ref={field.ref}
                      disabled={!!editing}
                      value={field.value}
                      onValueChange={field.onChange}
                      options={uoms.map((u) => ({ value: String(u.id), label: `${u.name} (${u.code})` }))}
                      placeholder="Select unit"
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
              name="factor"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Factor</FormLabel>
                  <FormControl>
                    <Input inputMode="decimal" placeholder="e.g. 12" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="quantity_precision"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Rounding precision (optional)</FormLabel>
                  <FormControl>
                    <Input inputMode="decimal" placeholder="Unrestricted" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <div className="flex justify-end gap-2 sm:col-span-4">
              {editing && (
                <Button type="button" variant="outline" onClick={cancelEdit}>
                  <X className="h-4 w-4" />
                  Cancel
                </Button>
              )}
              <Button type="submit" disabled={storeMutation.isPending || updateMutation.isPending}>
                {editing
                  ? updateMutation.isPending
                    ? 'Saving…'
                    : 'Save changes'
                  : storeMutation.isPending
                    ? 'Saving…'
                    : 'Add conversion'}
              </Button>
            </div>
          </form>
        </Form>
      </DetailSection>

      <DataTable
        columns={columns}
        data={rows}
        rowCount={total}
        pagination={{ pageIndex: query.page - 1, pageSize: query.perPage }}
        onPaginationChange={(u) => {
          const pagination = { pageIndex: query.page - 1, pageSize: query.perPage };
          const n = typeof u === 'function' ? u(pagination) : u;
          n.pageSize !== pagination.pageSize ? query.setPerPage(n.pageSize) : query.setPage(n.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(u) =>
          query.setSort(tableSortingToApiSort(typeof u === 'function' ? u(sorting) : u, itemQuantityUnitColumns))
        }
        rowActions={renderRowActions}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage={getApiErrorMessage(result.error) ?? "Couldn't load pack conversions."}
        emptyMessage="No pack conversions yet."
      />
    </div>
  );
}
