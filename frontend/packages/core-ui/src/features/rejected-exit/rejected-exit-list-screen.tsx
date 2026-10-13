import { useMemo, useState, type ReactNode } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router';
import type { PaginationState, RowSelectionState, SortingState } from '@tanstack/react-table';
import { useCurrenciesIndex, useWarehousesIndex, type SupplierAccountResource } from '@erp/core-api';
import { Building2, Calendar, DollarSign, FileText, Search, SlidersHorizontal, Tag, Truck, User, X } from 'lucide-react';

import { cn } from '../../lib/utils';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '../../components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { StatusBadge } from '../../components/ui/status-badge';
import { ScreenTemplate } from '../../components/page/screen-template';
import { PageHeader } from '../../components/page/page-header';
import { DataTable, toTanStackColumns, isColumnVisibleByDefault } from '../../components/data-table';
import { selectionColumn } from '../../components/data-table/columns';
import type { DataColumn } from '../../components/data-table/types';
import { PrintLayout, PrintableTable, PrintTrigger, useQuickPrint, type PrintMetaLine } from '../../components/print';
import { SupplierCombobox } from '../../components/lookups/entity-comboboxes';
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
  supplier_id: string;
  has_reference: string;
  has_credit: string;
  has_carrier_note: string;
  created_from: string;
  created_to: string;
  reason: string;
  [key: string]: string;
}

