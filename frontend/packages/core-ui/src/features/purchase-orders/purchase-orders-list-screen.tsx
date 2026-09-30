import { useMemo, useState } from "react";
import { useLocation, useNavigate } from "react-router";
import { SlidersHorizontal, X } from "lucide-react";
import type { PaginationState, SortingState } from "@tanstack/react-table";
import {
  useCurrenciesIndex,
  useItemsIndex,
  usePurchaseOrdersIndex,
  useSupplierAccountsIndex,
  useSupplierAccountsSitesIndex,
  type PurchaseOrderResource,
  type PurchaseOrdersIndexSort,
  type SupplierSiteResource,
} from "@erp/core-api";

import { cn } from "../../lib/utils";
import { Badge } from "../../components/ui/badge";
import { Combobox } from "../../components/ui/combobox";
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
  toTanStackColumns,
} from "../../components/data-table";
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

export function PurchaseOrdersListScreen() {
  const mayCreate = usePermission("purchasing.purchase-order.create");
  const mayViewCommercialCost = usePurchasingCommercialCost();
  const navigate = useNavigate();
  const { search } = useLocation();
  const query = useListQueryState<PurchaseOrderFilters>({
    defaultSort: "-created_at",
    defaultPerPage: 25,
  });
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

  const suppliersQuery = useSupplierAccountsIndex({
    per_page: 100,
    sort: "code",
    include: "party",
  });
  const suppliers = useMemo(
    () =>
      suppliersQuery.data?.status === 200 ? suppliersQuery.data.data.data : [],
    [suppliersQuery.data],
  );
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

  const itemsQuery = useItemsIndex({ per_page: 100, sort: "name" });
  const items =
    itemsQuery.data?.status === 200 ? itemsQuery.data.data.data : [];
  const itemLabels = useMemo(
    () =>
      new Map(
        items.map((item) => [item.id, `${item.code} — ${item.name}`]),
      ),
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
    () =>
      new Map(currencies.map((currency) => [currency.id, currency.code])),
    [currencies],
  );
  const columns = useMemo(
    () =>
      purchaseOrderColumns(
        supplierLabels,
        currencyLabels,
        mayViewCommercialCost,
      ),
    [supplierLabels, currencyLabels, mayViewCommercialCost],
  );
  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    "purchase-orders-list",
    Object.fromEntries(
      columns.map((column) => [column.key, isColumnVisibleByDefault(column)]),
    ),
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
  const orders =
    ordersQuery.data?.status === 200 ? ordersQuery.data.data.data : [];
  const meta =
    ordersQuery.data?.status === 200 ? ordersQuery.data.data.meta : undefined;
  const tanStackColumns = useMemo(() => toTanStackColumns(columns), [columns]);
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
    mayViewCommercialCost && (query.filters.total_min || query.filters.total_max),
  ].filter(Boolean).length;

  const mobileActiveFilterCount = [
    query.filters.supplier_account_id,
    query.filters.status,
    query.filters.order_date_from || query.filters.order_date_to,
    query.filters.expected_delivery_from || query.filters.expected_delivery_to,
    query.filters.issued_from || query.filters.issued_to,
    query.filters.item_id,
    query.filters.supplier_site_id,
    query.filters.currency_id,
    mayViewCommercialCost && (query.filters.total_min || query.filters.total_max),
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

  return (
    // Extra bottom padding below `md` clears the fixed MobileFab — see
    // PageHeader's `primaryAction` note in docs/architecture/user-interface.md.
    <div className="space-y-4 p-4 pb-24 md:p-6">
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
        <Input
          className="h-9 flex-1 md:w-64 md:flex-none"
          placeholder="Search orders, suppliers, items…"
          value={query.filters.search ?? ""}
          onChange={(event) =>
            query.setFilter("search", event.target.value || undefined)
          }
        />
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          <PurchaseOrderFilterControls
            suppliersLookup={lookupState(suppliersQuery, "suppliers")}
            suppliers={suppliers}
            supplierLabels={supplierLabels}
            query={query}
          />
          <Button
            variant={advancedOpen || advancedFilterCount > 0 ? "secondary" : "outline"}
            size="sm"
            className="h-9"
            onClick={() => setAdvancedOpen((open) => !open)}
          >
            <SlidersHorizontal className="mr-1.5 h-4 w-4" />
            Filters
            {advancedFilterCount > 0 && (
              <Badge className="ml-1.5">{advancedFilterCount}</Badge>
            )}
          </Button>
          <div className="ml-auto flex items-center gap-2">
            <PrintTrigger onPrint={() => print()} />
          </div>
        </div>
        <Button
          variant="outline"
          size="sm"
          className="h-9 shrink-0 md:hidden"
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
          <div className="rounded-lg border bg-card p-4 text-card-foreground shadow-xs">
            <div className="mb-3 flex items-center justify-between">
              <span className="text-xs font-semibold tracking-wide uppercase text-muted-foreground">
                Advanced Filters
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
                  className="h-7 w-7 p-0"
                  onClick={() => setAdvancedOpen(false)}
                  aria-label="Close filters"
                >
                  <X className="h-4 w-4" />
                </Button>
              </div>
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <AdvancedFilterFields
                query={query}
                itemsLookup={lookupState(itemsQuery, "items")}
                items={items}
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
        <SheetContent side="bottom" className="max-h-[85vh] overflow-y-auto md:hidden">
          <SheetHeader>
            <SheetTitle>Filters</SheetTitle>
          </SheetHeader>
          <div className="flex flex-col gap-4 py-4">
            <div className="space-y-3">
              <span className="text-xs font-semibold tracking-wide uppercase text-muted-foreground">
                Primary
              </span>
              <PurchaseOrderFilterControls
                suppliersLookup={lookupState(suppliersQuery, "suppliers")}
                suppliers={suppliers}
                supplierLabels={supplierLabels}
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
                itemsLookup={lookupState(itemsQuery, "items")}
                items={items}
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
      />

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
    </div>
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
  suppliersLookup: LookupState;
  suppliers: { id: number }[];
  supplierLabels: ReadonlyMap<number, string>;
  query: ReturnType<typeof useListQueryState<PurchaseOrderFilters>>;
  full?: boolean;
}

/** Supplier/Status selects — shared by the always-visible desktop row and the mobile Filters sheet. */
function PurchaseOrderFilterControls({
  suppliersLookup,
  suppliers,
  supplierLabels,
  query,
  full,
}: PurchaseOrderFilterControlsProps) {
  const supplierOptions = [
    { value: "all", label: "All suppliers" },
    ...suppliers.map((supplier) => ({
      value: String(supplier.id),
      label: supplierLabels.get(supplier.id) ?? `#${supplier.id}`,
    })),
  ];

  return (
    <>
      <Combobox
        {...suppliersLookup}
        value={query.filters.supplier_account_id ?? "all"}
        onValueChange={(value) =>
          query.setFilters({
            supplier_account_id: value === "all" ? undefined : value,
            supplier_site_id: undefined,
          })
        }
        options={supplierOptions}
        placeholder="Supplier"
        aria-label="Supplier"
        searchPlaceholder="Search suppliers…"
        emptyText="No suppliers found."
        className={full ? "h-9 w-full" : "h-9 w-56"}
      />
      <Select
        value={query.filters.status ?? "all"}
        onValueChange={(value) =>
          query.setFilter("status", value === "all" ? undefined : value)
        }
      >
        <SelectTrigger
          className={full ? "h-9 w-full" : "h-9 w-40"}
          aria-label="Status"
        >
          <SelectValue placeholder="Status" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All statuses</SelectItem>
          {STATUSES.map((status) => (
            <SelectItem key={status} value={status}>
              {status}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </>
  );
}

interface AdvancedFilterFieldsProps {
  query: ReturnType<typeof useListQueryState<PurchaseOrderFilters>>;
  itemsLookup: LookupState;
  items: { id: number; code: string; name: string }[];
  selectedSupplierId?: number;
  sites: SupplierSiteResource[];
  currencies: { id: number; code: string; name: string }[];
  mayViewCommercialCost: boolean;
  full?: boolean;
}

function AdvancedFilterFields({
  query,
  itemsLookup,
  items,
  selectedSupplierId,
  sites,
  currencies,
  mayViewCommercialCost,
  full,
}: AdvancedFilterFieldsProps) {
  const itemOptions = [
    { value: "all", label: "All items" },
    ...items.map((item) => ({
      value: String(item.id),
      label: `${item.code} — ${item.name}`,
    })),
  ];

  return (
    <>
      <div className={cn("space-y-1.5", !full && "sm:col-span-2")}>
        <Label className="text-xs font-medium text-muted-foreground">
          Order date range
        </Label>
        <div className="flex items-center gap-1.5">
          <DateField
            aria-label="Order date from"
            value={query.filters.order_date_from ?? ""}
            onChange={(val) => query.setFilter("order_date_from", val || undefined)}
            className="w-full"
          />
          <span className="text-xs text-muted-foreground">–</span>
          <DateField
            aria-label="Order date to"
            value={query.filters.order_date_to ?? ""}
            onChange={(val) => query.setFilter("order_date_to", val || undefined)}
            className="w-full"
          />
        </div>
      </div>

      <div className={cn("space-y-1.5", !full && "sm:col-span-2")}>
        <Label className="text-xs font-medium text-muted-foreground">
          Expected delivery range
        </Label>
        <div className="flex items-center gap-1.5">
          <DateField
            aria-label="Expected delivery from"
            value={query.filters.expected_delivery_from ?? ""}
            onChange={(val) =>
              query.setFilter("expected_delivery_from", val || undefined)
            }
            className="w-full"
          />
          <span className="text-xs text-muted-foreground">–</span>
          <DateField
            aria-label="Expected delivery to"
            value={query.filters.expected_delivery_to ?? ""}
            onChange={(val) =>
              query.setFilter("expected_delivery_to", val || undefined)
            }
            className="w-full"
          />
        </div>
      </div>

      <div className={cn("space-y-1.5", !full && "sm:col-span-2")}>
        <Label className="text-xs font-medium text-muted-foreground">
          Issued date range
        </Label>
        <div className="flex items-center gap-1.5">
          <DateField
            aria-label="Issued date from"
            value={query.filters.issued_from ?? ""}
            onChange={(val) => query.setFilter("issued_from", val || undefined)}
            className="w-full"
          />
          <span className="text-xs text-muted-foreground">–</span>
          <DateField
            aria-label="Issued date to"
            value={query.filters.issued_to ?? ""}
            onChange={(val) => query.setFilter("issued_to", val || undefined)}
            className="w-full"
          />
        </div>
      </div>

      <div className={cn("space-y-1.5", !full && "sm:col-span-1")}>
        <Label className="text-xs font-medium text-muted-foreground">
          Contains item
        </Label>
        <Combobox
          {...itemsLookup}
          value={query.filters.item_id ?? "all"}
          onValueChange={(val) =>
            query.setFilter("item_id", val === "all" ? undefined : val)
          }
          options={itemOptions}
          placeholder="Contains item"
          aria-label="Contains item"
          searchPlaceholder="Search items…"
          emptyText="No items found."
          className="h-9 w-full"
        />
      </div>

      <div className={cn("space-y-1.5", !full && "sm:col-span-1")}>
        <Label className="text-xs font-medium text-muted-foreground">
          Supplier site
        </Label>
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
              className="h-9 w-full"
              aria-label="Supplier site"
            >
              <SelectValue placeholder="All sites" />
            </SelectTrigger>
            <SelectContent>
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
          <div className="flex h-9 items-center rounded-md border border-dashed px-3 text-xs text-muted-foreground">
            Select supplier above
          </div>
        )}
      </div>

      {mayViewCommercialCost && (
        <>
          <div className={cn("space-y-1.5", !full && "sm:col-span-2")}>
            <Label className="text-xs font-medium text-muted-foreground">
              Total value range
            </Label>
            <div className="flex items-center gap-1.5">
              <Input
                type="number"
                placeholder="Min"
                aria-label="Min total value"
                value={query.filters.total_min ?? ""}
                onChange={(e) =>
                  query.setFilter("total_min", e.target.value || undefined)
                }
                className="h-9 w-full"
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
                className="h-9 w-full"
              />
            </div>
          </div>

          <div className={cn("space-y-1.5", !full && "sm:col-span-2")}>
            <Label className="text-xs font-medium text-muted-foreground">
              Currency
            </Label>
            <Select
              value={query.filters.currency_id ?? "all"}
              onValueChange={(val) =>
                query.setFilter("currency_id", val === "all" ? undefined : val)
              }
            >
              <SelectTrigger
                className="h-9 w-full"
                aria-label="Currency"
              >
                <SelectValue placeholder="All currencies" />
              </SelectTrigger>
              <SelectContent>
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
