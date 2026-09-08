import { useMemo, useState } from 'react';
import { Plus } from 'lucide-react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState, VisibilityState } from '@tanstack/react-table';
import {
  useNegativeStockOverrideRequestsIndex,
  type NegativeStockOverrideRequestResource,
  type NegativeStockOverrideRequestsIndexSort,
} from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns, isColumnVisibleByDefault } from '../../components/data-table';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { negativeStockOverrideColumns } from './negative-stock-override-columns';

interface NegativeStockOverrideFilters {
  movement_class: string;
  authorisation_mode: string;
  [key: string]: string;
}

const MOVEMENT_CLASSES = ['SALES_DESPATCH', 'MATERIAL_CONSUMPTION', 'SUPPLIER_RETURN', 'SCRAP_WRITEOFF'] as const;
const AUTHORISATION_MODES = ['PRIOR_APPROVAL', 'IMMEDIATE_WITH_REVIEW'] as const;

export function NegativeStockOverridesListScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<NegativeStockOverrideFilters>({ defaultSort: '-requested_at', defaultPerPage: 25 });
  const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(() =>
    Object.fromEntries(negativeStockOverrideColumns.map((column) => [column.key, isColumnVisibleByDefault(column)]))
  );

  const overridesQuery = useNegativeStockOverrideRequestsIndex({
    'filter[movement_class]': (query.filters.movement_class as (typeof MOVEMENT_CLASSES)[number] | undefined) || undefined,
    'filter[authorisation_mode]': (query.filters.authorisation_mode as (typeof AUTHORISATION_MODES)[number] | undefined) || undefined,
    sort: query.sort as NegativeStockOverrideRequestsIndexSort | undefined,
    include: 'item',
    per_page: query.perPage,
    page: query.page,
  });

  const overrides = overridesQuery.data?.status === 200 ? overridesQuery.data.data.data : [];
  const meta = overridesQuery.data?.status === 200 ? overridesQuery.data.data.meta : undefined;

  const tanStackColumns = useMemo(() => toTanStackColumns(negativeStockOverrideColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, negativeStockOverrideColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="Negative-Stock Overrides"
        description="Authorisation queue for a genuine shortage — requested, then decided or reviewed depending on company policy."
        actions={
          <Button onClick={() => navigate('/negative-stock-overrides/new')}>
            <Plus className="h-4 w-4" />
            New request
          </Button>
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <Select
          value={query.filters.movement_class ?? 'all'}
          onValueChange={(value) => query.setFilter('movement_class', value === 'all' ? undefined : value)}
        >
          <SelectTrigger className="h-9 w-52">
            <SelectValue placeholder="Movement class" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All movement classes</SelectItem>
            {MOVEMENT_CLASSES.map((movementClass) => (
              <SelectItem key={movementClass} value={movementClass}>
                {movementClass}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select
          value={query.filters.authorisation_mode ?? 'all'}
          onValueChange={(value) => query.setFilter('authorisation_mode', value === 'all' ? undefined : value)}
        >
          <SelectTrigger className="h-9 w-48">
            <SelectValue placeholder="Authorisation mode" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All modes</SelectItem>
            {AUTHORISATION_MODES.map((mode) => (
              <SelectItem key={mode} value={mode}>
                {mode}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <DataTable
        columns={tanStackColumns}
        data={overrides}
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
          query.setSort(tableSortingToApiSort(next, negativeStockOverrideColumns));
        }}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        onRowClick={(override: NegativeStockOverrideRequestResource) => navigate(`/negative-stock-overrides/${override.id}`)}
        isLoading={overridesQuery.isLoading}
        isError={overridesQuery.isError}
        errorMessage="Couldn't load negative-stock override requests."
        emptyMessage="No negative-stock override requests match these filters."
      />
    </div>
  );
}
