import { useEffect, useMemo, useState } from 'react';
import { Plus } from 'lucide-react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState, VisibilityState } from '@tanstack/react-table';
import { useWarehousesIndex, type WarehouseResource, type WarehousesIndexSort } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, isColumnVisibleByDefault, toTanStackColumns } from '../../components/data-table';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import {
  apiSortToTableSorting,
  describeSort,
  tableSortingToApiSort,
  useListQueryState,
} from '../../lib/list-query-state';
import { warehouseColumns } from './warehouses-columns';

interface WarehouseFilters {
  active: string;
  search: string;
  [key: string]: string;
}

export function WarehousesListScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<WarehouseFilters>({
    defaultSort: 'name',
    defaultPerPage: 25,
  });
  const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(() =>
    Object.fromEntries(warehouseColumns.map((column) => [column.key, isColumnVisibleByDefault(column)]))
  );
  const [searchInput, setSearchInput] = useState(query.filters.search ?? '');

  useEffect(() => {
    const handle = setTimeout(() => {
      if (searchInput !== (query.filters.search ?? '')) query.setFilter('search', searchInput || undefined);
    }, 300);

    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  const warehousesQuery = useWarehousesIndex({
    'filter[active]': query.filters.active ? query.filters.active === 'true' : undefined,
    'filter[search]': query.filters.search || undefined,
    sort: query.sort as WarehousesIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const warehouses = warehousesQuery.data?.status === 200 ? warehousesQuery.data.data.data : [];
  const meta = warehousesQuery.data?.status === 200 ? warehousesQuery.data.data.meta : undefined;

  const columns = useMemo(() => toTanStackColumns(warehouseColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, warehouseColumns);
  const pagination: PaginationState = {
    pageIndex: query.page - 1,
    pageSize: query.perPage,
  };
  const { contentRef: printRef, print } = useQuickPrint({
    documentTitle: 'Warehouse List',
  });
  const filters = [
    ...(query.filters.active ? [`Status = ${query.filters.active === 'true' ? 'Active' : 'Inactive'}`] : []),
    ...(query.filters.search ? [`Search = "${query.filters.search}"`] : []),
  ];

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="Warehouses"
        description="Places where the company receives, stores and dispatches stock."
        actions={
          <Button onClick={() => navigate('/warehouses/new')}>
            <Plus className="h-4 w-4" />
            New warehouse
          </Button>
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <Input
          placeholder="Search code or name…"
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          className="h-9 w-56"
        />
        <Select
          value={query.filters.active ?? 'all'}
          onValueChange={(value) => query.setFilter('active', value === 'all' ? undefined : value)}
        >
          <SelectTrigger className="h-9 w-36">
            <SelectValue placeholder="Status" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            <SelectItem value="true">Active</SelectItem>
            <SelectItem value="false">Inactive</SelectItem>
          </SelectContent>
        </Select>
        <div className="ml-auto">
          <PrintTrigger onPrint={() => print()} />
        </div>
      </div>

      <DataTable
        columns={columns}
        data={warehouses}
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
          query.setSort(tableSortingToApiSort(next, warehouseColumns));
        }}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        onRowClick={(warehouse: WarehouseResource) => navigate(`/warehouses/${warehouse.id}`)}
        isLoading={warehousesQuery.isLoading}
        isError={warehousesQuery.isError}
        errorMessage="Couldn't load warehouses."
        emptyMessage="No warehouses match these filters."
      />

      <div className="hidden">
        <div ref={printRef}>
          <PrintLayout
            title="WAREHOUSE LIST"
            printedAt={new Date()}
            recordCount={warehouses.length}
            meta={[
              ...(filters.length ? [{ label: 'Filters', value: filters.join(', ') }] : []),
              ...(query.sort
                ? [
                    {
                      label: 'Sort',
                      value: describeSort(query.sort, warehouseColumns) ?? query.sort,
                    },
                  ]
                : []),
            ]}
          >
            <PrintableTable columns={warehouseColumns} rows={warehouses} getRowKey={(warehouse) => warehouse.id} />
          </PrintLayout>
        </div>
      </div>
    </div>
  );
}
