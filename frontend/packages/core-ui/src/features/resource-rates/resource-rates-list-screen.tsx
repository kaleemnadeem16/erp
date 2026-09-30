import { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Trash2 } from 'lucide-react';
import type { SortingState } from '@tanstack/react-table';
import {
  useItemsIndex,
  useResourceRatesDestroy,
  useResourceRatesIndex,
  useResourceRatesStore,
  useUomsIndex,
  type ResourceRateResource,
} from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns } from '../../components/data-table';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Tooltip, TooltipContent, TooltipTrigger } from '../../components/ui/tooltip';
import { Combobox } from '../../components/ui/combobox';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '../../components/ui/form';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { applyApiErrorsToForm, getApiErrorMessage, lookupState } from '../../lib/api-errors';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { resourceRateColumns } from './resource-rates-columns';
import { usePermission } from '../../hooks/use-permission';

const schema = z.object({
  item_id: z.string().min(1, 'Item is required'),
  rate: z.string().refine((v) => /^\d{1,9}(\.\d{1,6})?$/.test(v) && Number(v) >= 0, 'Enter a rate of 0 or more'),
  rate_uom_id: z.string().min(1, 'Unit is required'),
  effective_from: z.string().min(1, 'Effective date is required'),
});
type FormValues = z.infer<typeof schema>;
const FORM_FIELDS = ['item_id', 'rate', 'rate_uom_id', 'effective_from'] as const;

interface Filters {
  [key: string]: string;
}

/**
 * Flat, item-keyed master data with no update endpoint — a rate change is
 * always a new dated row, see ResourceRateController. No dedicated edit
 * screen for the same reason; delete is the only row action.
 */
export function ResourceRatesListScreen() {
  const mayManage = usePermission('estimating.resource-rate.manage');
  const query = useListQueryState<Filters>({ defaultSort: '-effective_from' });
  const result = useResourceRatesIndex({
    include: 'item,rateUom',
    sort: query.sort as 'effective_from' | '-effective_from' | 'created_at' | '-created_at' | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const columns = useMemo(() => toTanStackColumns(resourceRateColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, resourceRateColumns);

  const itemsResult = useItemsIndex({ per_page: 100, sort: 'name' });
  const items = itemsResult.data?.status === 200 ? itemsResult.data.data.data : [];
  const labourAndPlantItems = items.filter((i) => i.type === 'LABOUR' || i.type === 'PLANT');
  const uomsResult = useUomsIndex({ per_page: 100, sort: 'name' });
  const uoms = uomsResult.data?.status === 200 ? uomsResult.data.data.data : [];

  const storeMutation = useResourceRatesStore();
  const deleteMutation = useResourceRatesDestroy();
  const [formError, setFormError] = useState<string | null>(null);

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { item_id: '', rate: '', rate_uom_id: '', effective_from: new Date().toISOString().slice(0, 10) },
  });

  function submit(values: FormValues) {
    setFormError(null);
    storeMutation.mutate(
      {
        data: {
          item_id: Number(values.item_id), rate: values.rate.trim(),
          rate_uom_id: Number(values.rate_uom_id), effective_from: values.effective_from,
        },
      },
      {
        onSuccess: () => {
          form.reset({ item_id: '', rate: '', rate_uom_id: '', effective_from: values.effective_from });
          void result.refetch();
        },
        onError: (e) => setFormError(applyApiErrorsToForm(e, form.setError, FORM_FIELDS)),
      }
    );
  }

  function renderRowActions(row: ResourceRateResource) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <Button
            variant="ghost"
            size="icon"
            className="h-8 w-8"
            aria-label="Delete"
            onClick={() =>
              deleteMutation.mutate({ resourceRate: row.id }, { onSuccess: () => void result.refetch() })
            }
          >
            <Trash2 className="h-4 w-4" />
          </Button>
        </TooltipTrigger>
        <TooltipContent>Delete</TooltipContent>
      </Tooltip>
    );
  }

  return (
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Resource rates"
        description="The standard estimating rate for a LABOUR or PLANT item, effective as of a date — the source Assembly component costing resolves from. A correction is a new dated row, not an edit to an old one."
      />

      {mayManage && <DetailSection title="Add a rate">
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
                    <Combobox {...lookupState(itemsResult, 'items')}
                      ref={field.ref}
                      value={field.value}
                      onValueChange={field.onChange}
                      options={labourAndPlantItems.map((i) => ({ value: String(i.id), label: `${i.code} — ${i.name}` }))}
                      placeholder="Select LABOUR/PLANT item"
                      searchPlaceholder="Search items…"
                      emptyText="No LABOUR/PLANT items found."
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="rate"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Rate</FormLabel>
                  <FormControl>
                    <Input inputMode="decimal" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="rate_uom_id"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Per unit</FormLabel>
                  <FormControl>
                    <Combobox {...lookupState(uomsResult, 'units')}
                      ref={field.ref}
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
              name="effective_from"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Effective from</FormLabel>
                  <FormControl>
                    <Input type="date" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <div className="flex justify-end sm:col-span-4">
              <Button type="submit" disabled={storeMutation.isPending}>
                {storeMutation.isPending ? 'Saving…' : 'Add rate'}
              </Button>
            </div>
          </form>
        </Form>
      </DetailSection>}

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
          query.setSort(tableSortingToApiSort(typeof u === 'function' ? u(sorting) : u, resourceRateColumns))
        }
        rowActions={mayManage ? renderRowActions : undefined}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage={getApiErrorMessage(result.error) ?? "Couldn't load resource rates."}
        emptyMessage="No resource rates yet."
      />
    </div>
  );
}
