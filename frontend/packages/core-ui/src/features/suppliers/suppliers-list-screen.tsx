import { useEffect, useMemo, useState } from "react";
import { useLocation, useNavigate } from "react-router";
import { useQueryClient } from "@tanstack/react-query";
import type {
  PaginationState,
  RowSelectionState,
  SortingState,
} from "@tanstack/react-table";
import {
  useChartOfAccountsIndex,
  useCurrenciesIndex,
  usePartiesIndex,
  useSupplierAccountsIndex,
  useSupplierAccountsUpdate,
  type SupplierAccountResource,
  type SupplierAccountsIndexSort,
} from "@erp/core-api";
import {
  Archive,
  Building2,
  ChevronUp,
  EllipsisVertical,
  Package,
  Pencil,
  RefreshCw,
  Search,
  ShoppingBag,
  SlidersHorizontal,
  X,
} from "lucide-react";

import { cn } from "../../lib/utils";
import { ScreenTemplate } from "../../components/page/screen-template";
import { PageHeader } from "../../components/page/page-header";
import { Input } from "../../components/ui/input";
import { Button } from "../../components/ui/button";
import { Badge } from "../../components/ui/badge";
import { Combobox } from "../../components/ui/combobox";
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
  tableSortingToApiSort,
  useListQueryState,
} from "../../lib/list-query-state";
import { useColumnVisibilityPreference } from "../../lib/use-column-visibility-preference";
import { useDebouncedSearch } from "../../lib/use-debounced-search";
import { useListViewPreference } from "../../lib/use-view-preference";
import { usePermission } from "../../hooks/use-permission";
import { lookupState } from "../../lib/api-errors";
import { getSupplierColumns } from "./suppliers-columns";

interface SupplierFilters {
  search: string;
  status: string;
  party_id: string;
  default_currency_id: string;
  payable_account_id: string;
  [key: string]: string;
}

