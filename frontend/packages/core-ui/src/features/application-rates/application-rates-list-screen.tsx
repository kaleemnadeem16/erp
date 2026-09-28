import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import {
  useApplicationRatesIndex,
  useItemsIndex,
  type ApplicationRateResource,
  type ApplicationRatesIndexSort,
} from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns } from '../../components/data-table';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { applicationRateColumns } from './application-rates-columns';
import { ApplicationRateResolutionTester } from './application-rate-resolution-tester';

interface Filters {
  item_id: string;
  [key: string]: string;
}

export function ApplicationRatesListScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<Filters>({ defaultSort: 'created_at' });
  const [testerOpen, setTesterOpen] = useState(false);
  const itemsResult = useItemsIndex({ per_page: 100, sort: 'name' });
  const items = itemsResult.data?.status === 200 ? itemsResult.data.data.data : [];
  const result = useApplicationRatesIndex({
    'filter[item_id]': query.filters.item_id ? Number(query.filters.item_id) : undefined,
    include: 'item,workUom,uom',
    sort: query.sort as ApplicationRatesIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const columns = useMemo(() => toTanStackColumns(applicationRateColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, applicationRateColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  return (
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Application rates"
        description="How much of an item is consumed per one unit of work, by context."
        primaryAction={{ label: 'New rate', onClick: () => navigate('/application-rates/new') }}
        actions={
          <Button type="button" variant="outline" onClick={() => setTesterOpen((v) => !v)}>
            {testerOpen ? 'Hide resolution tester' : 'Test resolution'}
          </Button>
        }
      />
      {testerOpen && <ApplicationRateResolutionTester />}
      <div className="flex flex-wrap items-center gap-2">
        <Combobox
          className="h-9 w-64"
          aria-label="Item"
          value={query.filters.item_id ?? 'all'}
          onValueChange={(v) => query.setFilter('item_id', v === 'all' ? undefined : v)}
          placeholder="Item"
          searchPlaceholder="Search items…"
          emptyText="No items found."
          options={[
            { value: 'all', label: 'All items' },
            ...items.map((i) => ({ value: String(i.id), label: `${i.code} — ${i.name}` })),
          ]}
        />
      </div>
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
          query.setSort(tableSortingToApiSort(typeof u === 'function' ? u(sorting) : u, applicationRateColumns))
        }
        onRowClick={(row: ApplicationRateResource) => navigate(`/application-rates/${row.id}/edit`)}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load application rates."
        emptyMessage="No application rates yet."
      />
    </div>
  );
}
