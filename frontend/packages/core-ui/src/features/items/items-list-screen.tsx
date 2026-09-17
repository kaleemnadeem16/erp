import { useEffect, useMemo, useState } from 'react';
import { Archive, ArchiveRestore, Pencil, SlidersHorizontal } from 'lucide-react';
import { Link, useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import { useCategoriesIndex, useItemsIndex, useItemsUpdate, type ItemResource, type ItemsIndexSort } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Combobox } from '../../components/ui/combobox';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns, isColumnVisibleByDefault } from '../../components/data-table';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint } from '../../components/print';
import { getApiErrorMessage } from '../../lib/api-errors';
import {
  apiSortToTableSorting,
  describeSort,
  tableSortingToApiSort,
  useListQueryState,
  type ListQueryState,
} from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useListViewPreference } from '../../lib/use-view-preference';
import { itemColumns } from './items-columns';

interface ItemFilters {
  category_id: string;
  type: string;
  status: string;
  search: string;
  [key: string]: string;
}

const ITEM_TYPES = ['RAW', 'SEMI_FINISHED', 'FINISHED', 'SERVICE', 'LABOUR', 'PLANT'] as const;

export function ItemsListScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<ItemFilters>({ defaultSort: 'name', defaultPerPage: 25 });
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    'items-list',
    Object.fromEntries(itemColumns.map((column) => [column.key, isColumnVisibleByDefault(column)]))
  );
  const [searchInput, setSearchInput] = useState(query.filters.search ?? '');
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [statusError, setStatusError] = useState<string | null>(null);
  const { view, setView } = useListViewPreference('items-list');
  const updateStatus = useItemsUpdate();

  // Debounce free-text search so every keystroke doesn't round-trip to the API —
  // the URL (and therefore the API call) only updates once typing pauses.
  useEffect(() => {
    const handle = setTimeout(() => {
      if (searchInput !== (query.filters.search ?? '')) query.setFilter('search', searchInput || undefined);
    }, 300);

    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  const categoriesQuery = useCategoriesIndex({ per_page: 100, sort: 'name' });
  const categories = categoriesQuery.data?.status === 200 ? categoriesQuery.data.data.data : [];

  const itemsQuery = useItemsIndex({
    'filter[category_id]': query.filters.category_id ? Number(query.filters.category_id) : undefined,
    'filter[type]': (query.filters.type as (typeof ITEM_TYPES)[number] | undefined) || undefined,
    'filter[status]': (query.filters.status as 'ACTIVE' | 'ARCHIVED' | undefined) || undefined,
    'filter[search]': query.filters.search || undefined,
    sort: query.sort as ItemsIndexSort | undefined,
    include: 'category',
    per_page: query.perPage,
    page: query.page,
  });

  const items = itemsQuery.data?.status === 200 ? itemsQuery.data.data.data : [];
  const meta = itemsQuery.data?.status === 200 ? itemsQuery.data.data.meta : undefined;

  const tanStackColumns = useMemo(() => toTanStackColumns(itemColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, itemColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  const activeFilterLines = describeFilters(query.filters, categories);
  const activeFilterCount = [query.filters.category_id, query.filters.type, query.filters.status].filter(
    Boolean
  ).length;
  const { contentRef: printRef, print } = useQuickPrint({ documentTitle: 'Item List' });

  function handleToggleStatus(item: ItemResource) {
    setStatusError(null);
    const nextStatus = item.status === 'ACTIVE' ? 'ARCHIVED' : 'ACTIVE';
    updateStatus.mutate(
      { item: item.id, data: { status: nextStatus } },
      { onSuccess: () => itemsQuery.refetch(), onError: (error) => setStatusError(getApiErrorMessage(error)) }
    );
  }

  function renderRowActions(item: ItemResource) {
    return (
      <>
        <Button variant="ghost" size="icon" className="h-8 w-8" asChild>
          <Link to={`/items/${item.id}/edit`} aria-label="Edit">
            <Pencil className="h-4 w-4" />
          </Link>
        </Button>
        <Button
          variant="ghost"
          size="icon"
          className="h-8 w-8"
          onClick={() => handleToggleStatus(item)}
          aria-label={item.status === 'ACTIVE' ? 'Archive' : 'Activate'}
        >
          {item.status === 'ACTIVE' ? <Archive className="h-4 w-4" /> : <ArchiveRestore className="h-4 w-4" />}
        </Button>
      </>
    );
  }

  return (
    // Extra bottom padding below `md` clears the fixed MobileFab (its own height
    // plus offset, on top of AppShell's bottom-nav clearance) so trailing content
    // like the pagination row never sits underneath it — see PageHeader's
    // `primaryAction` note in docs/architecture/user-interface.md.
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Items"
        description="Everything the company buys, holds, makes, consumes or sells."
        primaryAction={{ label: 'New item', onClick: () => navigate('/items/new') }}
      />

      <div className="flex flex-wrap items-center gap-2">
        <Input
          placeholder="Search code or name…"
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          className="h-9 flex-1 md:w-56 md:flex-none"
        />

        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          <ItemFilterControls categories={categories} query={query} />
          <div className="ml-auto flex items-center gap-2">
            <PrintTrigger onPrint={() => print()} />
          </div>
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
            <ItemFilterControls categories={categories} query={query} full />
            <PrintTrigger onPrint={() => print()} className="w-full justify-center" />
          </div>
          <SheetFooter>
            <Button
              variant="ghost"
              onClick={() => query.setFilters({ category_id: undefined, type: undefined, status: undefined })}
            >
              Clear filters
            </Button>
            <Button onClick={() => setFiltersOpen(false)}>Done</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>

      {statusError && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
          {statusError}
        </div>
      )}

      <DataTable
        columns={tanStackColumns}
        data={items}
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
          query.setSort(tableSortingToApiSort(next, itemColumns));
        }}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        onRowClick={(item: ItemResource) => navigate(`/items/${item.id}`)}
        isLoading={itemsQuery.isLoading}
        isError={itemsQuery.isError}
        errorMessage="Couldn't load items."
        emptyMessage="No items match these filters."
        view={view}
        onViewChange={setView}
        cardColumns={itemColumns}
        rowActions={renderRowActions}
      />

      {/* Quick Print — a dedicated print-only layout, hidden until react-to-print clones it. */}
      <div className="hidden">
        <div ref={printRef}>
          <PrintLayout
            title="ITEM LIST"
            printedAt={new Date()}
            recordCount={items.length}
            meta={[
              ...(activeFilterLines.length > 0 ? [{ label: 'Filters', value: activeFilterLines.join(', ') }] : []),
              ...(query.sort ? [{ label: 'Sort', value: describeSort(query.sort, itemColumns) ?? query.sort }] : []),
            ]}
          >
            <PrintableTable columns={itemColumns} rows={items} getRowKey={(item) => item.id} />
          </PrintLayout>
        </div>
      </div>
    </div>
  );
}