export function SuppliersListScreen() {
  const navigate = useNavigate();
  const { search } = useLocation();
  const queryClient = useQueryClient();

  const mayManage = usePermission("parties.supplier-account.manage");
  const maySetTerms = usePermission("parties.payment-terms.manage");
  const mayMap = usePermission("accounting.account-mapping.manage");

  const query = useListQueryState<SupplierFilters>({
    defaultSort: "code",
    defaultPerPage: 25,
  });

  const searchBox = useDebouncedSearch(query.filters.search, (value) =>
    query.setFilter("search", value)
  );

  const [filtersOpen, setFiltersOpen] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(() =>
    Boolean(
      query.filters.party_id ||
        query.filters.default_currency_id ||
        query.filters.payable_account_id
    )
  );
  const { view, setView } = useListViewPreference("suppliers-list");

  const currenciesQuery = useCurrenciesIndex({ per_page: 100, sort: "code" });
  const currencies =
    currenciesQuery.data?.status === 200 ? currenciesQuery.data.data.data : [];
  const currencyLabels = useMemo(
    () => new Map(currencies.map((c) => [c.id, `${c.code} — ${c.name}`])),
    [currencies]
  );

  const partiesQuery = usePartiesIndex({
    per_page: 100,
    sort: "name",
  });
  const parties =
    partiesQuery.data?.status === 200 ? partiesQuery.data.data.data : [];
  const partyLabels = useMemo(
    () => new Map(parties.map((p) => [p.id, `${p.code} — ${p.name}`])),
    [parties]
  );

  const accountsQuery = useChartOfAccountsIndex(
    { per_page: 100, sort: "code" },
    { query: { enabled: mayMap } }
  );
  const accounts =
    accountsQuery.data?.status === 200 ? accountsQuery.data.data.data : [];
  const accountLabels = useMemo(
    () => new Map(accounts.map((a) => [a.id, `${a.code} — ${a.name}`])),
    [accounts]
  );

  const columns = useMemo(
    () => getSupplierColumns(currencyLabels, accountLabels),
    [currencyLabels, accountLabels]
  );

  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    "suppliers-list",
    Object.fromEntries(
      columns.map((col) => [col.key, isColumnVisibleByDefault(col)])
    )
  );

  const suppliersQuery = useSupplierAccountsIndex({
    include: "party",
    "filter[search]": query.filters.search || undefined,
    "filter[status]":
      (query.filters.status as "ACTIVE" | "ARCHIVED" | undefined) || undefined,
    "filter[party_id]": query.filters.party_id
      ? Number(query.filters.party_id)
      : undefined,
    "filter[default_currency_id]": query.filters.default_currency_id
      ? Number(query.filters.default_currency_id)
      : undefined,
    "filter[payable_account_id]":
      mayMap && query.filters.payable_account_id
        ? Number(query.filters.payable_account_id)
        : undefined,
    sort: query.sort as SupplierAccountsIndexSort | undefined,
    page: query.page,
    per_page: query.perPage,
  });

  const suppliers =
    suppliersQuery.data?.status === 200 ? suppliersQuery.data.data.data : [];
  const meta =
    suppliersQuery.data?.status === 200 ? suppliersQuery.data.data.meta : undefined;

  const updateSupplier = useSupplierAccountsUpdate();

  const tanStackColumns = useMemo(
    () => [
      selectionColumn<SupplierAccountResource>(),
      ...toTanStackColumns(columns),
    ],
    [columns]
  );

  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  useEffect(() => setRowSelection({}), [search]);

  const selectedSuppliers = useMemo(
    () => suppliers.filter((supplier) => rowSelection[String(supplier.id)]),
    [suppliers, rowSelection]
  );

  const sorting: SortingState = apiSortToTableSorting(query.sort, columns);
  const pagination: PaginationState = {
    pageIndex: query.page - 1,
    pageSize: query.perPage,
  };

  const { contentRef: printRef, print } = useQuickPrint({
    documentTitle: "Supplier Directory",
  });

  const { contentRef: selectedPrintRef, print: printSelected } = useQuickPrint({
    documentTitle: "Supplier Directory (Selected)",
  });

  const activeAdvancedFilterCount = [
    query.filters.party_id,
    query.filters.default_currency_id,
    mayMap && query.filters.payable_account_id,
  ].filter(Boolean).length;

  const mobileActiveFilterCount = [
    query.filters.status,
    query.filters.party_id,
    query.filters.default_currency_id,
    mayMap && query.filters.payable_account_id,
  ].filter(Boolean).length;

  const activeChips = useMemo(() => {
    const chips: Array<{ id: string; label: string; onRemove: () => void }> = [];
    if (query.filters.search) {
      chips.push({
        id: "search",
        label: `Search: "${query.filters.search}"`,
        onRemove: () => query.setFilter("search", undefined),
      });
    }
    if (query.filters.status) {
      chips.push({
        id: "status",
        label: `Status: ${query.filters.status === "ACTIVE" ? "Active" : "Archived"}`,
        onRemove: () => query.setFilter("status", undefined),
      });
    }
    if (query.filters.party_id) {
      const partyIdNum = Number(query.filters.party_id);
      const partyName = partyLabels.get(partyIdNum) ?? `Partner #${partyIdNum}`;
      chips.push({
        id: "party",
        label: `Partner: ${partyName}`,
        onRemove: () => query.setFilter("party_id", undefined),
      });
    }
    if (query.filters.default_currency_id) {
      const currencyIdNum = Number(query.filters.default_currency_id);
      const currencyName =
        currencyLabels.get(currencyIdNum) ?? `Currency #${currencyIdNum}`;
      chips.push({
        id: "currency",
        label: `Currency: ${currencyName}`,
        onRemove: () => query.setFilter("default_currency_id", undefined),
      });
    }
    if (mayMap && query.filters.payable_account_id) {
      const accountIdNum = Number(query.filters.payable_account_id);
      const accountName =
        accountLabels.get(accountIdNum) ?? `Account #${accountIdNum}`;
      chips.push({
        id: "payable_account",
        label: `Account: ${accountName}`,
        onRemove: () => query.setFilter("payable_account_id", undefined),
      });
    }
    return chips;
  }, [query.filters, partyLabels, currencyLabels, accountLabels, mayMap, query]);

  function clearAllFilters() {
    query.setFilters({
      search: undefined,
      status: undefined,
      party_id: undefined,
      default_currency_id: undefined,
      payable_account_id: undefined,
    });
  }

  async function handleToggleStatus(supplier: SupplierAccountResource) {
    const nextStatus = supplier.status === "ACTIVE" ? "ARCHIVED" : "ACTIVE";
    await updateSupplier.mutateAsync({
      supplierAccount: supplier.id,
      data: { status: nextStatus },
    });
    await queryClient.invalidateQueries();
  }

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <div className="space-y-4 p-4 pb-24 md:p-6">
        <PageHeader
          title="Suppliers"
          description="Register and manage trading partners as suppliers for purchasing."
          primaryAction={
            mayManage && maySetTerms && mayMap
              ? {
                  label: "New supplier",
                  onClick: () => navigate({ pathname: "/suppliers/new", search }),
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
              placeholder="Search supplier code or name…"
              {...searchBox.searchProps}
            />
          </div>

          <div className="hidden items-center gap-2 md:flex md:flex-wrap">
            <Select
              value={query.filters.status || "ALL"}
              onValueChange={(val) =>
                query.setFilter("status", val === "ALL" ? undefined : val)
              }
            >
              <SelectTrigger className="h-10 w-36">
                <SelectValue placeholder="All statuses" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="ALL">All statuses</SelectItem>
                <SelectItem value="ACTIVE">Active</SelectItem>
                <SelectItem value="ARCHIVED">Archived</SelectItem>
              </SelectContent>
            </Select>

            <Button
              variant={
                advancedOpen || activeAdvancedFilterCount > 0
                  ? "secondary"
                  : "outline"
              }
              size="sm"
              className={cn(
                "relative h-10 px-4",
                (advancedOpen || activeAdvancedFilterCount > 0) &&
                  "ui-btn-filters-active"
              )}
              onClick={() => setAdvancedOpen((open) => !open)}
            >
              <SlidersHorizontal className="mr-1.5 h-4 w-4" />
              Filters
              {activeAdvancedFilterCount > 0 && (
                <Badge className="absolute -right-2 -top-2 h-5 min-w-5 justify-center rounded-full px-1 text-[11px]">
                  {activeAdvancedFilterCount}
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
            <SlidersHorizontal className="h-4 w-4 mr-1" />
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
              : "grid-rows-[0fr] opacity-0 -translate-y-2 pointer-events-none"
          )}
          aria-hidden={!advancedOpen}
        >
          <div className="overflow-hidden">
            <div className="rounded-lg border bg-card p-4 text-card-foreground shadow-xs">
              <div className="mb-4 flex items-center justify-between">
                <span className="flex items-center gap-2 text-sm font-semibold">
                  <SlidersHorizontal aria-hidden="true" className="h-4 w-4" />
                  Advanced filters
                </span>
                <div className="flex items-center gap-2">
                  {activeAdvancedFilterCount > 0 && (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-7 text-xs text-muted-foreground hover:text-foreground"
                      onClick={() =>
                        query.setFilters({
                          party_id: undefined,
                          default_currency_id: undefined,
                          payable_account_id: undefined,
                        })
                      }
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
                <div className="space-y-1.5">
                  <label className="text-xs font-medium text-muted-foreground">
                    Trading partner
                  </label>
                  <Combobox
                    {...lookupState(partiesQuery, "trading partners")}
                    value={query.filters.party_id ?? "all"}
                    onValueChange={(val) =>
                      query.setFilter(
                        "party_id",
                        val === "all" ? undefined : val
                      )
                    }
                    searchPlaceholder="Search trading partners…"
                    emptyText="No trading partners found."
                    options={[
                      { value: "all", label: "All trading partners" },
                      ...parties.map((p) => ({
                        value: String(p.id),
                        label: `${p.code} — ${p.name}`,
                      })),
                    ]}
                  />
                </div>

                <div className="space-y-1.5">
                  <label className="text-xs font-medium text-muted-foreground">
                    Default currency
                  </label>
                  <Combobox
                    {...lookupState(currenciesQuery, "currencies")}
                    value={query.filters.default_currency_id ?? "all"}
                    onValueChange={(val) =>
                      query.setFilter(
                        "default_currency_id",
                        val === "all" ? undefined : val
                      )
                    }
                    searchPlaceholder="Search currencies…"
                    emptyText="No currencies found."
                    options={[
                      { value: "all", label: "All currencies" },
                      ...currencies.map((c) => ({
                        value: String(c.id),
                        label: `${c.code} — ${c.name}`,
                      })),
                    ]}
                  />
                </div>

                {mayMap && (
                  <div className="space-y-1.5">
                    <label className="text-xs font-medium text-muted-foreground">
                      Payable control account
                    </label>
                    <Combobox
                      {...lookupState(accountsQuery, "payable accounts")}
                      value={query.filters.payable_account_id ?? "all"}
                      onValueChange={(val) =>
                        query.setFilter(
                          "payable_account_id",
                          val === "all" ? undefined : val
                        )
                      }
                      searchPlaceholder="Search accounts…"
                      emptyText="No accounts found."
                      options={[
                        { value: "all", label: "All accounts" },
                        ...accounts.map((a) => ({
                          value: String(a.id),
                          label: `${a.code} — ${a.name}`,
                        })),
                      ]}
                    />
                  </div>
                )}
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
              <div className="space-y-2">
                <label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Status
                </label>
                <Select
                  value={query.filters.status || "ALL"}
                  onValueChange={(val) =>
                    query.setFilter("status", val === "ALL" ? undefined : val)
                  }
                >
                  <SelectTrigger className="w-full">
                    <SelectValue placeholder="All statuses" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="ALL">All statuses</SelectItem>
                    <SelectItem value="ACTIVE">Active</SelectItem>
                    <SelectItem value="ARCHIVED">Archived</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2 border-t pt-3">
                <label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Trading partner
                </label>
                <Combobox
                  {...lookupState(partiesQuery, "trading partners")}
                  value={query.filters.party_id ?? "all"}
                  onValueChange={(val) =>
                    query.setFilter(
                      "party_id",
                      val === "all" ? undefined : val
                    )
                  }
                  searchPlaceholder="Search trading partners…"
                  emptyText="No trading partners found."
                  options={[
                    { value: "all", label: "All trading partners" },
                    ...parties.map((p) => ({
                      value: String(p.id),
                      label: `${p.code} — ${p.name}`,
                    })),
                  ]}
                />
              </div>

              <div className="space-y-2 border-t pt-3">
                <label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Default currency
                </label>
                <Combobox
                  {...lookupState(currenciesQuery, "currencies")}
                  value={query.filters.default_currency_id ?? "all"}
                  onValueChange={(val) =>
                    query.setFilter(
                      "default_currency_id",
                      val === "all" ? undefined : val
                    )
                  }
                  searchPlaceholder="Search currencies…"
                  emptyText="No currencies found."
                  options={[
                    { value: "all", label: "All currencies" },
                    ...currencies.map((c) => ({
                      value: String(c.id),
                      label: `${c.code} — ${c.name}`,
                    })),
                  ]}
                />
              </div>

              {mayMap && (
                <div className="space-y-2 border-t pt-3">
                  <label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    Payable control account
                  </label>
                  <Combobox
                    {...lookupState(accountsQuery, "payable accounts")}
                    value={query.filters.payable_account_id ?? "all"}
                    onValueChange={(val) =>
                      query.setFilter(
                        "payable_account_id",
                        val === "all" ? undefined : val
                      )
                    }
                    searchPlaceholder="Search accounts…"
                    emptyText="No accounts found."
                    options={[
                      { value: "all", label: "All accounts" },
                      ...accounts.map((a) => ({
                        value: String(a.id),
                        label: `${a.code} — ${a.name}`,
                      })),
                    ]}
                  />
                </div>
              )}
            </div>
            <SheetFooter className="gap-2 sm:space-x-0">
              <Button
                variant="outline"
                onClick={() => {
                  query.setFilters({
                    status: undefined,
                    party_id: undefined,
                    default_currency_id: undefined,
                    payable_account_id: undefined,
                  });
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
          <div className="flex flex-wrap items-center gap-1.5 pt-1">
            {activeChips.map((chip) => (
              <Badge
                key={chip.id}
                variant="secondary"
                className="h-7 gap-1 px-2.5 text-xs font-normal"
              >
                {chip.label}
                <button
                  type="button"
                  onClick={chip.onRemove}
                  className="rounded-full p-0.5 hover:bg-muted"
                  aria-label={`Remove filter: ${chip.label}`}
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

        {selectedSuppliers.length > 0 && (
          <div
            role="region"
            aria-label="Bulk actions"
            className="ui-bulk-bar flex flex-wrap items-center gap-3 rounded-md border bg-muted/40 px-3 py-2 text-sm"
          >
            <span className="font-medium">
              {selectedSuppliers.length} selected
            </span>
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
          data={suppliers}
          rowCount={meta?.total ?? 0}
          pagination={pagination}
          onPaginationChange={(updater) => {
            const next =
              typeof updater === "function" ? updater(pagination) : updater;
            if (next.pageSize !== pagination.pageSize) {
              query.setPerPage(next.pageSize);
            } else {
              query.setPage(next.pageIndex + 1);
            }
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
          onRowClick={(supplier: SupplierAccountResource) =>
            navigate({ pathname: `/suppliers/${supplier.id}`, search })
          }
          isLoading={suppliersQuery.isLoading}
          isError={suppliersQuery.isError}
          error={suppliersQuery.error}
          onRetry={() => void suppliersQuery.refetch()}
          errorMessage="Couldn't load suppliers."
          emptyMessage="No suppliers match these filters."
          view={view}
          onViewChange={setView}
          cardColumns={columns}
          recordNoun="suppliers"
          actionsHeader="Actions"
          numberedPagination
          rowActions={(supplier: SupplierAccountResource) => (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8"
                  aria-label={`Actions for ${supplier.code}`}
                  onClick={(e) => e.stopPropagation()}
                >
                  <EllipsisVertical className="h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuItem
                  onClick={(e) => {
                    e.stopPropagation();
                    navigate({ pathname: `/suppliers/${supplier.id}`, search });
                  }}
                >
                  <Building2 className="mr-2 h-4 w-4 text-muted-foreground" />
                  View details
                </DropdownMenuItem>
                {mayManage && (
                  <DropdownMenuItem
                    onClick={(e) => {
                      e.stopPropagation();
                      navigate({
                        pathname: `/suppliers/${supplier.id}/edit`,
                        search,
                      });
                    }}
                  >
                    <Pencil className="mr-2 h-4 w-4 text-muted-foreground" />
                    Edit supplier
                  </DropdownMenuItem>
                )}
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  onClick={(e) => {
                    e.stopPropagation();
                    navigate(
                      `/purchase-orders?supplier_account_id=${supplier.id}`
                    );
                  }}
                >
                  <ShoppingBag className="mr-2 h-4 w-4 text-muted-foreground" />
                  Purchase orders
                </DropdownMenuItem>
                <DropdownMenuItem
                  onClick={(e) => {
                    e.stopPropagation();
                    navigate(
                      `/item-suppliers?supplier_account_id=${supplier.id}`
                    );
                  }}
                >
                  <Package className="mr-2 h-4 w-4 text-muted-foreground" />
                  Supplied items
                </DropdownMenuItem>
                {mayManage && (
                  <>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      onClick={(e) => {
                        e.stopPropagation();
                        void handleToggleStatus(supplier);
                      }}
                    >
                      {supplier.status === "ACTIVE" ? (
                        <>
                          <Archive className="mr-2 h-4 w-4 text-muted-foreground" />
                          Archive supplier
                        </>
                      ) : (
                        <>
                          <RefreshCw className="mr-2 h-4 w-4 text-muted-foreground" />
                          Activate supplier
                        </>
                      )}
                    </DropdownMenuItem>
                  </>
                )}
              </DropdownMenuContent>
            </DropdownMenu>
          )}
        />

        <div className="hidden">
          <div ref={printRef}>
            <PrintLayout
              title="SUPPLIER DIRECTORY"
              printedAt={new Date()}
              recordCount={suppliers.length}
            >
              <PrintableTable
                columns={columns}
                rows={suppliers}
                getRowKey={(supplier) => supplier.id}
              />
            </PrintLayout>
          </div>
        </div>

        <div className="hidden">
          <div ref={selectedPrintRef}>
            <PrintLayout
              title="SUPPLIER DIRECTORY (SELECTED)"
              printedAt={new Date()}
              recordCount={selectedSuppliers.length}
              meta={[
                {
                  label: "Selection",
                  value: `${selectedSuppliers.length} selected suppliers`,
                },
              ]}
            >
              <PrintableTable
                columns={columns}
                rows={selectedSuppliers}
                getRowKey={(supplier) => supplier.id}
              />
            </PrintLayout>
          </div>
        </div>
      </div>
    </ScreenTemplate>
  );
}
