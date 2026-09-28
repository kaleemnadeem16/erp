import { useEffect, useMemo, useState } from 'react';
import { SlidersHorizontal } from 'lucide-react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import { useCategoriesIndex, type CategoriesIndexSort, type CategoryResource } from '@erp/core-api';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, isColumnVisibleByDefault, toTanStackColumns } from '../../components/data-table';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import {
  apiSortToTableSorting,
  describeSort,
  tableSortingToApiSort,
  useListQueryState,
} from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useListViewPreference } from '../../lib/use-view-preference';
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
  const [visibility, setVisibility] = useColumnVisibilityPreference(
    'categories-list',
    Object.fromEntries(categoryColumns.map((c) => [c.key, isColumnVisibleByDefault(c)]))
  );
  const [filtersOpen, setFiltersOpen] = useState(false);
  const { view, setView } = useListViewPreference('categories-list');
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
  const activeFilterCount = [query.filters.parent_id].filter(Boolean).length;
  return (
    // Extra bottom padding below `md` clears the fixed MobileFab — see
    // PageHeader's `primaryAction` note in docs/architecture/user-interface.md.
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Categories"
        description="Hierarchical item groups and inherited valuation and account defaults."
        primaryAction={{ label: 'New category', onClick: () => navigate('/categories/new') }}
      />
      <div className="flex flex-wrap items-center gap-2">
        <Input
          className="h-9 flex-1 md:w-56 md:flex-none"
          placeholder="Search code or name…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <div className="hidden items-center gap-2 md:flex">
          <CategoryFilterControls parents={parents} query={query} />
          <PrintTrigger onPrint={print} />
        </div>
        <Button variant="outline" size="sm" className="h-9 shrink-0 md:hidden" onClick={() => setFiltersOpen(true)}>
          <SlidersHorizontal className="h-4 w-4" />
          Filters
          {activeFilterCount > 0 && <Badge className="ml-1">{activeFilterCount}</Badge>}
        </Button>
      </div>

      <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
        <SheetContent side="bottom" className="md:hidden">
          <SheetHeader>
            <SheetTitle>Filters</SheetTitle>
          </SheetHeader>
          <div className="flex flex-col gap-3 py-4">
            <CategoryFilterControls parents={parents} query={query} full />
            <PrintTrigger onPrint={print} className="w-full justify-center" />
          </div>
          <SheetFooter>
            <Button variant="ghost" onClick={() => query.setFilters({ parent_id: undefined })}>
              Clear filters
            </Button>
            <Button onClick={() => setFiltersOpen(false)}>Done</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>

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
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load categories."
        emptyMessage="No categories match these filters."
        view={view}
        onViewChange={setView}
        cardColumns={categoryColumns}
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

interface CategoryFilterControlsProps {
  parents: { id: number; name: string }[];
  query: ReturnType<typeof useListQueryState<Filters>>;
  full?: boolean;
}

/** Parent select — shared by the always-visible desktop row and the mobile Filters sheet. */
function CategoryFilterControls({ parents, query, full }: CategoryFilterControlsProps) {
  return (
    <Select
      value={query.filters.parent_id ?? 'all'}
      onValueChange={(v) => query.setFilter('parent_id', v === 'all' ? undefined : v)}
    >
      <SelectTrigger className={full ? 'h-9 w-full' : 'h-9 w-48'} aria-label="Parent">
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
  );
}
