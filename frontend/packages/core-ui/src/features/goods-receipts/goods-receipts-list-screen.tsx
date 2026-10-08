import { useMemo, useState, type ReactNode } from "react";
import { useLocation, useNavigate } from "react-router";
import { useQueryClient } from "@tanstack/react-query";
import type {
  PaginationState,
  RowSelectionState,
  SortingState,
} from "@tanstack/react-table";
import {
  useCurrenciesIndex,
  useGoodsReceiptsIndex,
  useSupplierAccountsIndex,
  type GoodsReceiptResource,
  type GoodsReceiptsIndexSort,
} from "@erp/core-api";
import {
  Building2,
  CalendarDays,
  ChevronUp,
  CircleDollarSign,
  EllipsisVertical,
  Pencil,
  Search,
  SlidersHorizontal,
  Tag,
  X,
} from "lucide-react";

import { cn } from "../../lib/utils";
import { ScreenTemplate } from "../../components/page/screen-template";
import { PageHeader } from "../../components/page/page-header";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { Combobox } from "../../components/ui/combobox";
import { DateField } from "../../components/ui/date-field";
import { Input } from "../../components/ui/input";
import { Label } from "../../components/ui/label";
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
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "../../components/ui/dropdown-menu";
import {
  DataTable,
  isColumnVisibleByDefault,
  selectionColumn,
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
import { useDebouncedSearch } from "../../lib/use-debounced-search";
import { useListViewPreference } from "../../lib/use-view-preference";
import { usePermission } from "../../hooks/use-permission";
import { usePurchasingCommercialCost } from "../../hooks/use-purchasing-commercial-cost";
import { lookupState, type LookupState } from "../../lib/api-errors";
import { goodsReceiptColumns } from "./goods-receipt-columns";
import { hasUnfinishedGoodsReceipt } from "./goods-receipt-form";

interface ReceiptFilters {
  search: string;
  supplier_account_id: string;
  status: string;
  posting_date_from: string;
  posting_date_to: string;
  physical_arrival_from: string;
  physical_arrival_to: string;
  total_min: string;
  total_max: string;
  [key: string]: string;
}

const STATUSES = ["DRAFT", "POSTED", "REVERSED"] as const;

export function GoodsReceiptsListScreen() {
  const mayCreate = usePermission("purchasing.goods-receipt.create");
  const mayViewCommercialCost = usePurchasingCommercialCost();
  const navigate = useNavigate();
  const { search } = useLocation();
  const queryClient = useQueryClient();

  const query = useListQueryState<ReceiptFilters>({
    defaultSort: "-created_at",
    defaultPerPage: 25,
  });

  const searchBox = useDebouncedSearch(query.filters.search, (value) =>
    query.setFilter("search", value),
  );

  const [filtersOpen, setFiltersOpen] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(() =>
    Boolean(
      query.filters.posting_date_from ||
      query.filters.posting_date_to ||
      query.filters.physical_arrival_from ||
      query.filters.physical_arrival_to ||
      query.filters.total_min ||
      query.filters.total_max,
    ),
  );
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});

  const { view, setView } = useListViewPreference("goods-receipts-list");

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

  const currenciesQuery = useCurrenciesIndex(
    { per_page: 100, sort: "code" },
    { query: { enabled: mayViewCommercialCost } },
  );
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

  const columns = useMemo(
    () =>
      goodsReceiptColumns(
        supplierLabels,
        currencyLabels,
        mayViewCommercialCost,
      ),
    [supplierLabels, currencyLabels, mayViewCommercialCost],
  );

  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    "goods-receipts-list",
    Object.fromEntries(
      columns.map((column) => [column.key, isColumnVisibleByDefault(column)]),
    ),
  );

  const receiptsQuery = useGoodsReceiptsIndex({
    "filter[search]": query.filters.search || undefined,
    "filter[supplier_account_id]": query.filters.supplier_account_id
      ? Number(query.filters.supplier_account_id)
      : undefined,
    "filter[status]":
      query.filters.status && query.filters.status !== "ALL"
        ? (query.filters.status as (typeof STATUSES)[number])
        : undefined,
    "filter[posting_date_from]": query.filters.posting_date_from || undefined,
    "filter[posting_date_to]": query.filters.posting_date_to || undefined,
    "filter[physical_arrival_from]":
      query.filters.physical_arrival_from || undefined,
    "filter[physical_arrival_to]":
      query.filters.physical_arrival_to || undefined,
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
      : query.sort) as GoodsReceiptsIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });

  const receipts =
    receiptsQuery.data?.status === 200 ? receiptsQuery.data.data.data : [];
  const meta =
    receiptsQuery.data?.status === 200
      ? receiptsQuery.data.data.meta
      : undefined;

  const tanStackColumns = useMemo(
    () => [
      selectionColumn<GoodsReceiptResource>(),
      ...toTanStackColumns(columns),
    ],
    [columns],
  );
  const sorting: SortingState = apiSortToTableSorting(query.sort, columns);
  const pagination: PaginationState = {
    pageIndex: query.page - 1,
    pageSize: query.perPage,
  };

  const selectedReceipts = useMemo(
    () => receipts.filter((r) => rowSelection[String(r.id)]),
    [receipts, rowSelection],
  );

  const advancedFilterCount = [
    query.filters.posting_date_from || query.filters.posting_date_to,
    query.filters.physical_arrival_from || query.filters.physical_arrival_to,
    mayViewCommercialCost &&
      (query.filters.total_min || query.filters.total_max),
  ].filter(Boolean).length;

  const mobileActiveFilterCount = [
    query.filters.supplier_account_id,
    query.filters.status && query.filters.status !== "ALL",
    query.filters.posting_date_from || query.filters.posting_date_to,
    query.filters.physical_arrival_from || query.filters.physical_arrival_to,
    mayViewCommercialCost &&
      (query.filters.total_min || query.filters.total_max),
  ].filter(Boolean).length;

  const clearAdvancedFilters = () => {
    query.setFilters({
      posting_date_from: undefined,
      posting_date_to: undefined,
      physical_arrival_from: undefined,
      physical_arrival_to: undefined,
      total_min: undefined,
      total_max: undefined,
    });
  };

  const clearAllFilters = () => {
    query.setFilters({
      search: undefined,
      supplier_account_id: undefined,
      status: undefined,
      posting_date_from: undefined,
      posting_date_to: undefined,
      physical_arrival_from: undefined,
      physical_arrival_to: undefined,
      total_min: undefined,
      total_max: undefined,
    });
  };

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
        onRemove: () => query.setFilter("supplier_account_id", undefined),
      });
    }
    if (query.filters.status && query.filters.status !== "ALL") {
      chips.push({
        id: "status",
        icon: <Tag className="h-3.5 w-3.5" />,
        label: `Status: ${query.filters.status}`,
        onRemove: () => query.setFilter("status", undefined),
      });
    }
    if (query.filters.posting_date_from || query.filters.posting_date_to) {
      chips.push({
        id: "posting_date",
        icon: <CalendarDays className="h-3.5 w-3.5" />,
        label: `Posting: ${query.filters.posting_date_from ?? "…"} – ${query.filters.posting_date_to ?? "…"}`,
        onRemove: () =>
          query.setFilters({
            posting_date_from: undefined,
            posting_date_to: undefined,
          }),
      });
    }
    if (
      query.filters.physical_arrival_from ||
      query.filters.physical_arrival_to
    ) {
      chips.push({
        id: "arrival_date",
        icon: <CalendarDays className="h-3.5 w-3.5" />,
        label: `Arrival: ${query.filters.physical_arrival_from ?? "…"} – ${query.filters.physical_arrival_to ?? "…"}`,
        onRemove: () =>
          query.setFilters({
            physical_arrival_from: undefined,
            physical_arrival_to: undefined,
          }),
      });
    }
    if (
      mayViewCommercialCost &&
      (query.filters.total_min || query.filters.total_max)
    ) {
      chips.push({
        id: "total",
        icon: <CircleDollarSign className="h-3.5 w-3.5" />,
        label: `Total: ${query.filters.total_min ?? "0"} – ${query.filters.total_max ?? "…"}`,
        onRemove: () =>
          query.setFilters({
            total_min: undefined,
            total_max: undefined,
          }),
      });
    }
    return chips;
  }, [query.filters, supplierLabels, mayViewCommercialCost, query]);

  const activeFilterLines = describeFilters(
    query.filters,
    supplierLabels,
    mayViewCommercialCost,
  );
  const { contentRef: printRef, print } = useQuickPrint({
    documentTitle: "Goods Receipt List",
  });
  const { contentRef: selectedPrintRef, print: printSelected } = useQuickPrint({
    documentTitle: "Goods Receipt List (Selected)",
  });

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Goods Receipts"
        description="Record physical arrivals, post stock and resolve inspection holds."
        primaryAction={
          mayCreate
            ? {
                label: hasUnfinishedGoodsReceipt()
                  ? "Continue new receipt"
                  : "New receipt",
                onClick: () =>
                  navigate({ pathname: "/goods-receipts/new", search }),
              }
            : undefined
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <div className="relative flex-1 md:w-80 md:flex-none">
          <Search
            aria-hidden="true"
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
          />
          <Input
            className="h-10 w-full pl-10"
            placeholder="Search receipt number or supplier…"
            {...searchBox.searchProps}
          />
        </div>

        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          <GoodsReceiptFilterControls
            suppliersLookup={lookupState(suppliersQuery, "suppliers")}
            suppliers={suppliers}
            supplierLabels={supplierLabels}
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
                "ui-btn-filters-active",
            )}
            onClick={() => setAdvancedOpen((open) => !open)}
          >
            <SlidersHorizontal className="mr-1.5 h-4 w-4" />
            Filters
            {advancedFilterCount > 0 && (
              <Badge
                className={cn(
                  "absolute -right-2 -top-2 h-5 min-w-5 justify-center rounded-full px-1 text-[11px]",
                  (advancedOpen || advancedFilterCount > 0) &&
                    "ui-filter-badge",
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
          <SlidersHorizontal className="mr-1 h-4 w-4" />
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
          <div className="rounded-lg border bg-card p-4 text-card-foreground shadow-xs ui-filter-panel">
            <div className="mb-4 flex items-center justify-between">
              <span className="flex items-center gap-2 text-sm font-semibold">
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
              <AdvancedReceiptFilterFields
                query={query}
                mayViewCommercialCost={mayViewCommercialCost}
              />
            </div>
          </div>
        </div>
      </div>

      <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
        <SheetContent side="bottom" className="md:hidden">
          <SheetHeader>
            <SheetTitle>Filters</SheetTitle>
          </SheetHeader>
          <div className="flex flex-col gap-4 py-4">
            <GoodsReceiptFilterControls
              suppliersLookup={lookupState(suppliersQuery, "suppliers")}
              suppliers={suppliers}
              supplierLabels={supplierLabels}
              query={query}
              full
            />
            <div className="border-t pt-3 space-y-4">
              <AdvancedReceiptFilterFields
                query={query}
                mayViewCommercialCost={mayViewCommercialCost}
                full
              />
            </div>
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
          <span className="hidden text-sm font-medium md:inline">
            Active filters
          </span>
          {activeChips.map((chip) => (
            <span
              key={chip.id}
              className="inline-flex max-w-full items-center rounded-full border bg-primary/5 px-3 py-1.5 text-xs text-primary !gap-2 [&>svg]:shrink-0"
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
            className="text-xs font-medium text-primary hover:underline ml-1"
            onClick={clearAllFilters}
          >
            Clear all
          </button>
        </div>
      )}

      {selectedReceipts.length > 0 && (
        <div className="ui-bulk-bar fixed bottom-4 left-1/2 z-30 flex -translate-x-1/2 items-center gap-3 rounded-lg border bg-card px-4 py-2 text-card-foreground shadow-lg">
          <span className="text-sm font-medium">
            {selectedReceipts.length} selected goods receipt
            {selectedReceipts.length === 1 ? "" : "s"}
          </span>
          <Button
            size="sm"
            variant="outline"
            className="h-8 gap-1.5"
            onClick={() => printSelected()}
          >
            Print selected
          </Button>
          <Button
            size="sm"
            variant="ghost"
            className="h-8"
            onClick={() => setRowSelection({})}
          >
            Clear selection
          </Button>
        </div>
      )}

      <DataTable
        columns={tanStackColumns}
        data={receipts}
        rowCount={meta?.total ?? 0}
        pagination={pagination}
        onPaginationChange={(updater) => {
          const next =
            typeof updater === "function" ? updater(pagination) : updater;
          next.pageSize !== pagination.pageSize
            ? query.setPerPage(next.pageSize)
            : query.setPage(next.pageIndex + 1);
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
        onRowClick={(receipt: GoodsReceiptResource) =>
          navigate({ pathname: `/goods-receipts/${receipt.id}`, search })
        }
        isLoading={receiptsQuery.isLoading}
        isError={receiptsQuery.isError}
        error={receiptsQuery.error}
        onRetry={() => void receiptsQuery.refetch()}
        errorMessage="Couldn't load goods receipts."
        emptyMessage="No goods receipts match these filters."
        view={view}
        onViewChange={setView}
        cardColumns={columns}
        rowActions={(receipt: GoodsReceiptResource) => (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                aria-label={`Actions for receipt ${receipt.number ?? `#${receipt.id}`}`}
                onClick={(e) => e.stopPropagation()}
              >
                <EllipsisVertical className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem
                onSelect={() =>
                  navigate({
                    pathname: `/goods-receipts/${receipt.id}`,
                    search,
                  })
                }
              >
                View receipt
              </DropdownMenuItem>
              {receipt.status === "DRAFT" && mayCreate && (
                <DropdownMenuItem
                  onSelect={() =>
                    navigate({
                      pathname: `/goods-receipts/${receipt.id}/edit`,
                      search,
                    })
                  }
                >
                  <Pencil className="mr-2 h-3.5 w-3.5" />
                  Edit draft…
                </DropdownMenuItem>
              )}
              {receipt.number && (
                <>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem
                    onSelect={() =>
                      void navigator.clipboard?.writeText(receipt.number ?? "")
                    }
                  >
                    Copy receipt number
                  </DropdownMenuItem>
                </>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        )}
      />

      {/* Print selected */}
      <div className="hidden">
        <div ref={selectedPrintRef}>
          <PrintLayout
            title="GOODS RECEIPT LIST (SELECTED)"
            printedAt={new Date()}
            recordCount={selectedReceipts.length}
            meta={[
              {
                label: "Selection",
                value: `${selectedReceipts.length} selected goods receipts`,
              },
            ]}
          >
            <PrintableTable
              columns={columns}
              rows={selectedReceipts}
              getRowKey={(receipt) => receipt.id}
            />
          </PrintLayout>
        </div>
      </div>

      {/* Quick Print */}
      <div className="hidden">
        <div ref={printRef}>
          <PrintLayout
            title="GOODS RECEIPT LIST"
            printedAt={new Date()}
            recordCount={receipts.length}
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
              rows={receipts}
              getRowKey={(receipt) => receipt.id}
            />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}

function describeFilters(
  filters: Partial<ReceiptFilters>,
  supplierLabels: ReadonlyMap<number, string>,
  mayViewCommercialCost: boolean,
): string[] {
  const lines: string[] = [];
  if (filters.supplier_account_id) {
    lines.push(
      `Supplier = ${supplierLabels.get(Number(filters.supplier_account_id)) ?? filters.supplier_account_id}`,
    );
  }
  if (filters.status && filters.status !== "ALL") {
    lines.push(`Status = ${filters.status}`);
  }
  if (filters.posting_date_from || filters.posting_date_to) {
    lines.push(
      `Posting = ${filters.posting_date_from ?? "…"} – ${filters.posting_date_to ?? "…"}`,
    );
  }
  if (filters.physical_arrival_from || filters.physical_arrival_to) {
    lines.push(
      `Arrival = ${filters.physical_arrival_from ?? "…"} – ${filters.physical_arrival_to ?? "…"}`,
    );
  }
  if (mayViewCommercialCost && (filters.total_min || filters.total_max)) {
    lines.push(
      `Total = ${filters.total_min ?? "0"} – ${filters.total_max ?? "…"}`,
    );
  }
  if (filters.search) lines.push(`Search = "${filters.search}"`);

  return lines;
}

interface GoodsReceiptFilterControlsProps {
  suppliersLookup: LookupState;
  suppliers: { id: number; code: string; party?: { name: string } | null }[];
  supplierLabels: ReadonlyMap<number, string>;
  query: ReturnType<typeof useListQueryState<ReceiptFilters>>;
  full?: boolean;
}

/** Supplier & Status selects — shared by the always-visible desktop bar and the mobile Filters sheet. */
function GoodsReceiptFilterControls({
  suppliersLookup,
  suppliers,
  supplierLabels,
  query,
  full,
}: GoodsReceiptFilterControlsProps) {
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
          query.setFilter(
            "supplier_account_id",
            value === "all" ? undefined : value,
          )
        }
        options={supplierOptions}
        placeholder="Supplier"
        aria-label="Supplier"
        searchPlaceholder="Search suppliers…"
        emptyText="No suppliers found."
        className={full ? "h-9 w-full" : "h-10 w-56"}
        icon={
          full ? undefined : (
            <Building2 className="h-[18px] w-[18px] text-muted-foreground" />
          )
        }
      />
      <Select
        value={query.filters.status ?? "ALL"}
        onValueChange={(value) =>
          query.setFilter("status", value === "ALL" ? undefined : value)
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
                className="h-[18px] w-[18px] shrink-0 text-muted-foreground"
              />
            )}
            <SelectValue placeholder="Status" />
          </div>
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="ALL">All statuses</SelectItem>
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

interface AdvancedReceiptFilterFieldsProps {
  query: ReturnType<typeof useListQueryState<ReceiptFilters>>;
  mayViewCommercialCost: boolean;
  full?: boolean;
}

function AdvancedReceiptFilterFields({
  query,
  mayViewCommercialCost,
  full,
}: AdvancedReceiptFilterFieldsProps) {
  const fieldH = full ? "h-9" : "h-10";

  return (
    <>
      <div className="space-y-1.5">
        <Label className="text-sm font-medium">Posting date range</Label>
        <div className="flex items-center gap-1.5">
          <DateField
            aria-label="Posting date from"
            value={query.filters.posting_date_from ?? ""}
            onChange={(val) =>
              query.setFilter("posting_date_from", val || undefined)
            }
            className={cn(fieldH, "w-full")}
            pickerPosition={full ? "end" : "start"}
          />
          <span className="text-xs text-muted-foreground">–</span>
          <DateField
            aria-label="Posting date to"
            value={query.filters.posting_date_to ?? ""}
            onChange={(val) =>
              query.setFilter("posting_date_to", val || undefined)
            }
            className={cn(fieldH, "w-full")}
            pickerPosition={full ? "end" : "start"}
          />
        </div>
      </div>

      <div className="space-y-1.5">
        <Label className="text-sm font-medium">Arrival date range</Label>
        <div className="flex items-center gap-1.5">
          <DateField
            aria-label="Arrival date from"
            value={query.filters.physical_arrival_from ?? ""}
            onChange={(val) =>
              query.setFilter("physical_arrival_from", val || undefined)
            }
            className={cn(fieldH, "w-full")}
            pickerPosition={full ? "end" : "start"}
          />
          <span className="text-xs text-muted-foreground">–</span>
          <DateField
            aria-label="Arrival date to"
            value={query.filters.physical_arrival_to ?? ""}
            onChange={(val) =>
              query.setFilter("physical_arrival_to", val || undefined)
            }
            className={cn(fieldH, "w-full")}
            pickerPosition={full ? "end" : "start"}
          />
        </div>
      </div>

      {mayViewCommercialCost && (
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
      )}
    </>
  );
}
