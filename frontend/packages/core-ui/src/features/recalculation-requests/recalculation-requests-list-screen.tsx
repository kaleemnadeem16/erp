import { useMemo, useState } from 'react';
import { Plus } from 'lucide-react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState, VisibilityState } from '@tanstack/react-table';
import {
  useRecalculationRequestsIndex,
  type RecalculationRequestResource,
  type RecalculationRequestsIndexSort,
} from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns, isColumnVisibleByDefault } from '../../components/data-table';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { recalculationRequestColumns } from './recalculation-request-columns';

interface RecalculationRequestFilters {
  movement_kind: string;
  [key: string]: string;
}

const MOVEMENT_KINDS = ['RECEIPT', 'ISSUE', 'TRANSFER'] as const;

export function RecalculationRequestsListScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<RecalculationRequestFilters>({ defaultSort: '-requested_at', defaultPerPage: 25 });
  const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(() =>
    Object.fromEntries(recalculationRequestColumns.map((column) => [column.key, isColumnVisibleByDefault(column)]))
  );

  const requestsQuery = useRecalculationRequestsIndex({
    'filter[movement_kind]': (query.filters.movement_kind as (typeof MOVEMENT_KINDS)[number] | undefined) || undefined,
    sort: query.sort as RecalculationRequestsIndexSort | undefined,
    include: 'item',
    per_page: query.perPage,
    page: query.page,
  });

  const requests = requestsQuery.data?.status === 200 ? requestsQuery.data.data.data : [];
  const meta = requestsQuery.data?.status === 200 ? requestsQuery.data.data.meta : undefined;

  const tanStackColumns = useMemo(() => toTanStackColumns(recalculationRequestColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, recalculationRequestColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="Stock Recalculation"
        description="ADR 0040's backdated correction workflow — request, decide, and execute a correction for a receipt, issue or transfer that conflicts with later valuation history."
        actions={
          <Button onClick={() => navigate('/recalculation-requests/new')}>
            <Plus className="h-4 w-4" />
            New request
          </Button>
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <Select
          value={query.filters.movement_kind ?? 'all'}
          onValueChange={(value) => query.setFilter('movement_kind', value === 'all' ? undefined : value)}
        >
          <SelectTrigger className="h-9 w-44">
            <SelectValue placeholder="Movement kind" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All movement kinds</SelectItem>
            {MOVEMENT_KINDS.map((kind) => (
              <SelectItem key={kind} value={kind}>
                {kind}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <DataTable
        columns={tanStackColumns}
        data={requests}
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
          query.setSort(tableSortingToApiSort(next, recalculationRequestColumns));
        }}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        onRowClick={(request: RecalculationRequestResource) => navigate(`/recalculation-requests/${request.id}`)}
        isLoading={requestsQuery.isLoading}
        isError={requestsQuery.isError}
        errorMessage="Couldn't load stock recalculation requests."
        emptyMessage="No stock recalculation requests match these filters."
      />
    </div>
  );
}
