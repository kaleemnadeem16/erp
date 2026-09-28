import { useEffect, useMemo, useState } from 'react';
import { SlidersHorizontal } from 'lucide-react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import {
  useItemsIndex,
  useItemSuppliersIndex,
  useSupplierAccountsIndex,
  type ItemSupplierResource,
  type ItemSuppliersIndexSort,
} from '@erp/core-api';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Combobox } from '../../components/ui/combobox';
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
import { itemSupplierColumns } from './item-suppliers-columns';
import { usePermission } from '../../hooks/use-permission';
interface Filters {
  search: string;
  item_id: string;
  supplier_account_id: string;
  active: string;
  preferred: string;
  [key: string]: string;
}

export function ItemSuppliersListScreen() {
  const mayManage = usePermission('purchasing.item-supplier.manage');
  const navigate = useNavigate();
  const query = useListQueryState<Filters>({ defaultSort: 'priority' });
  const [visibility, setVisibility] = useColumnVisibilityPreference(
    'item-suppliers-list',
    Object.fromEntries(itemSupplierColumns.map((c) => [c.key, isColumnVisibleByDefault(c)]))
  );
  const [filtersOpen, setFiltersOpen] = useState(false);
  const { view, setView } = useListViewPreference('item-suppliers-list');
  const [searchInput, setSearchInput] = useState(query.filters.search ?? '');

  // Debounce free-text search so every keystroke doesn't round-trip to the API.
  useEffect(() => {
    const handle = setTimeout(() => {
      if (searchInput !== (query.filters.search ?? '')) query.setFilter('search', searchInput || undefined);
    }, 300);
    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  const itemsResult = useItemsIndex({ per_page: 100, sort: 'name' });
  const items = itemsResult.data?.status === 200 ? itemsResult.data.data.data : [];
  const suppliersResult = useSupplierAccountsIndex({ per_page: 100, sort: 'code', include: 'party' });
  const suppliers = suppliersResult.data?.status === 200 ? suppliersResult.data.data.data : [];
  const result = useItemSuppliersIndex({
    'filter[item_id]': query.filters.item_id ? Number(query.filters.item_id) : undefined,
    'filter[supplier_account_id]': query.filters.supplier_account_id
      ? Number(query.filters.supplier_account_id)
      : undefined,
    'filter[active]': query.filters.active ? query.filters.active === 'true' : undefined,
    'filter[preferred]': query.filters.preferred ? true : undefined,
    'filter[search]': query.filters.search || undefined,
    include: 'item,supplierAccount,purchaseUom',
    sort: query.sort as ItemSuppliersIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;
  const columns = useMemo(() => toTanStackColumns(itemSupplierColumns), []);
  const sorting: SortingState = apiSortToTableSorting(query.sort, itemSupplierColumns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };
  const { contentRef, print } = useQuickPrint({ documentTitle: 'Item Supplier List' });
  const filter = (name: keyof Filters, value: string) => query.setFilter(name, value === 'all' ? undefined : value);
  const activeFilterCount = [
    query.filters.item_id,
    query.filters.supplier_account_id,
    query.filters.active,
    query.filters.preferred,
  ].filter(Boolean).length;
  return (
    // Extra bottom padding below `md` clears the fixed MobileFab — see
    // PageHeader's `primaryAction` note in docs/architecture/user-interface.md.
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Item suppliers"
        description="Approved supplier relationships and item-specific sourcing terms."
        primaryAction={mayManage ? { label: 'New relationship', onClick: () => navigate('/item-suppliers/new') } : undefined}
      />
      <div className="flex flex-wrap items-center gap-2">
        <Input
          placeholder="Search item or supplier…"
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          className="h-9 flex-1 md:w-56 md:flex-none"
        />
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          <ItemSupplierFilterControls items={items} suppliers={suppliers} query={query} filter={filter} />
          <PrintTrigger onPrint={print} />
        </div>
        <Button
          variant="outline"
          size="sm"
          className="h-9 shrink-0 md:hidden"
          onClick={() => setFiltersOpen(true)}
        >
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
            <ItemSupplierFilterControls items={items} suppliers={suppliers} query={query} filter={filter} full />
            <PrintTrigger onPrint={print} className="w-full justify-center" />
          </div>
          <SheetFooter>
            <Button
              variant="ghost"
              onClick={() =>
                query.setFilters({
                  item_id: undefined,
                  supplier_account_id: undefined,
                  active: undefined,
                  preferred: undefined,
                })
              }
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
          query.setSort(tableSortingToApiSort(typeof u === 'function' ? u(sorting) : u, itemSupplierColumns))
        }
        columnVisibility={visibility}
        onColumnVisibilityChange={setVisibility}
        onRowClick={(row: ItemSupplierResource) => navigate(`/item-suppliers/${row.id}`)}
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load item suppliers."
        emptyMessage="No relationships match these filters."
        view={view}
        onViewChange={setView}
        cardColumns={itemSupplierColumns}
      />
      <div className="hidden">
        <div ref={contentRef}>
          <PrintLayout
            title="ITEM SUPPLIER LIST"
            printedAt={new Date()}
            recordCount={rows.length}
            meta={
              query.sort ? [{ label: 'Sort', value: describeSort(query.sort, itemSupplierColumns) ?? query.sort }] : []
            }
          >
            <PrintableTable columns={itemSupplierColumns} rows={rows} getRowKey={(row) => row.id} />
          </PrintLayout>
        </div>
      </div>
    </div>
  );
}

interface ItemSupplierFilterControlsProps {
  items: { id: number; code: string; name: string }[];
  suppliers: { id: number; code: string; party?: { name: string } | null }[];
  query: ReturnType<typeof useListQueryState<Filters>>;
  filter: (name: keyof Filters, value: string) => void;
  full?: boolean;
}

/** Item/Supplier/Status/Preferred selects — shared by the desktop row and the mobile Filters sheet. */
function ItemSupplierFilterControls({ items, suppliers, query, filter, full }: ItemSupplierFilterControlsProps) {
  return (
    <>
      <Combobox
        className={full ? 'h-9 w-full' : 'h-9 w-48'}
        aria-label="Item"
        value={query.filters.item_id ?? 'all'}
        onValueChange={(v) => filter('item_id', v)}
        placeholder="Item"
        searchPlaceholder="Search items…"
        emptyText="No items found."
        options={[
          { value: 'all', label: 'All items' },
          ...items.map((i) => ({ value: String(i.id), label: `${i.code} — ${i.name}` })),
        ]}
      />
      <Combobox
        className={full ? 'h-9 w-full' : 'h-9 w-48'}
        aria-label="Supplier"
        value={query.filters.supplier_account_id ?? 'all'}
        onValueChange={(v) => filter('supplier_account_id', v)}
        placeholder="Supplier"
        searchPlaceholder="Search suppliers…"
        emptyText="No suppliers found."
        options={[
          { value: 'all', label: 'All suppliers' },
          ...suppliers.map((s) => ({
            value: String(s.id),
            label: `${s.code}${s.party?.name ? ` — ${s.party.name}` : ''}`,
          })),
        ]}
      />
      <Select value={query.filters.active ?? 'all'} onValueChange={(v) => filter('active', v)}>
        <SelectTrigger className={full ? 'h-9 w-full' : 'h-9 w-36'}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All statuses</SelectItem>
          <SelectItem value="true">Active</SelectItem>
          <SelectItem value="false">Inactive</SelectItem>
        </SelectContent>
      </Select>
      <Select value={query.filters.preferred ?? 'all'} onValueChange={(v) => filter('preferred', v)}>
        <SelectTrigger className={full ? 'h-9 w-full' : 'h-9 w-40'}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All priorities</SelectItem>
          <SelectItem value="true">Preferred only</SelectItem>
        </SelectContent>
      </Select>
    </>
  );
}
