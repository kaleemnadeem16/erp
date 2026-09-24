import { useMemo } from 'react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import { useAssemblyGroupsIndex, type AssemblyGroupResource, type AssemblyGroupsIndexSort } from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns } from '../../components/data-table';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { assemblyGroupColumns } from './assembly-groups-columns';

interface Filters {
  [key: string]: string;
}

export function AssemblyGroupsListScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<Filters>({ defaultSort: 'code' });
  const result = useAssemblyGroupsIndex({
    sort: query.sort as AssemblyGroupsIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const columns = useMemo(() => toTanStackColumns(assemblyGroupColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, assemblyGroupColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  return (
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Assembly groups"
        description="Company-defined classifications of assemblies, used to share overhead-policy configuration."
        primaryAction={{ label: 'New group', onClick: () => navigate('/assembly-groups/new') }}
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
          query.setSort(tableSortingToApiSort(typeof u === 'function' ? u(sorting) : u, assemblyGroupColumns))
        }
        onRowClick={(row: AssemblyGroupResource) => navigate(`/assembly-groups/${row.id}/edit`)}
        isLoading={result.isLoading}
        isError={result.isError}
        errorMessage="Couldn't load assembly groups."
        emptyMessage="No assembly groups yet."
      />
    </div>
  );
}
