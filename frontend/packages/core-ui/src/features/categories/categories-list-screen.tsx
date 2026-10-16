import { useMemo, useState, type ReactNode } from 'react';
import { Layers, Pencil, Search, SlidersHorizontal, X } from 'lucide-react';
import { Link, useLocation, useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import { useCategoriesIndex, type CategoriesIndexSort, type CategoryResource } from '@erp/core-api';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { Tooltip, TooltipContent, TooltipTrigger } from '../../components/ui/tooltip';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { DataTable, isColumnVisibleByDefault, toTanStackColumns } from '../../components/data-table';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { cn } from '../../lib/utils';
import {
  apiSortToTableSorting,
  describeSort,
  tableSortingToApiSort,
  useListQueryState,
} from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useDebouncedSearch } from '../../lib/use-debounced-search';
import { useListViewPreference } from '../../lib/use-view-preference';
import { categoryColumns } from './categories-columns';
import { usePermission } from '../../hooks/use-permission';

interface Filters {
  parent_id: string;
  search: string;
  [key: string]: string;
}

export function CategoriesListScreen() {
  const mayManage = usePermission('inventory.category.manage');
  const navigate = useNavigate();
  const { search: listSearch } = useLocation();
  const query = useListQueryState<Filters>({ defaultSort: 'name' });

  const { input: searchInput, searchProps } = useDebouncedSearch(
    query.filters.search,
    (value) => query.setFilter('search', value),
  );

  const [visibility, setVisibility] = useColumnVisibilityPreference(
    'categories-list',
    Object.fromEntries(categoryColumns.map((c) => [c.key, isColumnVisibleByDefault(c)])),
  );
  const [filtersOpen, setFiltersOpen] = useState(false);
  const { view, setView } = useListViewPreference('categories-list');

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

  const activeChips = useMemo(() => {
    const chips: { id: string; icon: ReactNode; label: string; onRemove: () => void }[] = [];
    if (query.filters.search) {
      chips.push({
        id: 'search',
        icon: <Search className="h-3.5 w-3.5" />,
        label: `Search: "${query.filters.search}"`,
        onRemove: () => query.setFilter('search', undefined),
      });
    }
    if (query.filters.parent_id) {
      let label = 'Parent: Top-level only';
      if (query.filters.parent_id !== '0') {
        const parent = parents.find((p) => String(p.id) === query.filters.parent_id);
        label = `Parent: ${parent?.name ?? query.filters.parent_id}`;
      }
      chips.push({
        id: 'parent_id',
        icon: <Layers className="h-3.5 w-3.5" />,
        label,
        onRemove: () => query.setFilter('parent_id', undefined),
      });
    }
    return chips;
  }, [query.filters.search, query.filters.parent_id, parents, query]);

  const clearAllFilters = () => {
    query.setFilters({ parent_id: undefined, search: undefined });
  };

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Categories"
        description="Hierarchical item groups and inherited valuation and account defaults."
        primaryAction={
          mayManage
            ? {
                label: 'New category',
                onClick: () => navigate({ pathname: '/categories/new', search: listSearch }),
              }
            : undefined
        }
      />
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative flex-1 md:w-72 md:flex-none">
          <Search
            aria-hidden="true"
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
          />
          <Input
            className="h-10 pl-9 pr-9"
            placeholder="Search code or name…"
            aria-label="Search categories"
            {...searchProps}
          />
          {searchInput && (
            <button
              type="button"
              onClick={() => query.setFilter('search', undefined)}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer"
              aria-label="Clear search"
            >
              <X className="h-4 w-4" />
            </button>
          )}
        </div>
        <div className="hidden items-center gap-2 md:flex">
          <CategoryFilterControls parents={parents} query={query} />
          <PrintTrigger onPrint={print} />
        </div>
        <Button
          variant={filtersOpen || activeFilterCount > 0 ? 'secondary' : 'outline'}
          size="sm"
          className={cn(
            'relative h-10 px-3 shrink-0 md:hidden',
            (filtersOpen || activeFilterCount > 0) && 'ui-btn-filters-active',
          )}
          onClick={() => setFiltersOpen(true)}
          aria-label="Open filter settings"
        >
          <SlidersHorizontal className="h-4 w-4 mr-1.5" />
          Filters
          {activeFilterCount > 0 && <Badge className="ml-1.5 ui-filter-badge">{activeFilterCount}</Badge>}
        </Button>
      </div>

      <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
        <SheetContent side="bottom" className="md:hidden">
          <SheetHeader>
            <SheetTitle>Filters</SheetTitle>
          </SheetHeader>
          <div className="flex flex-col gap-3 py-4">
            <CategoryFilterControls parents={parents} query={query} full />
            <PrintTrigger onPrint={print} className="w-full justify-center h-10" />
          </div>
          <SheetFooter className="gap-2 sm:space-x-0">
            <Button
              variant="outline"
              onClick={() => {
                clearAllFilters();
                setFiltersOpen(false);
              }}
            >
              Clear filters
            </Button>
            <Button onClick={() => setFiltersOpen(false)}>Done</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>

      {activeChips.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 pt-1">
          <span className="hidden text-sm font-medium md:inline">Active filters</span>
          {activeChips.map((chip) => (
            <Badge
              key={chip.id}
              variant="secondary"
              className="ui-chip-active h-7 gap-1.5 pl-2.5 pr-1.5 text-xs font-normal"
            >
              {chip.icon}
              <span>{chip.label}</span>
              <button
                type="button"
                className="ml-0.5 rounded-full p-0.5 hover:bg-muted-foreground/20 cursor-pointer"
                aria-label={`Remove filter ${chip.label}`}
                onClick={chip.onRemove}
              >
                <X className="h-3 w-3" />
              </button>
            </Badge>
          ))}
          <Button
            variant="ghost"
            size="sm"
            className="h-7 text-xs text-muted-foreground hover:text-foreground"
            onClick={clearAllFilters}
          >
            Clear all
          </Button>
        </div>
      )}

      <DataTable
        columns={columns}
        data={rows}
        rowCount={total}
        recordNoun="categories"
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
        onRowClick={(row: CategoryResource) => navigate({ pathname: `/categories/${row.id}`, search: listSearch })}
        rowActions={(category: CategoryResource) =>
          mayManage ? (
            <Tooltip>
              <TooltipTrigger asChild>
                <Button variant="ghost" size="icon" className="h-8 w-8" aria-label="Edit" asChild>
                  <Link to={{ pathname: `/categories/${category.id}/edit`, search: listSearch }}>
                    <Pencil className="h-4 w-4" />
                  </Link>
                </Button>
              </TooltipTrigger>
              <TooltipContent>Edit</TooltipContent>
            </Tooltip>
          ) : null
        }
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
    </ScreenTemplate>
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
      <SelectTrigger className={full ? 'h-10 w-full' : 'h-10 w-48'} aria-label="Parent">
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

