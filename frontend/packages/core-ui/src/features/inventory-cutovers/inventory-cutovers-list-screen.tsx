import { useMemo, useState } from 'react';
import { Plus } from 'lucide-react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState, VisibilityState } from '@tanstack/react-table';
import { useInventoryCutoversIndex, type InventoryCutoverResource, type InventoryCutoversIndexSort } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns, isColumnVisibleByDefault } from '../../components/data-table';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { inventoryCutoverColumns } from './inventory-cutover-columns';

interface InventoryCutoverFilters {
  status: string;
  [key: string]: string;
}

const STATUSES = ['DRAFT', 'VALIDATED', 'APPROVED', 'FINALISED', 'FAILED'] as const;

export function InventoryCutoversListScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<InventoryCutoverFilters>({ defaultSort: '-created_at', defaultPerPage: 25 });
  const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(() =>
    Object.fromEntries(inventoryCutoverColumns.map((column) => [column.key, isColumnVisibleByDefault(column)]))
  );

  const cutoversQuery = useInventoryCutoversIndex({
    'filter[status]': (query.filters.status as 'DRAFT' | 'VALIDATED' | 'APPROVED' | 'FINALISED' | 'FAILED' | undefined) || undefined,
    sort: query.sort as InventoryCutoversIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });

  const cutovers = cutoversQuery.data?.status === 200 ? cutoversQuery.data.data.data : [];
  const meta = cutoversQuery.data?.status === 200 ? cutoversQuery.data.data.meta : undefined;

  const tanStackColumns = useMemo(() => toTanStackColumns(inventoryCutoverColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, inventoryCutoverColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="Inventory Cutovers"
        description="Bring in a predecessor system's opening stock balances under a controlled, auditable workflow."
        actions={
          <Button onClick={() => navigate('/inventory-cutovers/new')}>
            <Plus className="h-4 w-4" />
            New cutover
          </Button>
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <Select value={query.filters.status ?? 'all'} onValueChange={(value) => query.setFilter('status', value === 'all' ? undefined : value)}>
          <SelectTrigger className="h-9 w-40">
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
        data={cutovers}
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
          query.setSort(tableSortingToApiSort(next, inventoryCutoverColumns));
        }}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        onRowClick={(cutover: InventoryCutoverResource) => navigate(`/inventory-cutovers/${cutover.id}`)}
        isLoading={cutoversQuery.isLoading}
        isError={cutoversQuery.isError}
        errorMessage="Couldn't load inventory cutovers."
        emptyMessage="No inventory cutovers match these filters."
      />
    </div>
  );
}
