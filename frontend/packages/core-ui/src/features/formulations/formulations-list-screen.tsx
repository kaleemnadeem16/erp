import { useMemo } from 'react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import { useFormulationsIndex, type FormulationResource, type FormulationsIndexSort } from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns } from '../../components/data-table';
import { apiSortToTableSorting, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { formulationColumns } from './formulations-columns';

interface Filters {
  [key: string]: string;
}

export function FormulationsListScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<Filters>({ defaultSort: 'code' });
  const result = useFormulationsIndex({
    include: 'outputItem,customer',
    sort: query.sort as FormulationsIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const columns = useMemo(() => toTanStackColumns(formulationColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, formulationColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  return (
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Formulations"
        description="Recipes: the inputs required to produce one unit of an output item."
        primaryAction={{ label: 'New formulation', onClick: () => navigate('/formulations/new') }}
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
          query.setSort(tableSortingToApiSort(typeof u === 'function' ? u(sorting) : u, formulationColumns))
        }
        onRowClick={(row: FormulationResource) => navigate(`/formulations/${row.id}/edit`)}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load formulations."
        emptyMessage="No formulations yet."
      />
    </div>
  );
}
