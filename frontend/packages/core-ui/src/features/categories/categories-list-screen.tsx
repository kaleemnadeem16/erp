import { useEffect, useMemo, useState } from 'react';
import { Plus } from 'lucide-react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState, VisibilityState } from '@tanstack/react-table';
import { useCategoriesIndex, type CategoriesIndexSort, type CategoryResource } from '@erp/core-api';
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
import { categoryColumns } from './categories-columns';

interface Filters {
  parent_id: string;
  search: string;
  [key: string]: string;
}

export function CategoriesListScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<Filters>({ defaultSort: 'name' });
  const [search, setSearch] = useState(query.filters.search ?? '');
  const [visibility, setVisibility] = useState<VisibilityState>(() =>
    Object.fromEntries(categoryColumns.map((c) => [c.key, isColumnVisibleByDefault(c)]))
  );
  useEffect(() => {
    const handle = setTimeout(() => {
      if (search !== (query.filters.search ?? '')) query.setFilter('search', search || undefined);
    }, 300);
    return () => clearTimeout(handle);
  }, [search]); // eslint-disable-line react-hooks/exhaustive-deps
  const parentsQuery = useCategoriesIndex({ per_page: 100, sort: 'name' });
  const parents = parentsQuery.data?.status === 200 ? parentsQuery.data.data.data : [];
  const result = useCategoriesIndex({
    'filter[parent_id]': query.filters.parent_id ? Number(query.filters.parent_id) : undefined,
    'filter[search]': query.filters.search || undefined,
    include: 'parent',
    sort: query.sort as CategoriesIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const columns = useMemo(() => toTanStackColumns(categoryColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, categoryColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const { contentRef, print } = useQuickPrint({ documentTitle: 'Category List' });
  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="Categories"
        description="Hierarchical item groups and inherited valuation and account defaults."
        actions={
          <Button onClick={() => navigate('/categories/new')}>
            <Plus className="h-4 w-4" />
            New category
          </Button>
        }
      />
      <div className="flex flex-wrap gap-2">
        <Input
          className="h-9 w-56"
          placeholder="Search code or name…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <Select
          value={query.filters.parent_id ?? 'all'}
          onValueChange={(v) => query.setFilter('parent_id', v === 'all' ? undefined : v)}
        >
          <SelectTrigger className="h-9 w-48">
            <SelectValue placeholder="Parent" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All categories</SelectItem>
            <SelectItem value="0">Top-level only</SelectItem>
            {parents.map((p) => (
              <SelectItem key={p.id} value={String(p.id)}>
                Under {p.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <div className="ml-auto">
          <PrintTrigger onPrint={print} />
        </div>
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
          query.setSort(tableSortingToApiSort(typeof u === 'function' ? u(sorting) : u, categoryColumns))
        }
        columnVisibility={visibility}
        onColumnVisibilityChange={setVisibility}
        onRowClick={(row: CategoryResource) => navigate(`/categories/${row.id}`)}
        isLoading={result.isLoading}
        isError={result.isError}
        errorMessage="Couldn't load categories."
        emptyMessage="No categories match these filters."
      />
      <div className="hidden">
        <div ref={contentRef}>
          <PrintLayout
            title="CATEGORY LIST"
            printedAt={new Date()}
            recordCount={rows.length}
            meta={query.sort ? [{ label: 'Sort', value: describeSort(query.sort, categoryColumns) ?? query.sort }] : []}
          >
            <PrintableTable columns={categoryColumns} rows={rows} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
    </div>
  );
}
