import { useMemo, useState, type ReactNode } from 'react';
import { Outlet, useNavigate } from 'react-router';
import type { PaginationState, SortingState } from '@tanstack/react-table';
import { useCurrenciesIndex, useWarehousesIndex, type SupplierAccountResource } from '@erp/core-api';
import { Building2, Search, SlidersHorizontal, Tag, X } from 'lucide-react';

import { cn } from '../../lib/utils';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { StatusBadge } from '../../components/ui/status-badge';
import { ScreenTemplate } from '../../components/page/screen-template';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns, isColumnVisibleByDefault } from '../../components/data-table';
import type { DataColumn } from '../../components/data-table/types';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint, type PrintMetaLine } from '../../components/print';
import { apiSortToTableSorting, describeSort, tableSortingToApiSort, useListQueryState } from '../../lib/list-query-state';
import { useColumnVisibilityPreference } from '../../lib/use-column-visibility-preference';
import { useDebouncedSearch } from '../../lib/use-debounced-search';
import { useListViewPreference } from '../../lib/use-view-preference';
import { useSuppliersById } from '../../lib/use-records-by-id';
import { lookupState } from '../../lib/api-errors';
import { formatDateTime } from '../../lib/format-date';
import { trimQty } from '../package-stock/quantity';
import { usePermission } from '../../hooks/use-permission';
import { EXIT_CONFIG, useExitIndex, type ExitDocument, type ExitKind } from './rejected-exit-api';
import { EXIT_STATUS_LABEL, EXIT_STATUS_TONE } from './rejected-exit-detail-screen';

type Row = ExitDocument & { warehouse?: { name: string } };

interface Filters {
  search: string;
  warehouse_id: string;
  status: string;
  [key: string]: string;
}