export function ExitListScreen({ kind }: { kind: ExitKind }) {
  const config = EXIT_CONFIG[kind];
  const navigate = useNavigate();
  const { search: locationSearch } = useLocation();
  const mayCreate = usePermission(config.createPermission);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});

  const query = useListQueryState<Filters>({ defaultSort: '-created_at', defaultPerPage: 25 });
  const searchBox = useDebouncedSearch(query.filters.search, (value) => query.setFilter('search', value));

  const warehousesQuery = useWarehousesIndex({ per_page: 100, sort: 'name' });
  const warehouses = warehousesQuery.data?.status === 200 ? warehousesQuery.data.data.data : [];

  const list = useExitIndex(kind, {
    warehouseId: query.filters.warehouse_id && query.filters.warehouse_id !== 'all' ? Number(query.filters.warehouse_id) : undefined,
    status: query.filters.status && query.filters.status !== 'all' ? query.filters.status : undefined,
    search: query.filters.search,
    supplierAccountId: kind === 'SUPPLIER_RETURN' && query.filters.supplier_id && query.filters.supplier_id !== 'all' ? Number(query.filters.supplier_id) : undefined,
    createdFrom: query.filters.created_from || undefined,
    createdTo: query.filters.created_to || undefined,
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
          <span className="font-medium text-foreground hover:underline" data-testid="exit-row-link">
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
        header: 'Posted at',
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

  const tanStackColumns = useMemo(
    () => [selectionColumn<Row>(), ...toTanStackColumns(columns)],
    [columns],
  );
  const sorting: SortingState = apiSortToTableSorting(query.sort, columns);
  const pagination: PaginationState = { pageIndex: query.page - 1, pageSize: query.perPage };

  // Supplier, status, warehouse, search and creation dates filter on the server; the rest narrow the loaded page only.
  const filteredRows = useMemo(() => {
    let rows = list.rows as Row[];
    if (query.filters.has_reference === 'true') {
      rows = rows.filter((r) => Boolean(r.supplier_reference && r.supplier_reference.trim() !== ''));
    } else if (query.filters.has_reference === 'false') {
      rows = rows.filter((r) => !r.supplier_reference || r.supplier_reference.trim() === '');
    }
    if (query.filters.has_credit === 'true') {
      rows = rows.filter((r) => Boolean(r.expected_credit_amount && Number(r.expected_credit_amount) > 0));
    } else if (query.filters.has_credit === 'false') {
      rows = rows.filter((r) => !r.expected_credit_amount || Number(r.expected_credit_amount) === 0);
    }
    if (query.filters.has_carrier_note === 'true') {
      rows = rows.filter((r) => Boolean(r.carrier_note && r.carrier_note.trim() !== ''));
    } else if (query.filters.has_carrier_note === 'false') {
      rows = rows.filter((r) => !r.carrier_note || r.carrier_note.trim() === '');
    }
    if (query.filters.reason) {
      const reasonLower = query.filters.reason.toLowerCase();
      rows = rows.filter((r) => r.reason.toLowerCase().includes(reasonLower));
    }
    return rows;
  }, [list.rows, query.filters]);

  const selectedRows = useMemo(
    () => filteredRows.filter((row) => rowSelection[String(row.id)]),
    [filteredRows, rowSelection],
  );

  const advancedFilterCount = [
    query.filters.supplier_id && query.filters.supplier_id !== 'all',
    query.filters.has_reference && query.filters.has_reference !== 'all',
    query.filters.has_credit && query.filters.has_credit !== 'all',
    query.filters.has_carrier_note && query.filters.has_carrier_note !== 'all',
    query.filters.created_from,
    query.filters.created_to,
    query.filters.reason,
  ].filter(Boolean).length;

  const activeFilterCount = [
    query.filters.search,
    query.filters.warehouse_id && query.filters.warehouse_id !== 'all',
    query.filters.status && query.filters.status !== 'all',
    query.filters.supplier_id && query.filters.supplier_id !== 'all',
    query.filters.has_reference && query.filters.has_reference !== 'all',
    query.filters.has_credit && query.filters.has_credit !== 'all',
    query.filters.has_carrier_note && query.filters.has_carrier_note !== 'all',
    query.filters.created_from,
    query.filters.created_to,
    query.filters.reason,
  ].filter(Boolean).length;

  const clearAdvancedFilters = () => {
    query.setFilters({
      supplier_id: undefined,
      has_reference: undefined,
      has_credit: undefined,
      has_carrier_note: undefined,
      created_from: undefined,
      created_to: undefined,
      reason: undefined,
    });
  };

  const clearAllFilters = () => {
    query.setFilters({
      search: undefined,
      warehouse_id: undefined,
      status: undefined,
      supplier_id: undefined,
      has_reference: undefined,
      has_credit: undefined,
      has_carrier_note: undefined,
      created_from: undefined,
      created_to: undefined,
      reason: undefined,
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
        onRemove: () => query.setFilter('search', undefined),
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

    if (query.filters.supplier_id && query.filters.supplier_id !== 'all') {
      const supplierName = supplierLabels.get(Number(query.filters.supplier_id)) ?? `#${query.filters.supplier_id}`;
      chips.push({
        id: 'supplier',
        icon: <User className="h-3.5 w-3.5" />,
        label: `Supplier: ${supplierName}`,
        onRemove: () => query.setFilter('supplier_id', undefined),
      });
    }

    if (query.filters.has_reference && query.filters.has_reference !== 'all') {
      chips.push({
        id: 'has_reference',
        icon: <FileText className="h-3.5 w-3.5" />,
        label: query.filters.has_reference === 'true' ? "With supplier ref" : "Without supplier ref",
        onRemove: () => query.setFilter('has_reference', undefined),
      });
    }

    if (query.filters.has_credit && query.filters.has_credit !== 'all') {
      chips.push({
        id: 'has_credit',
        icon: <DollarSign className="h-3.5 w-3.5" />,
        label: query.filters.has_credit === 'true' ? "With credit" : "Without credit",
        onRemove: () => query.setFilter('has_credit', undefined),
      });
    }

    if (query.filters.has_carrier_note && query.filters.has_carrier_note !== 'all') {
      chips.push({
        id: 'has_carrier_note',
        icon: <Truck className="h-3.5 w-3.5" />,
        label: query.filters.has_carrier_note === 'true' ? "With carrier note" : "Without carrier note",
        onRemove: () => query.setFilter('has_carrier_note', undefined),
      });
    }

    if (query.filters.created_from) {
      chips.push({
        id: 'created_from',
        icon: <Calendar className="h-3.5 w-3.5" />,
        label: `From: ${query.filters.created_from}`,
        onRemove: () => query.setFilter('created_from', undefined),
      });
    }

    if (query.filters.created_to) {
      chips.push({
        id: 'created_to',
        icon: <Calendar className="h-3.5 w-3.5" />,
        label: `To: ${query.filters.created_to}`,
        onRemove: () => query.setFilter('created_to', undefined),
      });
    }

    if (query.filters.reason) {
      chips.push({
        id: 'reason',
        icon: <FileText className="h-3.5 w-3.5" />,
        label: `Reason: “${query.filters.reason}”`,
        onRemove: () => query.setFilter('reason', undefined),
      });
    }

    return chips;
  }, [query.filters, warehouses, supplierLabels, query]);

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
    if (query.filters.supplier_id && query.filters.supplier_id !== 'all') {
      const name = supplierLabels.get(Number(query.filters.supplier_id)) ?? `#${query.filters.supplier_id}`;
      meta.push({ label: 'Supplier', value: name });
    }
    const sortDesc = describeSort(query.sort, columns);
    if (sortDesc) meta.push({ label: 'Sort', value: sortDesc });
    return meta;
  }, [query.filters, query.sort, warehouses, supplierLabels, columns]);

  const { contentRef: printRef, print } = useQuickPrint({
    documentTitle: `${config.plural} List`,
  });

  const { contentRef: selectedPrintRef, print: printSelected } = useQuickPrint({
    documentTitle: `${config.plural} (Selected)`,
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
          primaryAction={
            mayCreate
              ? {
                  label: `New ${config.noun}`,
                  onClick: () => navigate({ pathname: `${config.basePath}/new`, search: locationSearch }),
                }
              : undefined
          }
        />

        {/* Filter Toolbar (Row 1: Primary Inputs & Controls) */}
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative flex-1 md:w-72 md:flex-none">
            <Search
              aria-hidden="true"
              className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
              placeholder={
                kind === 'SUPPLIER_RETURN'
                  ? 'Search return number or ref…'
                  : 'Search scrap number or ref…'
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
              className="h-10 w-44"
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

            {/* Desktop More Filters Popover */}
            <Popover>
              <PopoverTrigger asChild>
                <Button
                  variant="outline"
                  className={cn('h-10 gap-1.5', advancedFilterCount > 0 && 'ui-btn-filters-active')}
                  aria-label="More filters"
                >
                  <SlidersHorizontal className="h-4 w-4" />
                  <span>More filters</span>
                  {advancedFilterCount > 0 && (
                    <Badge className="ml-1 h-5 min-w-5 justify-center rounded-full px-1 text-[11px] ui-filter-badge">
                      {advancedFilterCount}
                    </Badge>
                  )}
                </Button>
              </PopoverTrigger>
              <PopoverContent align="start" className="w-80 space-y-3 p-4">
                <div className="flex items-center justify-between border-b pb-2">
                  <h4 className="text-sm font-semibold">Additional Filters</h4>
                  {advancedFilterCount > 0 && (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-6 px-1.5 text-xs text-muted-foreground hover:text-foreground"
                      onClick={clearAdvancedFilters}
                    >
                      Reset
                    </Button>
                  )}
                </div>

                {kind === 'SUPPLIER_RETURN' && (
                  <div className="space-y-1">
                    <Label className="text-xs">Supplier</Label>
                    <SupplierCombobox
                      label="Supplier"
                      value={query.filters.supplier_id ?? ''}
                      onValueChange={(val) => query.setFilter('supplier_id', val || undefined)}
                      placeholder="All suppliers"
                      className="h-9 w-full"
                    />
                  </div>
                )}

                <div className="space-y-1">
                  <Label className="text-xs">Reason contains</Label>
                  <Input
                    className="h-9"
                    placeholder="e.g. Viscosity, Damaged…"
                    value={query.filters.reason ?? ''}
                    onChange={(e) => query.setFilter('reason', e.target.value || undefined)}
                  />
                </div>

                {kind === 'SUPPLIER_RETURN' && (
                  <div className="grid grid-cols-2 gap-2">
                    <div className="space-y-1">
                      <Label className="text-xs">Supplier Reference</Label>
                      <Select
                        value={query.filters.has_reference ?? 'all'}
                        onValueChange={(val) => query.setFilter('has_reference', val === 'all' ? undefined : val)}
                      >
                        <SelectTrigger className="h-9 text-xs">
                          <SelectValue placeholder="All" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="all">All</SelectItem>
                          <SelectItem value="true">With ref</SelectItem>
                          <SelectItem value="false">Without ref</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>

                    <div className="space-y-1">
                      <Label className="text-xs">Expected Credit</Label>
                      <Select
                        value={query.filters.has_credit ?? 'all'}
                        onValueChange={(val) => query.setFilter('has_credit', val === 'all' ? undefined : val)}
                      >
                        <SelectTrigger className="h-9 text-xs">
                          <SelectValue placeholder="All" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="all">All</SelectItem>
                          <SelectItem value="true">With credit</SelectItem>
                          <SelectItem value="false">Without credit</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                  </div>
                )}

                <div className="grid grid-cols-2 gap-2">
                  <div className="space-y-1">
                    <Label className="text-xs">Created from</Label>
                    <Input
                      type="date"
                      className="h-9 text-xs"
                      value={query.filters.created_from ?? ''}
                      onChange={(e) => query.setFilter('created_from', e.target.value || undefined)}
                    />
                  </div>
                  <div className="space-y-1">
                    <Label className="text-xs">Created to</Label>
                    <Input
                      type="date"
                      className="h-9 text-xs"
                      value={query.filters.created_to ?? ''}
                      onChange={(e) => query.setFilter('created_to', e.target.value || undefined)}
                    />
                  </div>
                </div>
              </PopoverContent>
            </Popover>

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

        {/* Filter Toolbar (Row 2: Active Filter Chips Bar) */}
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

        {/* Bulk Selection Bar */}
        {selectedRows.length > 0 && (
          <div
            role="region"
            aria-label="Bulk actions"
            className="flex flex-wrap items-center gap-3 rounded-md border bg-muted/40 px-3 py-2 text-sm"
          >
            <span className="font-medium">{selectedRows.length} selected</span>
            <PrintTrigger
              onPrint={() => printSelected()}
              label="Print selected"
              className="h-8"
            />
            <Button
              variant="ghost"
              size="sm"
              className="h-8"
              onClick={() => setRowSelection({})}
            >
              Clear selection
            </Button>
          </div>
        )}

        {/* Hidden Print Layouts */}
        <div className="hidden">
          <div ref={printRef}>
            <PrintLayout
              title={config.plural}
              meta={printMeta}
              recordCount={filteredRows.length}
            >
              <PrintableTable<Row> columns={columns} rows={filteredRows} />
            </PrintLayout>
          </div>
          <div ref={selectedPrintRef}>
            <PrintLayout
              title={`${config.plural} (Selected)`}
              meta={printMeta}
              recordCount={selectedRows.length}
            >
              <PrintableTable<Row> columns={columns} rows={selectedRows} />
            </PrintLayout>
          </div>
        </div>

        {/* Mobile Filters Bottom Sheet */}
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

              {kind === 'SUPPLIER_RETURN' && (
                <div className="space-y-1">
                  <Label>Supplier</Label>
                  <SupplierCombobox
                    label="Supplier"
                    value={query.filters.supplier_id ?? ''}
                    onValueChange={(val) => query.setFilter('supplier_id', val || undefined)}
                    placeholder="All suppliers"
                    className="h-10 w-full"
                  />
                </div>
              )}

              <div className="space-y-1">
                <Label>Reason contains</Label>
                <Input
                  className="h-10"
                  placeholder="e.g. Viscosity, Damaged…"
                  value={query.filters.reason ?? ''}
                  onChange={(e) => query.setFilter('reason', e.target.value || undefined)}
                />
              </div>

              {kind === 'SUPPLIER_RETURN' && (
                <div className="grid grid-cols-2 gap-2">
                  <div className="space-y-1">
                    <Label>Supplier Reference</Label>
                    <Select
                      value={query.filters.has_reference ?? 'all'}
                      onValueChange={(val) => query.setFilter('has_reference', val === 'all' ? undefined : val)}
                    >
                      <SelectTrigger className="h-10">
                        <SelectValue placeholder="All" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="all">All</SelectItem>
                        <SelectItem value="true">With ref</SelectItem>
                        <SelectItem value="false">Without ref</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-1">
                    <Label>Expected Credit</Label>
                    <Select
                      value={query.filters.has_credit ?? 'all'}
                      onValueChange={(val) => query.setFilter('has_credit', val === 'all' ? undefined : val)}
                    >
                      <SelectTrigger className="h-10">
                        <SelectValue placeholder="All" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="all">All</SelectItem>
                        <SelectItem value="true">With credit</SelectItem>
                        <SelectItem value="false">Without credit</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </div>
              )}

              <div className="grid grid-cols-2 gap-2">
                <div className="space-y-1">
                  <Label>Created from</Label>
                  <Input
                    type="date"
                    className="h-10"
                    value={query.filters.created_from ?? ''}
                    onChange={(e) => query.setFilter('created_from', e.target.value || undefined)}
                  />
                </div>
                <div className="space-y-1">
                  <Label>Created to</Label>
                  <Input
                    type="date"
                    className="h-10"
                    value={query.filters.created_to ?? ''}
                    onChange={(e) => query.setFilter('created_to', e.target.value || undefined)}
                  />
                </div>
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
          data={filteredRows}
          rowCount={filteredRows.length}
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
          rowSelection={rowSelection}
          onRowSelectionChange={setRowSelection}
          getRowId={(row) => String(row.id)}
          onRowClick={(row: Row) => navigate({ pathname: `${config.basePath}/${row.id}`, search: locationSearch })}
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
