import { useMemo } from 'react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import { useRateDimensionsIndex, type RateDimensionResource, type RateDimensionsIndexSort } from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns } from '../../components/data-table';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { rateDimensionColumns } from './rate-dimensions-columns';
import { usePermission } from '../../hooks/use-permission';

interface Filters {
  [key: string]: string;
}

export function RateDimensionsListScreen() {
  const mayManage = usePermission('estimating.rate-dimension.manage');
  const navigate = useNavigate();
  const query = useListQueryState<Filters>({ defaultSort: 'sort_order' });
  const result = useRateDimensionsIndex({
    include: 'values',
    sort: query.sort as RateDimensionsIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const columns = useMemo(() => toTanStackColumns(rateDimensionColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, rateDimensionColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  return (
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Rate dimensions"
        description="Company-defined axes application rates can vary by — e.g. Surface Type, Thickness."
        primaryAction={mayManage ? { label: 'New dimension', onClick: () => navigate('/rate-dimensions/new') } : undefined}
      />
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
          query.setSort(tableSortingToApiSort(typeof u === 'function' ? u(sorting) : u, rateDimensionColumns))
        }
        onRowClick={mayManage ? (row: RateDimensionResource) => navigate(`/rate-dimensions/${row.id}/edit`) : undefined}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load rate dimensions."
        emptyMessage="No rate dimensions yet."
      />
    </div>
  );
}
