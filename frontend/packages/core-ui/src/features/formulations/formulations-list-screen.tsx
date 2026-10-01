import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { SlidersHorizontal } from 'lucide-react';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import {
  useCustomerAccountsIndex,
  useFormulationsIndex,
  useItemsIndex,
  type FormulationResource,
  type FormulationsIndexSort,
} from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns } from '../../components/data-table';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { lookupState, type LookupState } from '../../lib/api-errors';
import {
  apiSortToTableSorting,
  tableSortingToApiSort,
  useListQueryState,
  type ListQueryState,
} from '../../lib/list-query-state';
import { useListViewPreference } from '../../lib/use-view-preference';
import { formulationColumns } from './formulations-columns';
import { usePermission } from '../../hooks/use-permission';

interface Filters {
  search: string;
  status: string;
  output_item_id: string;
  customer_id: string;
  [key: string]: string;
}

const STATUSES = ['DRAFT', 'ACTIVE', 'ARCHIVED'] as const;

export function FormulationsListScreen() {
  const mayManage = usePermission('estimating.formulation.manage');
  const navigate = useNavigate();
  const query = useListQueryState<Filters>({ defaultSort: 'code' });
  const [searchInput, setSearchInput] = useState(query.filters.search ?? '');
  const [filtersOpen, setFiltersOpen] = useState(false);
  const { view, setView } = useListViewPreference('formulations-list');
  useEffect(() => {
    const timer = setTimeout(() => {
      if (searchInput !== (query.filters.search ?? '')) query.setFilter('search', searchInput || undefined);
    }, 300);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  const itemsQuery = useItemsIndex({ per_page: 100, sort: 'name' });
  const items = itemsQuery.data?.status === 200 ? itemsQuery.data.data.data : [];
  const customersQuery = useCustomerAccountsIndex({
    per_page: 100,
    sort: 'code',
  });
  const customers = customersQuery.data?.status === 200 ? customersQuery.data.data.data : [];
  const result = useFormulationsIndex({
    'filter[search]': query.filters.search || undefined,
    'filter[status]': query.filters.status || undefined,
    'filter[output_item_id]': query.filters.output_item_id ? Number(query.filters.output_item_id) : undefined,
    'filter[customer_id]': query.filters.customer_id ? Number(query.filters.customer_id) : undefined,
    include: 'outputItem,customer',
    sort: query.sort as FormulationsIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const columns = useMemo(() => toTanStackColumns(formulationColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, formulationColumns);
  const pagination: PaginationState = {
    pageIndex: query.page - 1,
    pageSize: query.perPage,
  };
  const activeFilterCount = [query.filters.status, query.filters.output_item_id, query.filters.customer_id].filter(
    Boolean,
  ).length;

  return (
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Formulations"
        description="Recipes: the inputs required to produce one unit of an output item."
        primaryAction={
          mayManage
            ? {
                label: 'New formulation',
                onClick: () => navigate('/formulations/new'),
              }
            : undefined
        }
      />
      <div className="flex flex-wrap items-center gap-2">
        <Input
          aria-label="Search formulations"
          placeholder="Search code or name…"
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          className="h-9 flex-1 md:w-56 md:flex-none"
        />
        <div className="hidden items-center gap-2 md:flex">
          <FormulationFilters
            query={query}
            items={items}
            itemsLookup={lookupState(itemsQuery, 'items')}
            customers={customers}
            customersLookup={lookupState(customersQuery, 'customers')}
          />
        </div>
        <Button variant="outline" size="sm" className="h-9 shrink-0 md:hidden" onClick={() => setFiltersOpen(true)}>
          <SlidersHorizontal className="h-4 w-4" />
          Filters
          {activeFilterCount > 0 && <Badge className="ml-1">{activeFilterCount}</Badge>}
        </Button>
      </div>
      <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
        <SheetContent side="bottom" className="md:hidden">
          <SheetHeader>
            <SheetTitle>Filters</SheetTitle>
          </SheetHeader>
          <div className="flex flex-col gap-3 py-4">
            <FormulationFilters
              query={query}
              items={items}
              itemsLookup={lookupState(itemsQuery, 'items')}
              customers={customers}
              customersLookup={lookupState(customersQuery, 'customers')}
              full
            />
          </div>
          <SheetFooter>
            <Button
              variant="ghost"
              onClick={() =>
                query.setFilters({
                  status: undefined,
                  output_item_id: undefined,
                  customer_id: undefined,
                })
              }
            >
              Clear filters
            </Button>
            <Button onClick={() => setFiltersOpen(false)}>Done</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>
      <DataTable
        columns={columns}
        data={rows}
        rowCount={total}
        pagination={pagination}
        onPaginationChange={(u) => {
          const n = typeof u === 'function' ? u(pagination) : u;
          n.pageSize !== pagination.pageSize ? query.setPerPage(n.pageSize) : query.setPage(n.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(u) =>
          query.setSort(tableSortingToApiSort(typeof u === 'function' ? u(sorting) : u, formulationColumns))
        }
        onRowClick={mayManage ? (row: FormulationResource) => navigate(`/formulations/${row.id}/edit`) : undefined}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load formulations."
        emptyMessage="No formulations yet."
        view={view}
        onViewChange={setView}
        cardColumns={formulationColumns}
      />
    </div>
  );
}

function FormulationFilters({
  query,
  items,
  itemsLookup,
  customers,
  customersLookup,
  full = false,
}: {
  query: ListQueryState<Filters>;
  items: { id: number; code: string; name: string }[];
  itemsLookup: LookupState;
  customers: { id: number; code: string }[];
  customersLookup: LookupState;
  full?: boolean;
}) {
  return (
    <>
      <Combobox
        {...itemsLookup}
        aria-label="Output item"
        className={full ? 'w-full' : 'w-48'}
        value={query.filters.output_item_id ?? 'all'}
        onValueChange={(value) => query.setFilter('output_item_id', value === 'all' ? undefined : value)}
        placeholder="Output item"
        searchPlaceholder="Search items…"
        emptyText="No items found."
        options={[
          { value: 'all', label: 'All output items' },
          ...items.map((item) => ({
            value: String(item.id),
            label: `${item.code} — ${item.name}`,
          })),
        ]}
      />
      <Combobox
        {...customersLookup}
        aria-label="Customer"
        className={full ? 'w-full' : 'w-44'}
        value={query.filters.customer_id ?? 'all'}
        onValueChange={(value) => query.setFilter('customer_id', value === 'all' ? undefined : value)}
        placeholder="Customer"
        searchPlaceholder="Search customers…"
        emptyText="No customers found."
        options={[
          { value: 'all', label: 'All customers' },
          ...customers.map((customer) => ({
            value: String(customer.id),
            label: customer.code,
          })),
        ]}
      />
      <Select
        value={query.filters.status ?? 'all'}
        onValueChange={(value) => query.setFilter('status', value === 'all' ? undefined : value)}
      >
        <SelectTrigger aria-label="Status" className={full ? 'h-9 w-full' : 'h-9 w-36'}>
          <SelectValue placeholder="Status" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All statuses</SelectItem>
          {STATUSES.map((status) => (
            <SelectItem key={status} value={status}>
              {status}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </>
  );
}