export function ExitListScreen({ kind }: { kind: ExitKind }) {
  const config = EXIT_CONFIG[kind];
  const navigate = useNavigate();
  const mayCreate = usePermission(config.createPermission);
  const [filtersOpen, setFiltersOpen] = useState(false);

  const query = useListQueryState<Filters>({ defaultSort: '-created_at', defaultPerPage: 25 });
  const searchBox = useDebouncedSearch(query.filters.search, (value) => query.setFilter('search', value));

  const warehousesQuery = useWarehousesIndex({ per_page: 100, sort: 'name' });
  const warehouses = warehousesQuery.data?.status === 200 ? warehousesQuery.data.data.data : [];

  const list = useExitIndex(kind, {
    warehouseId: query.filters.warehouse_id && query.filters.warehouse_id !== 'all' ? Number(query.filters.warehouse_id) : undefined,
    status: query.filters.status && query.filters.status !== 'all' ? query.filters.status : undefined,
    search: query.filters.search,
    sort: query.sort,
    perPage: query.perPage,
    page: query.page,
  });

  const supplierIds = useMemo(() => {
    if (kind !== 'SUPPLIER_RETURN') return [];
    return (list.rows as Row[])
      .map((r) => r.supplier_account_id)
      .filter((id): id is number => typeof id === 'number' && id > 0);
  }, [kind, list.rows]);
  const suppliers = useSuppliersById<SupplierAccountResource>(supplierIds, []);
  const supplierLabels = useMemo(
    () => new Map(suppliers.map((s) => [s.id, s.party?.name ? `${s.code} — ${s.party.name}` : s.code])),
    [suppliers],
  );

  const currenciesQuery = useCurrenciesIndex({ per_page: 100, sort: 'code' }, { query: { enabled: kind === 'SUPPLIER_RETURN' } });
  const currencyLabels = useMemo(() => {
    const currencies = currenciesQuery.data?.status === 200 ? currenciesQuery.data.data.data : [];
    return new Map(currencies.map((c) => [c.id, c.code]));
  }, [currenciesQuery.data]);

  const columns = useMemo<DataColumn<Row>[]>(() => {
    const listCols: DataColumn<Row>[] = [
      {
        key: 'number',
        header: 'Number',
        value: (row) => row.number ?? `Draft #${row.id}`,
        cardRole: 'title',
        sortable: true,
        cell: (row) => (
          <span className="font-semibold text-primary hover:underline ui-order-link">
            {row.number ?? `Draft #${row.id}`}
          </span>
        ),
      },
    ];

    if (kind === 'SUPPLIER_RETURN') {
      listCols.push(
        {
          key: 'supplier',
          header: 'Supplier',
          value: (row) => (row.supplier_account_id ? (supplierLabels.get(row.supplier_account_id) ?? `#${row.supplier_account_id}`) : null),
          cardRole: 'subtitle',
        },
        {
          key: 'supplier_reference',
          header: "Supplier's ref",
          value: (row) => row.supplier_reference ?? null,
        },
      );
    }

    listCols.push(
      {
        key: 'warehouse',
        header: 'Warehouse',
        value: (row) => row.warehouse?.name ?? null,
        cardRole: kind === 'SCRAP' ? 'subtitle' : undefined,
      },
      {
        key: 'reason',
        header: 'Reason',
        value: (row) => row.reason,
      },
      {
        key: 'status',
        header: 'Status',
        value: (row) => EXIT_STATUS_LABEL[row.status] ?? row.status,
        cell: (row) => <StatusBadge tone={EXIT_STATUS_TONE[row.status] ?? 'draft'}>{EXIT_STATUS_LABEL[row.status] ?? row.status}</StatusBadge>,
      },
    );

    if (kind === 'SUPPLIER_RETURN') {
      listCols.push(
        {
          key: 'expected_credit',
          header: 'Expected credit',
          value: (row) => {
            if (!row.expected_credit_amount) return null;
            const code = row.expected_credit_currency_id ? currencyLabels.get(row.expected_credit_currency_id) : '';
            return `${trimQty(row.expected_credit_amount)} ${code ?? ''}`.trim();
          },
          screenVisible: false,
        },
        {
          key: 'carrier_note',
          header: 'Carrier note',
          value: (row) => row.carrier_note ?? null,
          screenVisible: false,
        },
      );
    } else {
      listCols.push(
        {
          key: 'disposal_method',
          header: 'Disposal method',
          value: (row) => row.disposal_method ?? null,
        },
        {
          key: 'disposal_reference',
          header: 'Disposal ref',
          value: (row) => row.disposal_reference ?? null,
          screenVisible: false,
        },
      );
    }

    listCols.push(
      {
        key: 'created_at',
        header: 'Created',
        value: (row) => formatDateTime(row.created_at),
        sortable: true,
      },
      {
        key: 'posted_at',
        header: 'Posted',
        value: (row) => formatDateTime(row.posted_at),
        sortable: true,
        screenVisible: false,
      },
    );

    return listCols;
  }, [kind, supplierLabels, currencyLabels]);

  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    `${config.basePath.slice(1)}-list`,
    Object.fromEntries(columns.map((column) => [column.key, isColumnVisibleByDefault(column)])),
  );
  const { view, setView } = useListViewPreference(`${config.basePath.slice(1)}-list`);

  const tanStackColumns = useMemo(() => toTanStackColumns(columns), [columns]);
  const sorting: SortingState = apiSortToTableSorting(query.sort, columns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  const activeFilterCount = [
    query.filters.search,
    query.filters.warehouse_id && query.filters.warehouse_id !== 'all',
    query.filters.status && query.filters.status !== 'all',
  ].filter(Boolean).length;

  const clearAllFilters = () => {
    query.setFilters({
      search: undefined,
      warehouse_id: undefined,
      status: undefined,
    });
  };

  const activeChips = useMemo(() => {
    const chips: {
      id: string;
      label: string;
      icon: ReactNode;
      onRemove: () => void;
    }[] = [];

    if (query.filters.search) {
      chips.push({
        id: 'search',
        icon: <Search className="h-3.5 w-3.5" />,
        label: `Search: “${query.filters.search}”`,
        onRemove: () => {
          query.setFilter('search', undefined);
        },
      });
    }

    if (query.filters.warehouse_id && query.filters.warehouse_id !== 'all') {
      const warehouse = warehouses.find((w) => String(w.id) === query.filters.warehouse_id);
      chips.push({
        id: 'warehouse',
        icon: <Building2 className="h-3.5 w-3.5" />,
        label: `Warehouse: ${warehouse?.name ?? query.filters.warehouse_id}`,
        onRemove: () => query.setFilter('warehouse_id', undefined),
      });
    }

    if (query.filters.status && query.filters.status !== 'all') {
      chips.push({
        id: 'status',
        icon: <Tag className="h-3.5 w-3.5" />,
        label: `Status: ${EXIT_STATUS_LABEL[query.filters.status] ?? query.filters.status}`,
        onRemove: () => query.setFilter('status', undefined),
      });
    }

    return chips;
  }, [query.filters, warehouses, query]);

  const printMeta = useMemo(() => {
    const meta: PrintMetaLine[] = [];
    if (query.filters.search) meta.push({ label: 'Search', value: query.filters.search });
    if (query.filters.warehouse_id && query.filters.warehouse_id !== 'all') {
      const wh = warehouses.find((w) => String(w.id) === query.filters.warehouse_id);
      meta.push({ label: 'Warehouse', value: wh?.name ?? query.filters.warehouse_id });
    }
    if (query.filters.status && query.filters.status !== 'all') {
      meta.push({ label: 'Status', value: EXIT_STATUS_LABEL[query.filters.status] ?? query.filters.status });
    }
    const sortDesc = describeSort(query.sort, columns);
    if (sortDesc) meta.push({ label: 'Sort', value: sortDesc });
    return meta;
  }, [query.filters, query.sort, warehouses, columns]);

  const { contentRef: printRef, print } = useQuickPrint({
    documentTitle: `${config.plural} List`,
  });

  return (
    <ScreenTemplate kind="list" className="erp-screen pb-24">
      <div className="space-y-4" data-testid="exit-list">
        <PageHeader
          title={config.plural}
          description={
            kind === 'SUPPLIER_RETURN'
              ? 'Rejected stock sent back to the supplier. Start one from a delivery’s rejected stock.'
              : 'Rejected stock written off. Start one from a delivery’s rejected stock.'
          }
          primaryAction={mayCreate ? { label: `New ${config.noun}`, onClick: () => navigate(`${config.basePath}/new`) } : undefined}
        />

        <div className="flex flex-wrap items-center gap-2">
          <div className="relative flex-1 md:w-80 md:flex-none">
            <Search
              aria-hidden="true"
              className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
              placeholder={
                kind === 'SUPPLIER_RETURN'
                  ? 'Search return number or supplier ref…'
                  : 'Search scrap number or disposal ref…'
              }
              className="h-10 w-full pl-9 pr-8"
              {...searchBox.searchProps}
            />
            {searchBox.input ? (
              <button
                type="button"
                onClick={() => {
                  query.setFilter('search', undefined);
                }}
                className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-full p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                aria-label="Clear search"
              >
                <X className="h-4 w-4" />
              </button>
            ) : null}
          </div>

          <div className="hidden items-center gap-2 md:flex md:flex-wrap">
            <Combobox
              {...lookupState(warehousesQuery, 'warehouses')}
              className="h-10 w-48"
              aria-label="Warehouse"
              value={query.filters.warehouse_id ?? 'all'}
              onValueChange={(value) => query.setFilter('warehouse_id', value === 'all' ? undefined : value)}
              placeholder="Warehouse"
              searchPlaceholder="Search warehouses…"
              emptyText="No warehouses found."
              options={[{ value: 'all', label: 'All warehouses' }, ...warehouses.map((warehouse) => ({ value: String(warehouse.id), label: warehouse.name }))]}
            />
            <Select value={query.filters.status ?? 'all'} onValueChange={(value) => query.setFilter('status', value === 'all' ? undefined : value)}>
              <SelectTrigger className="h-10 w-36" aria-label="Status">
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All statuses</SelectItem>
                {Object.entries(EXIT_STATUS_LABEL).map(([value, label]) => (
                  <SelectItem key={value} value={value}>{label}</SelectItem>
                ))}
              </SelectContent>
            </Select>

            <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
          </div>

          <Button
            variant="outline"
            size="sm"
            className={cn(
              'h-10 shrink-0 md:hidden',
              activeFilterCount > 0 && 'ui-btn-filters-active',
            )}
            onClick={() => setFiltersOpen(true)}
          >
            <SlidersHorizontal className="mr-1 h-4 w-4" />
            Filters
            {activeFilterCount > 0 && (
              <Badge
                className={cn(
                  'ml-1 h-5 min-w-5 justify-center rounded-full px-1 text-[11px]',
                  'ui-filter-badge',
                )}
              >
                {activeFilterCount}
              </Badge>
            )}
          </Button>
        </div>

        {activeChips.length > 0 && (
          <div className="flex flex-wrap items-center gap-1.5 pt-1">
            {activeChips.map((chip) => (
              <Badge
                key={chip.id}
                variant="secondary"
                className="ui-chip-active h-7 gap-1.5 px-2.5 text-xs font-normal"
              >
                {chip.icon}
                <span>{chip.label}</span>
                <button
                  type="button"
                  onClick={chip.onRemove}
                  className="ml-0.5 rounded-full p-0.5 hover:bg-muted-foreground/20"
                  aria-label={`Remove filter ${chip.label}`}
                >
                  <X className="h-3 w-3" />
                </button>
              </Badge>
            ))}
            <Button
              variant="ghost"
              size="sm"
              className="h-7 px-2 text-xs text-muted-foreground hover:text-foreground"
              onClick={clearAllFilters}
            >
              Clear all
            </Button>
          </div>
        )}

        <div className="hidden">
          <div ref={printRef}>
            <PrintLayout
              title={config.plural}
              meta={printMeta}
              recordCount={list.rows.length}
            >
              <PrintableTable<Row> columns={columns} rows={list.rows as Row[]} />
            </PrintLayout>
          </div>
        </div>

        <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
          <SheetContent side="bottom" className="max-h-[85vh] overflow-y-auto md:hidden">
            <SheetHeader>
              <SheetTitle>Filters</SheetTitle>
            </SheetHeader>
            <div className="space-y-4 py-4">
              <div className="space-y-1">
                <Label>Warehouse</Label>
                <Combobox
                  {...lookupState(warehousesQuery, 'warehouses')}
                  className="h-10 w-full"
                  aria-label="Warehouse"
                  value={query.filters.warehouse_id ?? 'all'}
                  onValueChange={(value) => query.setFilter('warehouse_id', value === 'all' ? undefined : value)}
                  placeholder="Warehouse"
                  searchPlaceholder="Search warehouses…"
                  emptyText="No warehouses found."
                  options={[{ value: 'all', label: 'All warehouses' }, ...warehouses.map((warehouse) => ({ value: String(warehouse.id), label: warehouse.name }))]}
                />
              </div>
              <div className="space-y-1">
                <Label>Status</Label>
                <Select value={query.filters.status ?? 'all'} onValueChange={(value) => query.setFilter('status', value === 'all' ? undefined : value)}>
                  <SelectTrigger className="h-10 w-full" aria-label="Status">
                    <SelectValue placeholder="Status" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All statuses</SelectItem>
                    {Object.entries(EXIT_STATUS_LABEL).map(([value, label]) => (
                      <SelectItem key={value} value={value}>{label}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
            <SheetFooter className="flex-row gap-2 pt-2">
              <Button
                variant="outline"
                className="flex-1"
                onClick={() => {
                  clearAllFilters();
                  setFiltersOpen(false);
                }}
              >
                Reset filters
              </Button>
              <Button className="flex-1" onClick={() => setFiltersOpen(false)}>
                Done
              </Button>
            </SheetFooter>
          </SheetContent>
        </Sheet>

        <DataTable
          columns={tanStackColumns}
          data={list.rows as Row[]}
          rowCount={list.total}
          pagination={pagination}
          onPaginationChange={(updater) => {
            const next = typeof updater === 'function' ? updater(pagination) : updater;
            if (next.pageSize !== pagination.pageSize) query.setPerPage(next.pageSize);
            else query.setPage(next.pageIndex + 1);
          }}
          sorting={sorting}
          onSortingChange={(updater) => {
            const next = typeof updater === 'function' ? updater(sorting) : updater;
            query.setSort(tableSortingToApiSort(next, columns));
          }}
          columnVisibility={columnVisibility}
          onColumnVisibilityChange={setColumnVisibility}
          onRowClick={(row: Row) => navigate(`${config.basePath}/${row.id}`)}
          isLoading={list.query.isLoading}
          isError={list.query.isError}
          error={list.query.error}
          onRetry={() => void list.query.refetch()}
          errorMessage={`Couldn't load ${config.plural.toLowerCase()}.`}
          emptyMessage={
            activeFilterCount > 0
              ? `No ${config.plural.toLowerCase()} match these filters.`
              : `No ${config.plural.toLowerCase()} found.`
          }
          view={view}
          onViewChange={setView}
          cardColumns={columns}
        />

        <Outlet />
      </div>
    </ScreenTemplate>
  );
}
