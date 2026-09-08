import { useMemo, useState } from 'react';
import { Plus } from 'lucide-react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState, VisibilityState } from '@tanstack/react-table';
import { useWarehousesIndex, usePhysicalCountsIndex, type PhysicalCountResource, type PhysicalCountsIndexSort } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns, isColumnVisibleByDefault } from '../../components/data-table';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { physicalCountColumns } from './physical-count-columns';

interface PhysicalCountFilters {
  warehouse_id: string;
  status: string;
  [key: string]: string;
}

const STATUSES = ['DRAFT', 'POSTED', 'CANCELLED'] as const;

export function PhysicalCountsListScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<PhysicalCountFilters>({ defaultSort: '-created_at', defaultPerPage: 25 });
  const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(() =>
    Object.fromEntries(physicalCountColumns.map((column) => [column.key, isColumnVisibleByDefault(column)]))
  );

  const warehousesQuery = useWarehousesIndex({ per_page: 100, sort: 'name' });
  const warehouses = warehousesQuery.data?.status === 200 ? warehousesQuery.data.data.data : [];

  const countsQuery = usePhysicalCountsIndex({
    'filter[warehouse_id]': query.filters.warehouse_id ? Number(query.filters.warehouse_id) : undefined,
    'filter[status]': (query.filters.status as 'DRAFT' | 'POSTED' | 'CANCELLED' | undefined) || undefined,
    sort: query.sort as PhysicalCountsIndexSort | undefined,
    include: 'warehouse',
    per_page: query.perPage,
    page: query.page,
  });

  const counts = countsQuery.data?.status === 200 ? countsQuery.data.data.data : [];
  const meta = countsQuery.data?.status === 200 ? countsQuery.data.data.meta : undefined;

  const tanStackColumns = useMemo(() => toTanStackColumns(physicalCountColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, physicalCountColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="Physical Counts"
        description="Reconcile recorded stock against what's physically on hand, and post the difference as an auditable correction."
        actions={
          <Button onClick={() => navigate('/physical-counts/new')}>
            <Plus className="h-4 w-4" />
            New count
          </Button>
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <Select
          value={query.filters.warehouse_id ?? 'all'}
          onValueChange={(value) => query.setFilter('warehouse_id', value === 'all' ? undefined : value)}
        >
          <SelectTrigger className="h-9 w-44">
            <SelectValue placeholder="Warehouse" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All warehouses</SelectItem>
            {warehouses.map((warehouse) => (
              <SelectItem key={warehouse.id} value={String(warehouse.id)}>
                {warehouse.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select value={query.filters.status ?? 'all'} onValueChange={(value) => query.setFilter('status', value === 'all' ? undefined : value)}>
          <SelectTrigger className="h-9 w-36">
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
      </div>

      <DataTable
        columns={tanStackColumns}
        data={counts}
        rowCount={meta?.total ?? 0}
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next = typeof updater === 'function' ? updater(pagination) : updater;
          if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(updater) => {
          const next = typeof updater === 'function' ? updater(sorting) : updater;
          query.setSort(tableSortingToApiSort(next, physicalCountColumns));
        }}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        onRowClick={(count: PhysicalCountResource) => navigate(`/physical-counts/${count.id}`)}
        isLoading={countsQuery.isLoading}
        isError={countsQuery.isError}
        errorMessage="Couldn't load physical counts."
        emptyMessage="No physical counts match these filters."
      />
    </div>
  );
}
