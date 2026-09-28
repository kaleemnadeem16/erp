import { useMemo } from 'react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import { useAssembliesIndex, type AssemblyResource, type AssembliesIndexSort } from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns } from '../../components/data-table';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { assemblyColumns } from './assemblies-columns';

interface Filters {
  [key: string]: string;
}

export function AssembliesListScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<Filters>({ defaultSort: 'code' });
  const result = useAssembliesIndex({
    include: 'assemblyGroup',
    sort: query.sort as AssembliesIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const columns = useMemo(() => toTanStackColumns(assemblyColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, assemblyColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  return (
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Assemblies"
        description="Sellable units of work, priced per work unit — the recipe and cost roll-up land in a later step."
        primaryAction={{ label: 'New assembly', onClick: () => navigate('/assemblies/new') }}
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
          query.setSort(tableSortingToApiSort(typeof u === 'function' ? u(sorting) : u, assemblyColumns))
        }
        onRowClick={(row: AssemblyResource) => navigate(`/assemblies/${row.id}/edit`)}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load assemblies."
        emptyMessage="No assemblies yet."
      />
    </div>
  );
}
