import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { SlidersHorizontal } from 'lucide-react';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import {
  useApplicationRatesIndex,
  useItemsIndex,
  useItemsShow,
  useUomsIndex,
  useUomsShow,
  type ApplicationRateResource,
  type ApplicationRatesIndexSort,
} from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Badge } from '../../components/ui/badge';
import { RemoteLookupCombobox } from '../../components/ui/remote-lookup-combobox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns } from '../../components/data-table';
import {
  apiSortToTableSorting,
  tableSortingToApiSort,
  useListQueryState,
  type ListQueryState,
} from '../../lib/list-query-state';
import { useListViewPreference } from '../../lib/use-view-preference';
import { applicationRateColumns } from './application-rates-columns';
import { ApplicationRateResolutionTester } from './application-rate-resolution-tester';
import { lookupState, type LookupState } from '../../lib/api-errors';
import { usePermission } from '../../hooks/use-permission';

interface Filters {
  item_id: string;
  work_uom_id: string;
  is_default: string;
  [key: string]: string;
}

export function ApplicationRatesListScreen() {
  const mayManage = usePermission('estimating.application-rate.manage');
  const navigate = useNavigate();
  const query = useListQueryState<Filters>({ defaultSort: 'created_at' });
  const [testerOpen, setTesterOpen] = useState(false);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const { view, setView } = useListViewPreference('application-rates-list');
  const [itemSearch, setItemSearch] = useState('');
  const [debouncedItemSearch, setDebouncedItemSearch] = useState('');
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedItemSearch(itemSearch), 300);
    return () => clearTimeout(timer);
  }, [itemSearch]);
  const itemsResult = useItemsIndex({
    'filter[search]': debouncedItemSearch || undefined,
    per_page: 100,
    sort: 'name',
  });
  const items = itemsResult.data?.status === 200 ? itemsResult.data.data.data : [];
  const selectedItemId = Number(query.filters.item_id ?? 0);
  const selectedItemResult = useItemsShow(selectedItemId, undefined, { query: { enabled: selectedItemId > 0 } });
  const selectedItem = selectedItemResult.data?.status === 200 ? selectedItemResult.data.data.data : undefined;
  const [uomSearch, setUomSearch] = useState('');
  const [debouncedUomSearch, setDebouncedUomSearch] = useState('');
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedUomSearch(uomSearch), 300);
    return () => clearTimeout(timer);
  }, [uomSearch]);
  const uomsResult = useUomsIndex({ 'filter[search]': debouncedUomSearch || undefined, per_page: 100, sort: 'name' });
  const uoms = uomsResult.data?.status === 200 ? uomsResult.data.data.data : [];
  const selectedUomId = Number(query.filters.work_uom_id ?? 0);
  const selectedUomResult = useUomsShow(selectedUomId, { query: { enabled: selectedUomId > 0 } });
  const selectedUom = selectedUomResult.data?.status === 200 ? selectedUomResult.data.data.data : undefined;
  const result = useApplicationRatesIndex({
    'filter[item_id]': query.filters.item_id ? Number(query.filters.item_id) : undefined,
    'filter[work_uom_id]': query.filters.work_uom_id ? Number(query.filters.work_uom_id) : undefined,
    'filter[is_default]': query.filters.is_default ? query.filters.is_default === 'true' : undefined,
    include: 'item,workUom,uom',
    sort: query.sort as ApplicationRatesIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const columns = useMemo(() => toTanStackColumns(applicationRateColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, applicationRateColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const activeFilterCount = [query.filters.item_id, query.filters.work_uom_id, query.filters.is_default].filter(
    Boolean,
  ).length;

  return (
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Application rates"
        description="How much of an item is consumed per one unit of work, by context."
        primaryAction={mayManage ? { label: 'New rate', onClick: () => navigate('/application-rates/new') } : undefined}
        actions={
          <Button type="button" variant="outline" onClick={() => setTesterOpen((v) => !v)}>
            {testerOpen ? 'Hide resolution tester' : 'Test resolution'}
          </Button>
        }
      />
      {testerOpen && <ApplicationRateResolutionTester />}
      <div className="flex flex-wrap items-center gap-2">
        <div className="hidden items-center gap-2 md:flex">
          <ApplicationRateFilters
            query={query}
            items={itemSearch === debouncedItemSearch ? items : []}
            itemSearch={itemSearch}
            onItemSearchChange={setItemSearch}
            selectedItemLabel={selectedItem ? `${selectedItem.code} — ${selectedItem.name}` : undefined}
            uoms={uomSearch === debouncedUomSearch ? uoms : []}
            uomSearch={uomSearch}
            onUomSearchChange={setUomSearch}
            selectedUomLabel={selectedUom ? `${selectedUom.name} (${selectedUom.code})` : undefined}
            itemsLookup={{
              ...lookupState(itemsResult, 'items'),
              loading: itemsResult.isLoading || itemSearch !== debouncedItemSearch,
            }}
            uomsLookup={{
              ...lookupState(uomsResult, 'units'),
              loading: uomsResult.isLoading || uomSearch !== debouncedUomSearch,
            }}
          />
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
            <ApplicationRateFilters
              query={query}
              items={itemSearch === debouncedItemSearch ? items : []}
              itemSearch={itemSearch}
              onItemSearchChange={setItemSearch}
              selectedItemLabel={selectedItem ? `${selectedItem.code} — ${selectedItem.name}` : undefined}
              uoms={uomSearch === debouncedUomSearch ? uoms : []}
              uomSearch={uomSearch}
              onUomSearchChange={setUomSearch}
              selectedUomLabel={selectedUom ? `${selectedUom.name} (${selectedUom.code})` : undefined}
              itemsLookup={{
                ...lookupState(itemsResult, 'items'),
                loading: itemsResult.isLoading || itemSearch !== debouncedItemSearch,
              }}
              uomsLookup={{
                ...lookupState(uomsResult, 'units'),
                loading: uomsResult.isLoading || uomSearch !== debouncedUomSearch,
              }}
              full
            />
          </div>
          <SheetFooter>
            <Button
              variant="ghost"
              onClick={() => query.setFilters({ item_id: undefined, work_uom_id: undefined, is_default: undefined })}
            >
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
          query.setSort(tableSortingToApiSort(typeof u === 'function' ? u(sorting) : u, applicationRateColumns))
        }
        onRowClick={
          mayManage ? (row: ApplicationRateResource) => navigate(`/application-rates/${row.id}/edit`) : undefined
        }
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load application rates."
        emptyMessage="No application rates yet."
        view={view}
        onViewChange={setView}
        cardColumns={applicationRateColumns}
      />
    </div>
  );
}

function ApplicationRateFilters({
  query,
  items,
  itemSearch,
  onItemSearchChange,
  selectedItemLabel,
  uoms,
  uomSearch,
  onUomSearchChange,
  selectedUomLabel,
  itemsLookup,
  uomsLookup,
  full = false,
}: {
  query: ListQueryState<Filters>;
  items: { id: number; code: string; name: string }[];
  itemSearch: string;
  onItemSearchChange: (search: string) => void;
  selectedItemLabel?: string;
  uoms: { id: number; code: string; name: string }[];
  uomSearch: string;
  onUomSearchChange: (search: string) => void;
  selectedUomLabel?: string;
  itemsLookup: LookupState;
  uomsLookup: LookupState;
  full?: boolean;
}) {
  return (
    <>
      <RemoteLookupCombobox
        {...itemsLookup}
        className={full ? 'w-full' : 'h-9 w-64'}
        label="Item"
        allLabel="All items"
        value={query.filters.item_id ?? 'all'}
        selectedLabel={selectedItemLabel}
        onValueChange={(value) => query.setFilter('item_id', value === 'all' ? undefined : value)}
        search={itemSearch}
        onSearchChange={onItemSearchChange}
        options={items.map((item) => ({ value: String(item.id), label: `${item.code} — ${item.name}` }))}
      />
      <RemoteLookupCombobox
        {...uomsLookup}
        className={full ? 'w-full' : 'h-9 w-52'}
        label="Work unit"
        allLabel="All work units"
        value={query.filters.work_uom_id ?? 'all'}
        selectedLabel={selectedUomLabel}
        onValueChange={(value) => query.setFilter('work_uom_id', value === 'all' ? undefined : value)}
        search={uomSearch}
        onSearchChange={onUomSearchChange}
        options={uoms.map((uom) => ({ value: String(uom.id), label: `${uom.name} (${uom.code})` }))}
      />
      <Select
        value={query.filters.is_default ?? 'all'}
        onValueChange={(value) => query.setFilter('is_default', value === 'all' ? undefined : value)}
      >
        <SelectTrigger aria-label="Default status" className={full ? 'h-9 w-full' : 'h-9 w-40'}>
          <SelectValue placeholder="Default status" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All rates</SelectItem>
          <SelectItem value="true">Default only</SelectItem>
          <SelectItem value="false">Context-specific only</SelectItem>
        </SelectContent>
      </Select>
    </>
  );
}
