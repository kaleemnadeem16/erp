import { ScreenTemplate } from "../../components/page/screen-template";
import { MD_BREAKPOINT_QUERY, useMediaQuery } from "../../lib/use-media-query";
import { Collapse } from "../../components/ui/collapse";
import { useEffect, useMemo, useState, type ReactNode } from "react";
import { useLocation, useNavigate } from "react-router";
import {
  Building2,
  CalendarDays,
  ChevronUp,
  CircleDollarSign,
  Columns3,
  Database,
  EllipsisVertical,
  Search,
  ShieldCheck,
  SlidersHorizontal,
  Tag,
  X,
} from "lucide-react";
import type {
  PaginationState,
  RowSelectionState,
  SortingState,
} from "@tanstack/react-table";
import {
  useCurrenciesIndex,
  useItemsIndex,
  usePurchaseOrdersIndex,
  usePurchaseOrdersSubmit,
  useSupplierAccountsIndex,
  useSupplierAccountsSitesIndex,
  type PurchaseOrderResource,
  type PurchaseOrdersIndexFilterApproval,
  type PurchaseOrdersIndexSort,
  type ItemResource,
  type SupplierAccountResource,
  type SupplierSiteResource,
} from "@erp/core-api";

import { cn } from "../../lib/utils";
import { Badge } from "../../components/ui/badge";
import { Combobox } from "../../components/ui/combobox";
import { useItemLookup, useSupplierLookup } from "../../components/lookups/entity-comboboxes";
import { useItemsById, useSuppliersById } from "../../lib/use-records-by-id";
import { DateField } from "../../components/ui/date-field";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
import { Button } from "../../components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "../../components/ui/select";
import {
  Sheet,
  SheetContent,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "../../components/ui/sheet";
import { PageHeader } from "../../components/page/page-header";
import {
  DataTable,
  isColumnVisibleByDefault,
  selectionColumn,
  toTanStackColumns,
} from "../../components/data-table";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "../../components/ui/dropdown-menu";
import {
  PrintLayout,
  PrintableTable,
  PrintTrigger,
  useQuickPrint,
} from "../../components/print";
import {
  apiSortToTableSorting,
  describeSort,
  tableSortingToApiSort,
  useListQueryState,
} from "../../lib/list-query-state";
import { useColumnVisibilityPreference } from "../../lib/use-column-visibility-preference";
import { useDebouncedSearch } from "../../lib/use-debounced-search";
import { useListViewPreference } from "../../lib/use-view-preference";
import { purchaseOrderColumns } from "./purchase-order-columns";
import { hasUnfinishedPurchaseOrder } from "./purchase-order-form";
import { usePermission } from "../../hooks/use-permission";
import { usePurchasingCommercialCost } from "../../hooks/use-purchasing-commercial-cost";
import { lookupState, type LookupState } from "../../lib/api-errors";

interface PurchaseOrderFilters {
  search: string;
  supplier_account_id: string;
  status: string;
  approval: string;
  supplier_site_id: string;
  currency_id: string;
  item_id: string;
  order_date_from: string;
  order_date_to: string;
  expected_delivery_from: string;
  expected_delivery_to: string;
  issued_from: string;
  issued_to: string;
  total_min: string;
  total_max: string;
  [key: string]: string;
}

const STATUSES = ["DRAFT", "ISSUED", "CLOSED", "CANCELLED"] as const;

// Approval states in which a draft cannot be issued yet, and the ones from which it can be (re)submitted.
const BLOCKS_ISSUE = new Set([
  "NOT_SUBMITTED",
  "PENDING",
  "REJECTED",
  "WITHDRAWN",
  "INVALIDATED",
  "EXPIRED",
]);
const SUBMITTABLE = new Set([
  "NOT_SUBMITTED",
  "REJECTED",
  "WITHDRAWN",
  "INVALIDATED",
  "EXPIRED",
]);

// The approval filter judges an order by its latest approval request; NONE is "never sent for approval".
const APPROVAL_FILTER_LABELS: Record<string, string> = {
  PENDING: "Waiting for approval",
  APPROVED: "Approved",
  REJECTED: "Rejected",
  WITHDRAWN: "Withdrawn",
  INVALIDATED: "No longer valid",
  EXPIRED: "Expired",
  NONE: "Not sent for approval",
};

export function PurchaseOrdersListScreen() {
  const mayCreate = usePermission("purchasing.purchase-order.create");
  const mayIssue = usePermission("purchasing.purchase-order.issue");
  const mayCancel = usePermission("purchasing.purchase-order.cancel");
  const maySubmit = usePermission("purchasing.purchase-order.submit");
  const submitForApproval = usePurchaseOrdersSubmit();
  const mayViewCommercialCost = usePurchasingCommercialCost();
  const isDesktop = useMediaQuery(MD_BREAKPOINT_QUERY);
  const [showActiveFilters, setShowActiveFilters] = useState(true);
  const navigate = useNavigate();
  const { search } = useLocation();
  const query = useListQueryState<PurchaseOrderFilters>({
    defaultSort: "-created_at",
    defaultPerPage: 25,
  });
  // The search box answers at once but only asks the server once typing pauses (see useDebouncedSearch).
  const searchBox = useDebouncedSearch(query.filters.search, (value) =>
    query.setFilter("search", value),
  );
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(() =>
    Boolean(
      query.filters.order_date_from ||
      query.filters.order_date_to ||
      query.filters.expected_delivery_from ||
      query.filters.expected_delivery_to ||
      query.filters.issued_from ||
      query.filters.issued_to ||
      query.filters.item_id ||
      query.filters.supplier_site_id ||
      query.filters.currency_id ||
      query.filters.total_min ||
      query.filters.total_max,
    ),
  );
  const { view, setView } = useListViewPreference("purchase-orders-list");

  const selectedSupplierId = query.filters.supplier_account_id
    ? Number(query.filters.supplier_account_id)
    : undefined;

  const sitesQuery = useSupplierAccountsSitesIndex(
    selectedSupplierId ?? 0,
    { per_page: 100 },
    { query: { enabled: Boolean(selectedSupplierId) } },
  );
  const sites =
    sitesQuery.data?.status === 200 ? sitesQuery.data.data.data : [];

  const items = useItemsById<ItemResource>(
    query.filters.item_id ? [Number(query.filters.item_id)] : [],
    [],
  );
  const itemLabels = useMemo(
    () =>
      new Map(items.map((item) => [item.id, `${item.code} — ${item.name}`])),
    [items],
  );

  const currenciesQuery = useCurrenciesIndex({ per_page: 100, sort: "code" });
  const currencies = useMemo(
    () =>
      currenciesQuery.data?.status === 200
        ? currenciesQuery.data.data.data
        : [],
    [currenciesQuery.data],
  );
  const currencyLabels = useMemo(
    () => new Map(currencies.map((currency) => [currency.id, currency.code])),
    [currencies],
  );
  const ordersQuery = usePurchaseOrdersIndex({
    "filter[search]": query.filters.search || undefined,
    "filter[supplier_account_id]": selectedSupplierId,
    "filter[supplier_site_id]": query.filters.supplier_site_id
      ? Number(query.filters.supplier_site_id)
      : undefined,
    "filter[status]":
      (query.filters.status as (typeof STATUSES)[number] | undefined) ||
      undefined,
    "filter[approval]":
      (query.filters.approval as
        PurchaseOrdersIndexFilterApproval | undefined) || undefined,
    "filter[currency_id]": query.filters.currency_id
      ? Number(query.filters.currency_id)
      : undefined,
    "filter[item_id]": query.filters.item_id
      ? Number(query.filters.item_id)
      : undefined,
    "filter[order_date_from]": query.filters.order_date_from || undefined,
    "filter[order_date_to]": query.filters.order_date_to || undefined,
    "filter[expected_delivery_from]":
      query.filters.expected_delivery_from || undefined,
    "filter[expected_delivery_to]":
      query.filters.expected_delivery_to || undefined,
    "filter[issued_from]": query.filters.issued_from || undefined,
    "filter[issued_to]": query.filters.issued_to || undefined,
    "filter[total_min]":
      mayViewCommercialCost && query.filters.total_min
        ? Number(query.filters.total_min)
        : undefined,
    "filter[total_max]":
      mayViewCommercialCost && query.filters.total_max
        ? Number(query.filters.total_max)
        : undefined,
    sort: (!mayViewCommercialCost &&
    query.sort?.replace(/^-/, "") === "total_amount"
      ? "-created_at"
      : query.sort) as PurchaseOrdersIndexSort | undefined,
    include: "lines",
    per_page: query.perPage,
    page: query.page,
  });
  // Chips and columns name the filtered supplier and the supplier of each listed order by id; the pickers search the server.
  const listedSupplierIds = [
    ...(query.filters.supplier_account_id ? [Number(query.filters.supplier_account_id)] : []),
    ...(ordersQuery?.data?.status === 200 ? ordersQuery.data.data.data.map((order) => order.supplier_account_id) : []),
  ];
  const suppliers = useSuppliersById<SupplierAccountResource>(listedSupplierIds, []);
  const supplierLabels = useMemo(
    () =>
      new Map(
        suppliers.map((supplier) => [
          supplier.id,
          `${supplier.code}${supplier.party?.name ? ` — ${supplier.party.name}` : ""}`,
        ]),
      ),
    [suppliers],
  );

  const orders =
    ordersQuery.data?.status === 200 ? ordersQuery.data.data.data : [];
  const meta =
    ordersQuery.data?.status === 200 ? ordersQuery.data.data.meta : undefined;
  // Approvals are "in use" once any order carries an approval status (the API sends null while the company has them
  // switched off). It stays on once seen, so the column and filter do not blink away when a filter empties the page.
  const [approvalsSeen, setApprovalsSeen] = useState(false);
  useEffect(() => {
    if (orders.some((order) => order.approval_status != null))
      setApprovalsSeen(true);
  }, [orders]);
  const approvalsInUse = approvalsSeen || Boolean(query.filters.approval);
  const columns = useMemo(
    () =>
      purchaseOrderColumns(
        supplierLabels,
        currencyLabels,
        mayViewCommercialCost,
        approvalsInUse,
      ),
    [supplierLabels, currencyLabels, mayViewCommercialCost, approvalsInUse],
  );
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    "purchase-orders-list",
    Object.fromEntries(
      columns.map((column) => [column.key, isColumnVisibleByDefault(column)]),
    ),
  );

  const tanStackColumns = useMemo(
    () => [
      selectionColumn<PurchaseOrderResource>(),
      ...toTanStackColumns(columns),
    ],
    [columns],
  );
  // Ticked rows are for bulk actions on the page in view, so any change of page, sort or filters starts a new selection.
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  useEffect(() => setRowSelection({}), [search]);
  const selectedOrders = useMemo(
    () => orders.filter((order) => rowSelection[String(order.id)]),
    [orders, rowSelection],
  );
  const sorting: SortingState = apiSortToTableSorting(query.sort, columns);
  const pagination: PaginationState = {
    pageIndex: query.page - 1,
    pageSize: query.perPage,
  };

  const advancedFilterCount = [
    query.filters.order_date_from || query.filters.order_date_to,
    query.filters.expected_delivery_from || query.filters.expected_delivery_to,
    query.filters.issued_from || query.filters.issued_to,
    query.filters.item_id,
    query.filters.supplier_site_id,
    query.filters.currency_id,
    mayViewCommercialCost &&
      (query.filters.total_min || query.filters.total_max),
  ].filter(Boolean).length;

  const mobileActiveFilterCount = [
    query.filters.supplier_account_id,
    query.filters.status,
    query.filters.approval,
    query.filters.order_date_from || query.filters.order_date_to,
    query.filters.expected_delivery_from || query.filters.expected_delivery_to,
    query.filters.issued_from || query.filters.issued_to,
    query.filters.item_id,
    query.filters.supplier_site_id,
    query.filters.currency_id,
    mayViewCommercialCost &&
      (query.filters.total_min || query.filters.total_max),
  ].filter(Boolean).length;

  const clearAdvancedFilters = () => {
    query.setFilters({
      order_date_from: undefined,
      order_date_to: undefined,
      expected_delivery_from: undefined,
      expected_delivery_to: undefined,
      issued_from: undefined,
      issued_to: undefined,
      item_id: undefined,
      supplier_site_id: undefined,
      currency_id: undefined,
      total_min: undefined,
      total_max: undefined,
    });
  };

  const clearAllFilters = () => {
    query.setFilters({
      supplier_account_id: undefined,
      status: undefined,
      approval: undefined,
      order_date_from: undefined,
      order_date_to: undefined,
      expected_delivery_from: undefined,
      expected_delivery_to: undefined,
      issued_from: undefined,
      issued_to: undefined,
      item_id: undefined,
      supplier_site_id: undefined,
      currency_id: undefined,
      total_min: undefined,
      total_max: undefined,
    });
  };

  const activeFilterLines = describeFilters(
    query.filters,
    supplierLabels,
    currencyLabels,
    itemLabels,
    mayViewCommercialCost,
  );
  const { contentRef: printRef, print } = useQuickPrint({
    documentTitle: "Purchase Order List",
  });

  const { contentRef: selectedPrintRef, print: printSelected } = useQuickPrint({
    documentTitle: "Selected Purchase Orders",
  });

  const activeChips = useMemo(() => {
    const chips: {
      id: string;
      label: string;
      icon: ReactNode;
      onRemove: () => void;
    }[] = [];
    if (query.filters.supplier_account_id) {
      const name =
        supplierLabels.get(Number(query.filters.supplier_account_id)) ??
        `#${query.filters.supplier_account_id}`;
      chips.push({
        id: "supplier",
        icon: <Building2 className="h-3.5 w-3.5" />,
        label: `Supplier: ${name}`,
        onRemove: () =>
          query.setFilters({
            supplier_account_id: undefined,
            supplier_site_id: undefined,
          }),
      });
    }
    if (query.filters.status) {
      chips.push({
        id: "status",
        icon: <Tag className="h-3.5 w-3.5" />,
        label: `Status: ${query.filters.status.replace(/_/g, " ")}`,
        onRemove: () => query.setFilter("status", undefined),
      });
    }
    if (query.filters.approval) {
      chips.push({
        id: "approval",
        icon: <ShieldCheck className="h-3.5 w-3.5" />,
        label: `Approval: ${APPROVAL_FILTER_LABELS[query.filters.approval] ?? query.filters.approval}`,
        onRemove: () => query.setFilter("approval", undefined),
      });
    }
    if (query.filters.order_date_from || query.filters.order_date_to) {
      chips.push({
        id: "order_date",
        icon: <CalendarDays className="h-3.5 w-3.5" />,
        label: `Order date: ${query.filters.order_date_from ?? "…"} – ${query.filters.order_date_to ?? "…"}`,
        onRemove: () =>
          query.setFilters({
            order_date_from: undefined,
            order_date_to: undefined,
          }),
      });
    }
    if (
      query.filters.expected_delivery_from ||
      query.filters.expected_delivery_to
    ) {
      chips.push({
        id: "expected_delivery",
        icon: <CalendarDays className="h-3.5 w-3.5" />,
        label: `Expected: ${query.filters.expected_delivery_from ?? "…"} – ${query.filters.expected_delivery_to ?? "…"}`,
        onRemove: () =>
          query.setFilters({
            expected_delivery_from: undefined,
            expected_delivery_to: undefined,
          }),
      });
    }
    if (query.filters.issued_from || query.filters.issued_to) {
      chips.push({
        id: "issued",
        icon: <CalendarDays className="h-3.5 w-3.5" />,
        label: `Issued: ${query.filters.issued_from ?? "…"} – ${query.filters.issued_to ?? "…"}`,
        onRemove: () =>
          query.setFilters({
            issued_from: undefined,
            issued_to: undefined,
          }),
      });
    }
    if (query.filters.item_id) {
      const name =
        itemLabels.get(Number(query.filters.item_id)) ??
        `#${query.filters.item_id}`;
      chips.push({
        id: "item",
        icon: <Search className="h-3.5 w-3.5" />,
        label: `Item: ${name}`,
        onRemove: () => query.setFilter("item_id", undefined),
      });
    }
    if (query.filters.supplier_site_id) {
      chips.push({
        id: "site",
        icon: <Building2 className="h-3.5 w-3.5" />,
        label: `Site: #${query.filters.supplier_site_id}`,
        onRemove: () => query.setFilter("supplier_site_id", undefined),
      });
    }
    if (query.filters.currency_id) {
      const code =
        currencyLabels.get(Number(query.filters.currency_id)) ??
        `#${query.filters.currency_id}`;
      chips.push({
        id: "currency",
        icon: <Database className="h-3.5 w-3.5" />,
        label: `Currency: ${code}`,
        onRemove: () => query.setFilter("currency_id", undefined),
      });
    }
    if (
      mayViewCommercialCost &&
      (query.filters.total_min || query.filters.total_max)
    ) {
      chips.push({
        id: "total",
        icon: <CircleDollarSign className="h-3.5 w-3.5" />,
        label: `Value: ${query.filters.total_min ?? "0"} – ${query.filters.total_max ?? "…"}`,
        onRemove: () =>
          query.setFilters({
            total_min: undefined,
            total_max: undefined,
          }),
      });
    }
    return chips;
  }, [
    query.filters,
    supplierLabels,
    currencyLabels,
    itemLabels,
    mayViewCommercialCost,
    query,
  ]);

  return (
    // Extra bottom padding below `md` clears the fixed MobileFab — see
    // PageHeader's `primaryAction` note in docs/architecture/user-interface.md.
    <ScreenTemplate kind="list" className="po-screen">
      <PageHeader
        title="Purchase Orders"
        description="Raise supplier commitments, track their lifecycle and continue into receiving."
        primaryAction={
          mayCreate
            ? {
                // Re-read on every render (closing the sheet navigates, which re-renders
                // this list) so the button reflects work left in the sheet.
                label: hasUnfinishedPurchaseOrder()
                  ? "Continue new purchase order"
                  : "New purchase order",
                // Keep the query string: the list stays mounted behind the create
                // sheet and reads its filters/search from the URL.
                onClick: () =>
                  navigate({ pathname: "/purchase-orders/new", search }),
              }
            : undefined
        }
      />
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative flex-1 md:w-80 md:flex-none">
          <Search
            aria-hidden="true"
            className="po-field-icon pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2"
          />
          <Input
            className="h-10 w-full pl-10"
            placeholder="Search orders, suppliers, items…"
            {...searchBox.searchProps}
          />
        </div>
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          <PurchaseOrderFilterControls
            approvalsInUse={approvalsInUse}
            query={query}
          />
          <Button
            variant={
              advancedOpen || advancedFilterCount > 0 ? "secondary" : "outline"
            }
            size="sm"
            className={cn(
              "relative h-10 px-4",
              (advancedOpen || advancedFilterCount > 0) &&
                "po-btn-filters-active",
            )}
            onClick={() => setAdvancedOpen((open) => !open)}
          >
            <SlidersHorizontal className="mr-1.5 h-[18px] w-[18px]" />
            Filters
            {advancedFilterCount > 0 && (
              <Badge
                className={cn(
                  "absolute -right-2 -top-2 h-5 min-w-5 justify-center rounded-full px-1 text-[11px]",
                  (advancedOpen || advancedFilterCount > 0) &&
                    "po-filter-badge",
                )}
              >
                {advancedFilterCount}
              </Badge>
            )}
          </Button>
          <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
        </div>
        <Button
          variant="outline"
          size="sm"
          className="h-10 shrink-0 md:hidden"
          onClick={() => setFiltersOpen(true)}
        >
          <SlidersHorizontal className="h-4 w-4" />
          Filters
          {mobileActiveFilterCount > 0 && (
            <Badge className="ml-1">{mobileActiveFilterCount}</Badge>
          )}
        </Button>
      </div>

      <div
        className={cn(
          "hidden md:grid transition-[grid-template-rows,opacity,transform] duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] motion-reduce:transition-none",
          advancedOpen
            ? "grid-rows-[1fr] opacity-100 translate-y-0"
            : "grid-rows-[0fr] opacity-0 -translate-y-2 pointer-events-none",
        )}
        aria-hidden={!advancedOpen}
        inert={!advancedOpen}
      >
        <div className="overflow-hidden">
          <div className="rounded-lg border bg-card p-4 text-card-foreground shadow-xs po-filter-panel">
            <div className="mb-4 flex items-center justify-between">
              <span className="po-filter-panel-title flex items-center gap-2 text-sm font-semibold">
                <SlidersHorizontal aria-hidden="true" className="h-4 w-4" />
                Advanced filters
              </span>
              <div className="flex items-center gap-2">
                {advancedFilterCount > 0 && (
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 text-xs text-muted-foreground hover:text-foreground"
                    onClick={clearAdvancedFilters}
                  >
                    Reset filters
                  </Button>
                )}
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 gap-1 px-2 text-xs"
                  onClick={() => setAdvancedOpen(false)}
                  aria-label="Close filters"
                >
                  Hide filters
                  <ChevronUp aria-hidden="true" className="h-4 w-4" />
                </Button>
              </div>
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <AdvancedFilterFields
                query={query}
                selectedSupplierId={selectedSupplierId}
                sites={sites}
                currencies={currencies}
                mayViewCommercialCost={mayViewCommercialCost}
              />
            </div>
          </div>
        </div>
      </div>

      <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
        <SheetContent
          side="bottom"
          className="max-h-[85vh] overflow-y-auto md:hidden"
        >
          <SheetHeader>
            <SheetTitle>Filters</SheetTitle>
          </SheetHeader>
          <div className="flex flex-col gap-4 py-4">
            <div className="space-y-3">
              <span className="text-xs font-semibold tracking-wide uppercase text-muted-foreground">
                Primary
              </span>
              <PurchaseOrderFilterControls
                approvalsInUse={approvalsInUse}
                query={query}
                full
              />
            </div>
            <div className="space-y-3 border-t pt-3">
              <span className="text-xs font-semibold tracking-wide uppercase text-muted-foreground">
                Advanced Criteria
              </span>
              <AdvancedFilterFields
                query={query}
                selectedSupplierId={selectedSupplierId}
                sites={sites}
                currencies={currencies}
                mayViewCommercialCost={mayViewCommercialCost}
                full
              />
            </div>
            <PrintTrigger
              onPrint={() => print()}
              className="w-full justify-center"
            />
          </div>
          <SheetFooter>
            <Button variant="ghost" onClick={clearAllFilters}>
              Clear all
            </Button>
            <Button onClick={() => setFiltersOpen(false)}>Done</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>

      {selectedOrders.length > 0 && (
        <div
          role="region"
          aria-label="Bulk actions"
          className="po-bulk-bar flex flex-wrap items-center gap-3 rounded-md border px-3 py-2 text-sm"
        >
          <span className="font-medium">{selectedOrders.length} selected</span>
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

      <DataTable
        columns={tanStackColumns}
        data={orders}
        rowCount={meta?.total ?? 0}
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next =
            typeof updater === "function" ? updater(pagination) : updater;
          if (next.pageSize !== pagination.pageSize)
            query.setPerPage(next.pageSize);
          else query.setPage(next.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(updater) => {
          const next =
            typeof updater === "function" ? updater(sorting) : updater;
          query.setSort(tableSortingToApiSort(next, columns));
        }}
        columnVisibility={columnVisibility}
        onColumnVisibilityChange={setColumnVisibility}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        getRowId={(row) => String(row.id)}
        onRowClick={(order: PurchaseOrderResource) =>
          navigate({ pathname: `/purchase-orders/${order.id}`, search })
        }
        isLoading={ordersQuery.isLoading}
        isError={ordersQuery.isError}
        error={ordersQuery.error}
        onRetry={() => void ordersQuery.refetch()}
        errorMessage="Couldn't load purchase orders."
        emptyMessage="No purchase orders match these filters."
        view={view}
        onViewChange={setView}
        cardColumns={columns}
        viewOptionsMenuClassName="po-dropdown-content"
        recordNoun="purchase orders"
        viewOptionsIcon={<Columns3 className="h-[18px] w-[18px]" />}
        viewOptionsTriggerClassName="h-10 px-4"
        actionsHeader="Actions"
        numberedPagination
        toolbarStart={
          activeChips.length > 0 ? (
            <div className="space-y-2">
              <div className="flex items-center justify-between gap-2 md:hidden">
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  aria-expanded={showActiveFilters}
                  aria-controls="po-active-filters"
                  onClick={() => setShowActiveFilters(!showActiveFilters)}
                >
                  Active filters ({activeChips.length}) ·{" "}
                  {showActiveFilters ? "Hide" : "Show"}
                </Button>
                <button
                  type="button"
                  className="po-link-action shrink-0"
                  onClick={clearAllFilters}
                >
                  Clear all
                </button>
              </div>
              <Collapse
                open={isDesktop || showActiveFilters}
                id="po-active-filters"
              >
                <div className="flex flex-wrap items-center gap-2">
                  <span className="hidden text-sm font-medium md:inline">
                    Active filters
                  </span>
                  {activeChips.map((chip) => (
                    <span
                      key={chip.id}
                      className="po-chip-active inline-flex max-w-full items-center rounded-full border bg-primary/5 px-3 py-1.5 text-xs text-primary !gap-2 [&>svg]:shrink-0"
                    >
                      {chip.icon}
                      <span className="min-w-0 break-words">{chip.label}</span>
                      <button
                        type="button"
                        className="shrink-0"
                        aria-label={`Remove filter ${chip.label}`}
                        onClick={chip.onRemove}
                      >
                        <X className="h-3 w-3" />
                      </button>
                    </span>
                  ))}
                  <button
                    type="button"
                    className="po-link-action hidden md:inline"
                    onClick={clearAllFilters}
                  >
                    Clear all
                  </button>
                </div>
              </Collapse>
            </div>
          ) : undefined
        }
        rowActions={(order: PurchaseOrderResource) => (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                size="icon"
                className="h-8 w-8"
                aria-label={`Actions for ${order.number ?? `draft ${order.id}`}`}
              >
                <EllipsisVertical className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="po-dropdown-content">
              <DropdownMenuItem
                onSelect={() =>
                  navigate({ pathname: `/purchase-orders/${order.id}`, search })
                }
              >
                Open order
              </DropdownMenuItem>
              {/* Quick actions only: each opens where the work is done, with the confirmation (and any
                  approval rule) the order screen already enforces — nothing is changed from the list itself. */}
              {order.status === "DRAFT" && mayCreate && (
                <DropdownMenuItem
                  onSelect={() =>
                    navigate({
                      pathname: `/purchase-orders/${order.id}/edit`,
                      search,
                    })
                  }
                >
                  Edit draft
                </DropdownMenuItem>
              )}
              {/* A draft that must be approved first is sent for approval from here; it can only be issued once approved. */}
              {order.status === "DRAFT" &&
                maySubmit &&
                SUBMITTABLE.has(order.approval_status ?? "") && (
                  <DropdownMenuItem
                    disabled={submitForApproval.isPending}
                    onSelect={() =>
                      submitForApproval.mutate({ purchaseOrder: order.id })
                    }
                  >
                    Submit for approval
                  </DropdownMenuItem>
                )}
              {order.status === "DRAFT" &&
                mayIssue &&
                !BLOCKS_ISSUE.has(order.approval_status ?? "") && (
                  <DropdownMenuItem
                    onSelect={() =>
                      navigate(
                        { pathname: `/purchase-orders/${order.id}`, search },
                        { state: { quickAction: "issue" } },
                      )
                    }
                  >
                    Issue order…
                  </DropdownMenuItem>
                )}
              {order.status === "ISSUED" && mayCancel && (
                <DropdownMenuItem
                  onSelect={() =>
                    navigate(
                      { pathname: `/purchase-orders/${order.id}`, search },
                      { state: { quickAction: "cancel" } },
                    )
                  }
                >
                  Cancel order…
                </DropdownMenuItem>
              )}
              {order.number && (
                <DropdownMenuItem
                  onSelect={() =>
                    void navigator.clipboard?.writeText(order.number ?? "")
                  }
                >
                  Copy order number
                </DropdownMenuItem>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        )}
      />

      {/* Print selected — the same layout, limited to the ticked rows. */}
      <div className="hidden">
        <div ref={selectedPrintRef}>
          <PrintLayout
            title="PURCHASE ORDER LIST"
            printedAt={new Date()}
            recordCount={selectedOrders.length}
            meta={[
              {
                label: "Selection",
                value: `${selectedOrders.length} selected purchase orders`,
              },
            ]}
          >
            <PrintableTable
              columns={columns}
              rows={selectedOrders}
              getRowKey={(order) => order.id}
            />
          </PrintLayout>
        </div>
      </div>

      {/* Quick Print — a dedicated print-only layout, hidden until react-to-print clones it. */}
      <div className="hidden">
        <div ref={printRef}>
          <PrintLayout
            title="PURCHASE ORDER LIST"
            printedAt={new Date()}
            recordCount={orders.length}
            meta={[
              ...(activeFilterLines.length > 0
                ? [{ label: "Filters", value: activeFilterLines.join(", ") }]
                : []),
              ...(query.sort
                ? [
                    {
                      label: "Sort",
                      value: describeSort(query.sort, columns) ?? query.sort,
                    },
                  ]
                : []),
            ]}
          >
            <PrintableTable
              columns={columns}
              rows={orders}
              getRowKey={(order) => order.id}
            />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}

function describeFilters(
  filters: Partial<PurchaseOrderFilters>,
  supplierLabels: ReadonlyMap<number, string>,
  currencyLabels: ReadonlyMap<number, string>,
  itemLabels: ReadonlyMap<number, string>,
  mayViewCommercialCost: boolean,
): string[] {
  const lines: string[] = [];
  if (filters.search) lines.push(`Search = "${filters.search}"`);
  if (filters.supplier_account_id) {
    lines.push(
      `Supplier = ${supplierLabels.get(Number(filters.supplier_account_id)) ?? filters.supplier_account_id}`,
    );
  }
  if (filters.status) lines.push(`Status = ${filters.status}`);
  if (filters.approval)
    lines.push(
      `Approval = ${APPROVAL_FILTER_LABELS[filters.approval] ?? filters.approval}`,
    );
  if (filters.order_date_from || filters.order_date_to) {
    lines.push(
      `Order Date = ${filters.order_date_from ?? "…"} to ${filters.order_date_to ?? "…"}`,
    );
  }
  if (filters.expected_delivery_from || filters.expected_delivery_to) {
    lines.push(
      `Delivery = ${filters.expected_delivery_from ?? "…"} to ${filters.expected_delivery_to ?? "…"}`,
    );
  }
  if (filters.issued_from || filters.issued_to) {
    lines.push(
      `Issued = ${filters.issued_from ?? "…"} to ${filters.issued_to ?? "…"}`,
    );
  }
  if (filters.item_id) {
    lines.push(
      `Item = ${itemLabels.get(Number(filters.item_id)) ?? `#${filters.item_id}`}`,
    );
  }
  if (filters.supplier_site_id) {
    lines.push(`Site = #${filters.supplier_site_id}`);
  }
  if (filters.currency_id) {
    lines.push(
      `Currency = ${currencyLabels.get(Number(filters.currency_id)) ?? filters.currency_id}`,
    );
  }
  if (mayViewCommercialCost && (filters.total_min || filters.total_max)) {
    lines.push(
      `Value = ${filters.total_min ?? "0"} to ${filters.total_max ?? "…"}`,
    );
  }

  return lines;
}

interface PurchaseOrderFilterControlsProps {
  /** Show the Approval filter — only for a company that uses approvals. */
  approvalsInUse?: boolean;
  query: ReturnType<typeof useListQueryState<PurchaseOrderFilters>>;
  full?: boolean;
}

/** Supplier/Status selects — shared by the always-visible desktop row and the mobile Filters sheet. */
function PurchaseOrderFilterControls({
  approvalsInUse,
  query,
  full,
}: PurchaseOrderFilterControlsProps) {
  const supplierLookup = useSupplierLookup(query.filters.supplier_account_id ?? "all", { allLabel: "All suppliers" });

  return (
    <>
      <Combobox
        {...supplierLookup}
        value={query.filters.supplier_account_id ?? "all"}
        onValueChange={(value) =>
          query.setFilters({
            supplier_account_id: value === "all" ? undefined : value,
            supplier_site_id: undefined,
          })
        }
        placeholder="Supplier"
        aria-label="Supplier"
        searchPlaceholder="Search suppliers…"
        emptyText="No suppliers found."
        className={full ? "h-9 w-full" : "h-10 w-56"}
        popoverClassName="po-popover-content"
        icon={
          full ? undefined : (
            <Building2 className="po-field-icon h-[18px] w-[18px]" />
          )
        }
      />
      <Select
        value={query.filters.status ?? "all"}
        onValueChange={(value) =>
          query.setFilter("status", value === "all" ? undefined : value)
        }
      >
        <SelectTrigger
          className={full ? "h-9 w-full" : "h-10 w-44"}
          aria-label="Status"
        >
          <div className="flex min-w-0 items-center gap-2">
            {!full && (
              <Tag
                aria-hidden="true"
                className="po-field-icon h-[18px] w-[18px] shrink-0"
              />
            )}
            <SelectValue placeholder="Status" />
          </div>
        </SelectTrigger>
        <SelectContent className="po-select-content">
          <SelectItem value="all">All statuses</SelectItem>
          {STATUSES.map((status) => (
            <SelectItem key={status} value={status}>
              {status}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      {approvalsInUse && (
        <Select
          value={query.filters.approval ?? "all"}
          onValueChange={(value) =>
            query.setFilter("approval", value === "all" ? undefined : value)
          }
        >
          <SelectTrigger
            className={full ? "h-9 w-full" : "h-10 w-52"}
            aria-label="Approval"
          >
            <div className="flex min-w-0 items-center gap-2">
              {!full && (
                <ShieldCheck
                  aria-hidden="true"
                  className="po-field-icon h-[18px] w-[18px] shrink-0"
                />
              )}
              <SelectValue placeholder="Approval" />
            </div>
          </SelectTrigger>
          <SelectContent className="po-select-content">
            <SelectItem value="all">All approvals</SelectItem>
            {Object.entries(APPROVAL_FILTER_LABELS).map(([value, label]) => (
              <SelectItem key={value} value={value}>
                {label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )}
    </>
  );
}

interface AdvancedFilterFieldsProps {
  query: ReturnType<typeof useListQueryState<PurchaseOrderFilters>>;
  selectedSupplierId?: number;
  sites: SupplierSiteResource[];
  currencies: { id: number; code: string; name: string }[];
  mayViewCommercialCost: boolean;
  full?: boolean;
}

function AdvancedFilterFields({
  query,
  selectedSupplierId,
  sites,
  currencies,
  mayViewCommercialCost,
  full,
}: AdvancedFilterFieldsProps) {
  const fieldH = full ? "h-9" : "h-10";
  const itemLookup = useItemLookup(query.filters.item_id ?? "all", { allLabel: "All items" });

  return (
    <>
      <div className="space-y-1.5">
        <Label className="text-sm font-medium">Order date range</Label>
        <div className="flex items-center gap-1.5">
          <DateField
            aria-label="Order date from"
            value={query.filters.order_date_from ?? ""}
            onChange={(val) =>
              query.setFilter("order_date_from", val || undefined)
            }
            className={cn(fieldH, "w-full")}
            popoverClassName="po-popover-content"
            pickerPosition={full ? "end" : "start"}
          />
          <span className="text-xs text-muted-foreground">–</span>
          <DateField
            aria-label="Order date to"
            value={query.filters.order_date_to ?? ""}
            onChange={(val) =>
              query.setFilter("order_date_to", val || undefined)
            }
            className={cn(fieldH, "w-full")}
            popoverClassName="po-popover-content"
            pickerPosition={full ? "end" : "start"}
          />
        </div>
      </div>

      <div className="space-y-1.5">
        <Label className="text-sm font-medium">Expected delivery range</Label>
        <div className="flex items-center gap-1.5">
          <DateField
            aria-label="Expected delivery from"
            value={query.filters.expected_delivery_from ?? ""}
            onChange={(val) =>
              query.setFilter("expected_delivery_from", val || undefined)
            }
            className={cn(fieldH, "w-full")}
            popoverClassName="po-popover-content"
            pickerPosition={full ? "end" : "start"}
          />
          <span className="text-xs text-muted-foreground">–</span>
          <DateField
            aria-label="Expected delivery to"
            value={query.filters.expected_delivery_to ?? ""}
            onChange={(val) =>
              query.setFilter("expected_delivery_to", val || undefined)
            }
            className={cn(fieldH, "w-full")}
            popoverClassName="po-popover-content"
            pickerPosition={full ? "end" : "start"}
          />
        </div>
      </div>

      <div className="space-y-1.5">
        <Label className="text-sm font-medium">Issued date range</Label>
        <div className="flex items-center gap-1.5">
          <DateField
            aria-label="Issued date from"
            value={query.filters.issued_from ?? ""}
            onChange={(val) => query.setFilter("issued_from", val || undefined)}
            className={cn(fieldH, "w-full")}
            popoverClassName="po-popover-content"
            pickerPosition={full ? "end" : "start"}
          />
          <span className="text-xs text-muted-foreground">–</span>
          <DateField
            aria-label="Issued date to"
            value={query.filters.issued_to ?? ""}
            onChange={(val) => query.setFilter("issued_to", val || undefined)}
            className={cn(fieldH, "w-full")}
            popoverClassName="po-popover-content"
            pickerPosition={full ? "end" : "start"}
          />
        </div>
      </div>

      <div className="space-y-1.5">
        <Label className="text-sm font-medium">Contains item</Label>
        <Combobox
          {...itemLookup}
          value={query.filters.item_id ?? "all"}
          onValueChange={(val) =>
            query.setFilter("item_id", val === "all" ? undefined : val)
          }
          placeholder="Contains item"
          aria-label="Contains item"
          searchPlaceholder="Search items…"
          emptyText="No items found."
          className={cn(fieldH, "w-full")}
          popoverClassName="po-popover-content"
          icon={
            full ? undefined : (
              <Search className="po-field-icon h-[18px] w-[18px]" />
            )
          }
        />
      </div>

      <div className="space-y-1.5">
        <Label className="text-sm font-medium">Supplier site</Label>
        {selectedSupplierId ? (
          <Select
            value={query.filters.supplier_site_id ?? "all"}
            onValueChange={(val) =>
              query.setFilter(
                "supplier_site_id",
                val === "all" ? undefined : val,
              )
            }
          >
            <SelectTrigger
              className={cn(fieldH, "w-full")}
              aria-label="Supplier site"
            >
              <div className="flex min-w-0 items-center gap-2">
                {!full && (
                  <Building2
                    aria-hidden="true"
                    className="po-field-icon h-[18px] w-[18px] shrink-0"
                  />
                )}
                <SelectValue placeholder="All sites" />
              </div>
            </SelectTrigger>
            <SelectContent className="po-select-content">
              <SelectItem value="all">All sites</SelectItem>
              {sites.map((site) => (
                <SelectItem key={site.id} value={String(site.id)}>
                  {site.party_site?.code ?? `#${site.id}`}
                  {site.party_site?.name ? ` — ${site.party_site.name}` : ""}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        ) : (
          <div
            className={cn(
              "po-field-disabled flex items-center gap-2 rounded-md border border-dashed px-3 text-sm text-muted-foreground",
              fieldH,
            )}
          >
            {!full && (
              <Building2 aria-hidden="true" className="h-4 w-4 shrink-0" />
            )}
            Select supplier above
          </div>
        )}
      </div>

      {mayViewCommercialCost && (
        <>
          <div className="space-y-1.5">
            <Label className="text-sm font-medium">Total value range</Label>
            <div className="flex items-center gap-1.5">
              <Input
                type="number"
                placeholder="Min"
                aria-label="Min total value"
                value={query.filters.total_min ?? ""}
                onChange={(e) =>
                  query.setFilter("total_min", e.target.value || undefined)
                }
                className={cn(fieldH, "w-full")}
              />
              <span className="text-xs text-muted-foreground">–</span>
              <Input
                type="number"
                placeholder="Max"
                aria-label="Max total value"
                value={query.filters.total_max ?? ""}
                onChange={(e) =>
                  query.setFilter("total_max", e.target.value || undefined)
                }
                className={cn(fieldH, "w-full")}
              />
            </div>
          </div>

          <div className="space-y-1.5">
            <Label className="text-sm font-medium">Currency</Label>
            <Select
              value={query.filters.currency_id ?? "all"}
              onValueChange={(val) =>
                query.setFilter("currency_id", val === "all" ? undefined : val)
              }
            >
              <SelectTrigger
                className={cn(fieldH, "w-full")}
                aria-label="Currency"
              >
                <div className="flex min-w-0 items-center gap-2">
                  {!full && (
                    <Database
                      aria-hidden="true"
                      className="po-field-icon h-[18px] w-[18px] shrink-0"
                    />
                  )}
                  <SelectValue placeholder="All currencies" />
                </div>
              </SelectTrigger>
              <SelectContent className="po-select-content">
                <SelectItem value="all">All currencies</SelectItem>
                {currencies.map((currency) => (
                  <SelectItem key={currency.id} value={String(currency.id)}>
                    {currency.code} ({currency.name})
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </>
      )}
    </>
  );
}