interface ItemFilterControlsProps {
  categories: { id: number; name: string }[];
  query: ListQueryState<ItemFilters>;
  /** Full-width sizing for the mobile filter sheet, vs. the desktop row's fixed widths. */
  full?: boolean;
}

/** Category/Type/Status selects — shared by the always-visible desktop row and the mobile Filters sheet. */
function ItemFilterControls({ categories, query, full }: ItemFilterControlsProps) {
  return (
    <>
      <Combobox
        className={full ? 'h-9 w-full' : 'h-9 w-44'}
        aria-label="Category"
        value={query.filters.category_id ?? 'all'}
        onValueChange={(value) => query.setFilter('category_id', value === 'all' ? undefined : value)}
        placeholder="Category"
        searchPlaceholder="Search categories…"
        emptyText="No categories found."
        options={[
          { value: 'all', label: 'All categories' },
          ...categories.map((category) => ({ value: String(category.id), label: category.name })),
        ]}
      />
      <Select
        value={query.filters.type ?? 'all'}
        onValueChange={(value) => query.setFilter('type', value === 'all' ? undefined : value)}
      >
        <SelectTrigger className={full ? 'h-9 w-full' : 'h-9 w-40'} aria-label="Type">
          <SelectValue placeholder="Type" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All types</SelectItem>
          {ITEM_TYPES.map((type) => (
            <SelectItem key={type} value={type}>
              {type.replace('_', ' ')}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      <Select
        value={query.filters.status ?? 'all'}
        onValueChange={(value) => query.setFilter('status', value === 'all' ? undefined : value)}
      >
        <SelectTrigger className={full ? 'h-9 w-full' : 'h-9 w-36'} aria-label="Status">
          <SelectValue placeholder="Status" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All statuses</SelectItem>
          <SelectItem value="ACTIVE">Active</SelectItem>
          <SelectItem value="ARCHIVED">Archived</SelectItem>
        </SelectContent>
      </Select>
    </>
  );
}

function describeFilters(filters: Partial<ItemFilters>, categories: { id: number; name: string }[]): string[] {
  const lines: string[] = [];
  if (filters.category_id) {
    const category = categories.find((c) => String(c.id) === filters.category_id);
    lines.push(`Category = ${category?.name ?? filters.category_id}`);
  }
  if (filters.type) lines.push(`Type = ${filters.type.replace('_', ' ')}`);
  if (filters.status) lines.push(`Status = ${filters.status}`);
  if (filters.search) lines.push(`Search = "${filters.search}"`);

  return lines;
}
