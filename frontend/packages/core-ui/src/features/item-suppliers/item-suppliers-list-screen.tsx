import { useMemo, useState } from 'react';
import { Plus } from 'lucide-react';
import { useNavigate } from 'react-router';
import type { PaginationState, SortingState, VisibilityState } from '@tanstack/react-table';
import {
  useItemsIndex,
  useItemSuppliersIndex,
  useSupplierAccountsIndex,
  type ItemSupplierResource,
  type ItemSuppliersIndexSort,
} from '@erp/core-api';
import { Button } from '../../components/ui/button';
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
import { itemSupplierColumns } from './item-suppliers-columns';
interface Filters {
  item_id: string;
  supplier_account_id: string;
  active: string;
  preferred: string;
  [key: string]: string;
}

export function ItemSuppliersListScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<Filters>({ defaultSort: 'priority' });
  const [visibility, setVisibility] = useState<VisibilityState>(() =>
    Object.fromEntries(itemSupplierColumns.map((c) => [c.key, isColumnVisibleByDefault(c)]))
  );
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
  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="Item suppliers"
        description="Approved supplier relationships and item-specific sourcing terms."
        actions={
          <Button onClick={() => navigate('/item-suppliers/new')}>
            <Plus className="h-4 w-4" />
            New relationship
          </Button>
        }
      />
      <div className="flex flex-wrap gap-2">
        <Select value={query.filters.item_id ?? 'all'} onValueChange={(v) => filter('item_id', v)}>
          <SelectTrigger className="h-9 w-48">
            <SelectValue placeholder="Item" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All items</SelectItem>
            {items.map((i) => (
              <SelectItem key={i.id} value={String(i.id)}>
                {i.code} — {i.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select
          value={query.filters.supplier_account_id ?? 'all'}
          onValueChange={(v) => filter('supplier_account_id', v)}
        >
          <SelectTrigger className="h-9 w-48">
            <SelectValue placeholder="Supplier" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All suppliers</SelectItem>
            {suppliers.map((s) => (
              <SelectItem key={s.id} value={String(s.id)}>
                {s.code}
                {s.party?.name ? ` — ${s.party.name}` : ''}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select value={query.filters.active ?? 'all'} onValueChange={(v) => filter('active', v)}>
          <SelectTrigger className="h-9 w-36">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            <SelectItem value="true">Active</SelectItem>
            <SelectItem value="false">Inactive</SelectItem>
          </SelectContent>
        </Select>
        <Select value={query.filters.preferred ?? 'all'} onValueChange={(v) => filter('preferred', v)}>
          <SelectTrigger className="h-9 w-40">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All priorities</SelectItem>
            <SelectItem value="true">Preferred only</SelectItem>
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
          query.setSort(tableSortingToApiSort(typeof u === 'function' ? u(sorting) : u, itemSupplierColumns))
        }
        columnVisibility={visibility}
        onColumnVisibilityChange={setVisibility}
        onRowClick={(row: ItemSupplierResource) => navigate(`/item-suppliers/${row.id}`)}
        isLoading={result.isLoading}
        isError={result.isError}
        errorMessage="Couldn't load item suppliers."
        emptyMessage="No relationships match these filters."
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
