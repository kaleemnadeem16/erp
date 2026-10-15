import { useEffect, useMemo, useState, type ReactNode } from "react";
import {
  Archive,
  ArchiveRestore,
  Barcode,
  Boxes,
  Calculator,
  ChevronUp,
  CircleDollarSign,
  Layers,
  Package,
  Pencil,
  Scale,
  Search,
  ShoppingBag,
  SlidersHorizontal,
  Tag,
  Truck,
  X,
} from "lucide-react";
import { Link, useLocation, useNavigate, useSearchParams } from "react-router";
import type {
  PaginationState,
  RowSelectionState,
  SortingState,
} from "@tanstack/react-table";
import {
  useCategoriesIndex,
  useItemsIndex,
  useItemsUpdate,
  useSupplierAccountsShow,
  useUomsIndex,
  type ItemResource,
  type ItemsIndexSort,
} from "@erp/core-api";

import { ScreenTemplate } from "../../components/page/screen-template";
import { PageHeader } from "../../components/page/page-header";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "../../components/ui/select";
import { Combobox } from "../../components/ui/combobox";
import { SupplierCombobox } from "../../components/lookups/entity-comboboxes";
import {
  Sheet,
  SheetContent,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "../../components/ui/sheet";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "../../components/ui/tooltip";
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
import { cn } from "../../lib/utils";
import {
  getApiErrorMessage,
  lookupState,
  type LookupState,
} from "../../lib/api-errors";
import {
  apiSortToTableSorting,
  describeSort,
  tableSortingToApiSort,
  useListQueryState,
  type ListQueryState,
} from "../../lib/list-query-state";
import { useColumnVisibilityPreference } from "../../lib/use-column-visibility-preference";
import { useDebouncedSearch } from "../../lib/use-debounced-search";
import { useListViewPreference } from "../../lib/use-view-preference";
import { usePermission } from "../../hooks/use-permission";
import { BulkRunDialog } from "../../components/bulk-run-dialog";
import { itemColumns } from "./items-columns";

interface ItemFilters {
  category_id: string;
  type: string;
  status: string;
  search: string;
  is_stocked: string;
  is_purchasable: string;
  is_sellable: string;
  valuation_method: string;
  tracking: string;
  supplier_id: string;
  has_supplier: string;
  has_packaging: string;
  stock_uom_id: string;
  package_tracking_mode: string;
  [key: string]: string;
}

const ITEM_TYPES = [
  "RAW",
  "SEMI_FINISHED",
  "FINISHED",
  "SERVICE",
  "LABOUR",
  "PLANT",
] as const;

const VALUATION_METHODS = [
  "AVCO",
  "FIFO",
  "SPECIFIC",
] as const;

const TRACKING_MODES = [
  "NONE",
  "BATCH",
  "SERIAL",
] as const;

const PACKAGE_TRACKING_MODES = [
  "QUANTITY_ONLY",
  "PACKAGE_BALANCE",
  "CONTAINER_TRACKED",
] as const;

export function ItemsListScreen() {
  const mayManage = usePermission("inventory.item.manage");
  // Sourcing (which supplier supplies an item) is Purchasing data: only those who may read item-supplier links get its filters, as the server also enforces.
  const maySeeSourcing = [
    usePermission("purchasing.item-supplier.view"),
    usePermission("purchasing.item-supplier.manage"),
    usePermission("purchasing.purchase-order.view"),
    usePermission("purchasing.purchase-order.create"),
  ].some(Boolean);
  const navigate = useNavigate();
  const location = useLocation();
  const { search } = location;
  const [searchParams, setSearchParams] = useSearchParams();

  useEffect(() => {
    if (location.pathname === "/items" && searchParams.has("tab")) {
      const nextParams = new URLSearchParams(searchParams);
      nextParams.delete("tab");
      setSearchParams(nextParams, { replace: true });
    }
  }, [location.pathname, searchParams, setSearchParams]);

  const listSearch = useMemo(() => {
    const params = new URLSearchParams(search);
    params.delete("tab");
    const s = params.toString();
    return s ? `?${s}` : "";
  }, [search]);

  const query = useListQueryState<ItemFilters>({
    defaultSort: "name",
    defaultPerPage: 25,
  });
  const searchBox = useDebouncedSearch(query.filters.search, (value) =>
    query.setFilter("search", value),
  );

  const [filtersOpen, setFiltersOpen] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(() =>
    Boolean(
      (query.filters.is_stocked && query.filters.is_stocked !== "all") ||
        (query.filters.is_purchasable && query.filters.is_purchasable !== "all") ||
        (query.filters.is_sellable && query.filters.is_sellable !== "all") ||
        (query.filters.valuation_method && query.filters.valuation_method !== "all") ||
        (query.filters.tracking && query.filters.tracking !== "all") ||
        (query.filters.supplier_id && query.filters.supplier_id !== "all") ||
        (query.filters.has_supplier && query.filters.has_supplier !== "all") ||
        (query.filters.has_packaging && query.filters.has_packaging !== "all") ||
        (query.filters.stock_uom_id && query.filters.stock_uom_id !== "all") ||
        (query.filters.package_tracking_mode && query.filters.package_tracking_mode !== "all"),
    ),
  );
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  const [statusError, setStatusError] = useState<string | null>(null);
  const { view, setView } = useListViewPreference("items-list");
  const updateStatus = useItemsUpdate();
  // The ticked items are remembered when the dialog opens: archiving takes them out of an Active-only list, and the result must stay readable.
  const [bulkStatus, setBulkStatus] = useState<{ to: "ACTIVE" | "ARCHIVED"; items: ItemResource[] } | null>(null);

  const categoriesQuery = useCategoriesIndex({ per_page: 100, sort: "name" });
  const categories =
    categoriesQuery.data?.status === 200 ? categoriesQuery.data.data.data : [];

  const uomsQuery = useUomsIndex({ per_page: 100 });
  const uoms = uomsQuery.data?.status === 200 ? uomsQuery.data.data.data : [];
  const uomLabels = useMemo(
    () => new Map(uoms.map((u) => [u.id, u.code])),
    [uoms],
  );

  const supplierQuery = useSupplierAccountsShow(
    query.filters.supplier_id && query.filters.supplier_id !== "all"
      ? Number(query.filters.supplier_id)
      : 0,
    { include: "party" },
    {
      query: {
        enabled: Boolean(
          query.filters.supplier_id &&
            query.filters.supplier_id !== "all" &&
            Number(query.filters.supplier_id) > 0,
        ),
      },
    },
  );
  const supplierData =
    supplierQuery.data?.status === 200 ? supplierQuery.data.data.data : undefined;
  const supplierLabel = supplierData
    ? `${supplierData.code}${supplierData.party?.name ? ` — ${supplierData.party.name}` : ""}`
    : query.filters.supplier_id && query.filters.supplier_id !== "all"
    ? `#${query.filters.supplier_id}`
    : undefined;

  const itemsQuery = useItemsIndex({
    "filter[category_id]": query.filters.category_id
      ? Number(query.filters.category_id)
      : undefined,
    "filter[type]":
      (query.filters.type as (typeof ITEM_TYPES)[number] | undefined) ||
      undefined,
    "filter[status]":
      (query.filters.status as "ACTIVE" | "ARCHIVED" | undefined) || undefined,
    "filter[search]": query.filters.search || undefined,
    "filter[is_stocked]":
      query.filters.is_stocked === "true"
        ? true
        : query.filters.is_stocked === "false"
        ? false
        : undefined,
    "filter[is_purchasable]":
      query.filters.is_purchasable === "true"
        ? true
        : query.filters.is_purchasable === "false"
        ? false
        : undefined,
    "filter[is_sellable]":
      query.filters.is_sellable === "true"
        ? true
        : query.filters.is_sellable === "false"
        ? false
        : undefined,
    "filter[valuation_method]":
      (query.filters.valuation_method as (typeof VALUATION_METHODS)[number] | undefined) ||
      undefined,
    "filter[tracking]":
      (query.filters.tracking as (typeof TRACKING_MODES)[number] | undefined) ||
      undefined,
    "filter[supplier_id]":
      maySeeSourcing && query.filters.supplier_id && query.filters.supplier_id !== "all"
        ? Number(query.filters.supplier_id)
        : undefined,
    "filter[has_supplier]":
      maySeeSourcing && query.filters.has_supplier === "true"
        ? true
        : maySeeSourcing && query.filters.has_supplier === "false"
        ? false
        : undefined,
    "filter[has_packaging]":
      query.filters.has_packaging === "true"
        ? true
        : query.filters.has_packaging === "false"
        ? false
        : undefined,
    "filter[stock_uom_id]":
      query.filters.stock_uom_id && query.filters.stock_uom_id !== "all"
        ? Number(query.filters.stock_uom_id)
        : undefined,
    "filter[package_tracking_mode]":
      (query.filters.package_tracking_mode as (typeof PACKAGE_TRACKING_MODES)[number] | undefined) ||
      undefined,
    sort: query.sort as ItemsIndexSort | undefined,
    include: "category",
    per_page: query.perPage,
    page: query.page,
  });

  const items =
    itemsQuery.data?.status === 200 ? itemsQuery.data.data.data : [];
  const meta =
    itemsQuery.data?.status === 200 ? itemsQuery.data.data.meta : undefined;

  const columns = useMemo(() => itemColumns(uomLabels), [uomLabels]);

  const [columnVisibility, setColumnVisibility] = useColumnVisibilityPreference(
    "items-list",
    Object.fromEntries(
      columns.map((column) => [column.key, isColumnVisibleByDefault(column)]),
    ),
  );

  const tanStackColumns = useMemo(
    () => [selectionColumn<ItemResource>(), ...toTanStackColumns(columns)],
    [columns],
  );
  const sorting: SortingState = apiSortToTableSorting(query.sort, columns);
  const pagination: PaginationState = {
    pageIndex: query.page - 1,
    pageSize: query.perPage,
  };

  const selectedItems = useMemo(
    () => items.filter((item) => rowSelection[String(item.id)]),
    [items, rowSelection],
  );

  const advancedFilterCount = [
    query.filters.is_stocked && query.filters.is_stocked !== "all",
    query.filters.is_purchasable && query.filters.is_purchasable !== "all",
    query.filters.is_sellable && query.filters.is_sellable !== "all",
    query.filters.valuation_method && query.filters.valuation_method !== "all",
    query.filters.tracking && query.filters.tracking !== "all",
    query.filters.supplier_id && query.filters.supplier_id !== "all",
    query.filters.has_supplier && query.filters.has_supplier !== "all",
    query.filters.has_packaging && query.filters.has_packaging !== "all",
    query.filters.stock_uom_id && query.filters.stock_uom_id !== "all",
    query.filters.package_tracking_mode && query.filters.package_tracking_mode !== "all",
  ].filter(Boolean).length;

  const clearAdvancedFilters = () => {
    query.setFilters({
      is_stocked: undefined,
      is_purchasable: undefined,
      is_sellable: undefined,
      valuation_method: undefined,
      tracking: undefined,
      supplier_id: undefined,
      has_supplier: undefined,
      has_packaging: undefined,
      stock_uom_id: undefined,
      package_tracking_mode: undefined,
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
        id: "search",
        icon: <Search className="h-3.5 w-3.5" />,
        label: `Search: "${query.filters.search}"`,
        onRemove: () => query.setFilter("search", undefined),
      });
    }
    if (query.filters.category_id && query.filters.category_id !== "all") {
      const category = categories.find(
        (c) => String(c.id) === query.filters.category_id,
      );
      chips.push({
        id: "category",
        icon: <Layers className="h-3.5 w-3.5" />,
        label: `Category: ${category?.name ?? query.filters.category_id}`,
        onRemove: () => query.setFilter("category_id", undefined),
      });
    }
    if (query.filters.type && query.filters.type !== "all") {
      chips.push({
        id: "type",
        icon: <Boxes className="h-3.5 w-3.5" />,
        label: `Type: ${query.filters.type.replace("_", " ")}`,
        onRemove: () => query.setFilter("type", undefined),
      });
    }
    if (query.filters.status && query.filters.status !== "all") {
      chips.push({
        id: "status",
        icon: <Tag className="h-3.5 w-3.5" />,
        label: `Status: ${query.filters.status}`,
        onRemove: () => query.setFilter("status", undefined),
      });
    }
    if (query.filters.supplier_id && query.filters.supplier_id !== "all") {
      chips.push({
        id: "supplier_id",
        icon: <Truck className="h-3.5 w-3.5" />,
        label: `Supplier: ${supplierLabel ?? query.filters.supplier_id}`,
        onRemove: () => query.setFilter("supplier_id", undefined),
      });
    }
    if (query.filters.has_supplier && query.filters.has_supplier !== "all") {
      chips.push({
        id: "has_supplier",
        icon: <Truck className="h-3.5 w-3.5" />,
        label: `Sourcing: ${query.filters.has_supplier === "true" ? "Sourced only" : "Unsourced only"}`,
        onRemove: () => query.setFilter("has_supplier", undefined),
      });
    }
    if (query.filters.has_packaging && query.filters.has_packaging !== "all") {
      chips.push({
        id: "has_packaging",
        icon: <Package className="h-3.5 w-3.5" />,
        label: `Packaging: ${query.filters.has_packaging === "true" ? "With packaging" : "Without packaging"}`,
        onRemove: () => query.setFilter("has_packaging", undefined),
      });
    }
    if (query.filters.stock_uom_id && query.filters.stock_uom_id !== "all") {
      const uomCode = uomLabels.get(Number(query.filters.stock_uom_id));
      chips.push({
        id: "stock_uom_id",
        icon: <Scale className="h-3.5 w-3.5" />,
        label: `Stock UoM: ${uomCode ?? query.filters.stock_uom_id}`,
        onRemove: () => query.setFilter("stock_uom_id", undefined),
      });
    }
    if (query.filters.package_tracking_mode && query.filters.package_tracking_mode !== "all") {
      chips.push({
        id: "package_tracking_mode",
        icon: <Package className="h-3.5 w-3.5" />,
        label: `Package tracking: ${query.filters.package_tracking_mode.replace("_", " ")}`,
        onRemove: () => query.setFilter("package_tracking_mode", undefined),
      });
    }
    if (query.filters.is_stocked && query.filters.is_stocked !== "all") {
      chips.push({
        id: "is_stocked",
        icon: <Package className="h-3.5 w-3.5" />,
        label: `Stocked: ${query.filters.is_stocked === "true" ? "Yes" : "No"}`,
        onRemove: () => query.setFilter("is_stocked", undefined),
      });
    }
    if (query.filters.is_purchasable && query.filters.is_purchasable !== "all") {
      chips.push({
        id: "is_purchasable",
        icon: <ShoppingBag className="h-3.5 w-3.5" />,
        label: `Purchasable: ${query.filters.is_purchasable === "true" ? "Yes" : "No"}`,
        onRemove: () => query.setFilter("is_purchasable", undefined),
      });
    }
    if (query.filters.is_sellable && query.filters.is_sellable !== "all") {
      chips.push({
        id: "is_sellable",
        icon: <CircleDollarSign className="h-3.5 w-3.5" />,
        label: `Sellable: ${query.filters.is_sellable === "true" ? "Yes" : "No"}`,
        onRemove: () => query.setFilter("is_sellable", undefined),
      });
    }
    if (query.filters.valuation_method && query.filters.valuation_method !== "all") {
      chips.push({
        id: "valuation_method",
        icon: <Calculator className="h-3.5 w-3.5" />,
        label: `Valuation: ${query.filters.valuation_method}`,
        onRemove: () => query.setFilter("valuation_method", undefined),
      });
    }
    if (query.filters.tracking && query.filters.tracking !== "all") {
      chips.push({
        id: "tracking",
        icon: <Barcode className="h-3.5 w-3.5" />,
        label: `Tracking: ${query.filters.tracking}`,
        onRemove: () => query.setFilter("tracking", undefined),
      });
    }
    return chips;
  }, [query.filters, categories, query, supplierLabel, uomLabels]);

  const mobileActiveFilterCount = [
    query.filters.search,
    query.filters.category_id && query.filters.category_id !== "all",
    query.filters.type && query.filters.type !== "all",
    query.filters.status && query.filters.status !== "all",
    query.filters.supplier_id && query.filters.supplier_id !== "all",
    query.filters.has_supplier && query.filters.has_supplier !== "all",
    query.filters.has_packaging && query.filters.has_packaging !== "all",
    query.filters.stock_uom_id && query.filters.stock_uom_id !== "all",
    query.filters.package_tracking_mode && query.filters.package_tracking_mode !== "all",
    query.filters.is_stocked && query.filters.is_stocked !== "all",
    query.filters.is_purchasable && query.filters.is_purchasable !== "all",
    query.filters.is_sellable && query.filters.is_sellable !== "all",
    query.filters.valuation_method && query.filters.valuation_method !== "all",
    query.filters.tracking && query.filters.tracking !== "all",
  ].filter(Boolean).length;

  const clearAllFilters = () => {
    query.setFilters({
      category_id: undefined,
      type: undefined,
      status: undefined,
      search: undefined,
      supplier_id: undefined,
      has_supplier: undefined,
      has_packaging: undefined,
      stock_uom_id: undefined,
      package_tracking_mode: undefined,
      is_stocked: undefined,
      is_purchasable: undefined,
      is_sellable: undefined,
      valuation_method: undefined,
      tracking: undefined,
    });
  };

  const activeFilterLines = describeFilters(
    query.filters,
    categories,
    uomLabels,
    supplierLabel,
  );
  const { contentRef: printRef, print } = useQuickPrint({
    documentTitle: "Item List",
  });
  const { contentRef: selectedPrintRef, print: printSelected } = useQuickPrint({
    documentTitle: "Item List (Selected)",
  });

  function handleToggleStatus(item: ItemResource) {
    setStatusError(null);
    const nextStatus = item.status === "ACTIVE" ? "ARCHIVED" : "ACTIVE";
    updateStatus.mutate(
      { item: item.id, data: { status: nextStatus } },
      {
        onSuccess: () => itemsQuery.refetch(),
        onError: (error) => setStatusError(getApiErrorMessage(error)),
      },
    );
  }

  function renderRowActions(item: ItemResource) {
    if (!mayManage) return null;
    const toggleLabel = item.status === "ACTIVE" ? "Archive" : "Activate";
    return (
      <>
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8"
              aria-label="Edit"
              asChild
            >
              <Link to={{ pathname: `/items/${item.id}/edit`, search: listSearch }}>
                <Pencil className="h-4 w-4" />
              </Link>
            </Button>
          </TooltipTrigger>
          <TooltipContent>Edit</TooltipContent>
        </Tooltip>
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8"
              onClick={() => handleToggleStatus(item)}
              aria-label={toggleLabel}
            >
              {item.status === "ACTIVE" ? (
                <Archive className="h-4 w-4" />
              ) : (
                <ArchiveRestore className="h-4 w-4" />
              )}
            </Button>
          </TooltipTrigger>
          <TooltipContent>{toggleLabel}</TooltipContent>
        </Tooltip>
      </>
    );
  }

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Items"
        description="Everything the company buys, holds, makes, consumes or sells."
        primaryAction={
          mayManage
            ? {
                label: "New item",
                onClick: () => navigate({ pathname: "/items/new", search: listSearch }),
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
            placeholder="Search code or name…"
            {...searchBox.searchProps}
          />
        </div>

        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          <Combobox
            {...lookupState(categoriesQuery, "categories")}
            className="h-10 w-48"
            aria-label="Category"
            value={query.filters.category_id ?? "all"}
            onValueChange={(value) =>
              query.setFilter("category_id", value === "all" ? undefined : value)
            }
            placeholder="All categories"
            searchPlaceholder="Search categories…"
            emptyText="No categories found."
            options={[
              { value: "all", label: "All categories" },
              ...categories.map((category) => ({
                value: String(category.id),
                label: category.name,
              })),
            ]}
          />

          <Select
            value={query.filters.type ?? "all"}
            onValueChange={(value) =>
              query.setFilter("type", value === "all" ? undefined : value)
            }
          >
            <SelectTrigger
              className="h-10 w-40"
              aria-label="Filter by type"
            >
              <SelectValue placeholder="All types" />
            </SelectTrigger>
            <SelectContent className="ui-select-content">
              <SelectItem value="all">All types</SelectItem>
              {ITEM_TYPES.map((type) => (
                <SelectItem key={type} value={type}>
                  {type.replace("_", " ")}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <Select
            value={query.filters.status ?? "all"}
            onValueChange={(value) =>
              query.setFilter("status", value === "all" ? undefined : value)
            }
          >
            <SelectTrigger
              className="h-10 w-36"
              aria-label="Filter by status"
            >
              <SelectValue placeholder="Status" />
            </SelectTrigger>
            <SelectContent className="ui-select-content">
              <SelectItem value="all">All statuses</SelectItem>
              <SelectItem value="ACTIVE">Active</SelectItem>
              <SelectItem value="ARCHIVED">Archived</SelectItem>
            </SelectContent>
          </Select>

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

          <div className="ml-auto flex items-center gap-2">
            <PrintTrigger onPrint={() => print()} className="h-10 px-4" />
          </div>
        </div>

        <Button
          variant="outline"
          size="sm"
          className="h-10 shrink-0 md:hidden"
          onClick={() => setFiltersOpen(true)}
        >
          <SlidersHorizontal className="mr-1.5 h-4 w-4" />
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
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
              {/* Row 1 */}
              {maySeeSourcing && (
<>
<div>
                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                  Supplier
                </label>
                <SupplierCombobox
                  value={query.filters.supplier_id ?? "all"}
                  onValueChange={(value) =>
                    query.setFilter("supplier_id", value === "all" ? undefined : value)
                  }
                  label="Supplier"
                  placeholder="All suppliers"
                  allLabel="All suppliers"
                  className="h-10 w-full"
                />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                  Sourcing status
                </label>
                <Select
                  value={query.filters.has_supplier ?? "all"}
                  onValueChange={(value) =>
                    query.setFilter("has_supplier", value === "all" ? undefined : value)
                  }
                >
                  <SelectTrigger className="h-10 w-full" aria-label="Filter by sourcing status">
                    <SelectValue placeholder="All" />
                  </SelectTrigger>
                  <SelectContent className="ui-select-content">
                    <SelectItem value="all">All sourcing</SelectItem>
                    <SelectItem value="true">Sourced only</SelectItem>
                    <SelectItem value="false">Unsourced only</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              </>
)}
<div>
                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                  Purchasable
                </label>
                <Select
                  value={query.filters.is_purchasable ?? "all"}
                  onValueChange={(value) =>
                    query.setFilter("is_purchasable", value === "all" ? undefined : value)
                  }
                >
                  <SelectTrigger
                    className="h-10 w-full"
                    aria-label="Filter by purchasable"
                  >
                    <SelectValue placeholder="All" />
                  </SelectTrigger>
                  <SelectContent className="ui-select-content">
                    <SelectItem value="all">All</SelectItem>
                    <SelectItem value="true">Purchasable only</SelectItem>
                    <SelectItem value="false">Non-purchasable only</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                  Stocked
                </label>
                <Select
                  value={query.filters.is_stocked ?? "all"}
                  onValueChange={(value) =>
                    query.setFilter("is_stocked", value === "all" ? undefined : value)
                  }
                >
                  <SelectTrigger
                    className="h-10 w-full"
                    aria-label="Filter by stocked"
                  >
                    <SelectValue placeholder="All" />
                  </SelectTrigger>
                  <SelectContent className="ui-select-content">
                    <SelectItem value="all">All</SelectItem>
                    <SelectItem value="true">Stocked only</SelectItem>
                    <SelectItem value="false">Non-stocked only</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                  Sellable
                </label>
                <Select
                  value={query.filters.is_sellable ?? "all"}
                  onValueChange={(value) =>
                    query.setFilter("is_sellable", value === "all" ? undefined : value)
                  }
                >
                  <SelectTrigger
                    className="h-10 w-full"
                    aria-label="Filter by sellable"
                  >
                    <SelectValue placeholder="All" />
                  </SelectTrigger>
                  <SelectContent className="ui-select-content">
                    <SelectItem value="all">All</SelectItem>
                    <SelectItem value="true">Sellable only</SelectItem>
                    <SelectItem value="false">Non-sellable only</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              {/* Row 2 */}
              <div>
                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                  Packaging profiles
                </label>
                <Select
                  value={query.filters.has_packaging ?? "all"}
                  onValueChange={(value) =>
                    query.setFilter("has_packaging", value === "all" ? undefined : value)
                  }
                >
                  <SelectTrigger className="h-10 w-full" aria-label="Filter by packaging profiles">
                    <SelectValue placeholder="All" />
                  </SelectTrigger>
                  <SelectContent className="ui-select-content">
                    <SelectItem value="all">All packaging</SelectItem>
                    <SelectItem value="true">With packaging</SelectItem>
                    <SelectItem value="false">Without packaging</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                  Stock UoM
                </label>
                <Select
                  value={query.filters.stock_uom_id ?? "all"}
                  onValueChange={(value) =>
                    query.setFilter("stock_uom_id", value === "all" ? undefined : value)
                  }
                >
                  <SelectTrigger className="h-10 w-full" aria-label="Filter by stock unit of measure">
                    <SelectValue placeholder="All UoMs" />
                  </SelectTrigger>
                  <SelectContent className="ui-select-content">
                    <SelectItem value="all">All UoMs</SelectItem>
                    {uoms.map((u) => (
                      <SelectItem key={u.id} value={String(u.id)}>
                        {u.code} — {u.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                  Package tracking
                </label>
                <Select
                  value={query.filters.package_tracking_mode ?? "all"}
                  onValueChange={(value) =>
                    query.setFilter("package_tracking_mode", value === "all" ? undefined : value)
                  }
                >
                  <SelectTrigger className="h-10 w-full" aria-label="Filter by package tracking mode">
                    <SelectValue placeholder="All modes" />
                  </SelectTrigger>
                  <SelectContent className="ui-select-content">
                    <SelectItem value="all">All modes</SelectItem>
                    {PACKAGE_TRACKING_MODES.map((mode) => (
                      <SelectItem key={mode} value={mode}>
                        {mode.replace("_", " ")}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                  Valuation method
                </label>
                <Select
                  value={query.filters.valuation_method ?? "all"}
                  onValueChange={(value) =>
                    query.setFilter("valuation_method", value === "all" ? undefined : value)
                  }
                >
                  <SelectTrigger
                    className="h-10 w-full"
                    aria-label="Filter by valuation method"
                  >
                    <SelectValue placeholder="All methods" />
                  </SelectTrigger>
                  <SelectContent className="ui-select-content">
                    <SelectItem value="all">All methods</SelectItem>
                    {VALUATION_METHODS.map((method) => (
                      <SelectItem key={method} value={method}>
                        {method}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                  Tracking
                </label>
                <Select
                  value={query.filters.tracking ?? "all"}
                  onValueChange={(value) =>
                    query.setFilter("tracking", value === "all" ? undefined : value)
                  }
                >
                  <SelectTrigger
                    className="h-10 w-full"
                    aria-label="Filter by tracking"
                  >
                    <SelectValue placeholder="All tracking" />
                  </SelectTrigger>
                  <SelectContent className="ui-select-content">
                    <SelectItem value="all">All tracking</SelectItem>
                    {TRACKING_MODES.map((mode) => (
                      <SelectItem key={mode} value={mode}>
                        {mode}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
        <SheetContent side="bottom" className="md:hidden max-h-[85vh] overflow-y-auto">
          <SheetHeader>
            <SheetTitle>Filters</SheetTitle>
          </SheetHeader>
          <div className="flex flex-col gap-3 py-4">
            <div>
              <label className="mb-1.5 block text-xs font-medium text-muted-foreground">
                Category
              </label>
              <Combobox
                {...lookupState(categoriesQuery, "categories")}
                className="h-10 w-full"
                aria-label="Category"
                value={query.filters.category_id ?? "all"}
                onValueChange={(value) =>
                  query.setFilter("category_id", value === "all" ? undefined : value)
                }
                placeholder="All categories"
                searchPlaceholder="Search categories…"
                emptyText="No categories found."
                options={[
                  { value: "all", label: "All categories" },
                  ...categories.map((category) => ({
                    value: String(category.id),
                    label: category.name,
                  })),
                ]}
              />
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-muted-foreground">
                Type
              </label>
              <Select
                value={query.filters.type ?? "all"}
                onValueChange={(value) =>
                  query.setFilter("type", value === "all" ? undefined : value)
                }
              >
                <SelectTrigger
                  className="h-10 w-full"
                  aria-label="Filter by type"
                >
                  <SelectValue placeholder="All types" />
                </SelectTrigger>
                <SelectContent className="ui-select-content">
                  <SelectItem value="all">All types</SelectItem>
                  {ITEM_TYPES.map((type) => (
                    <SelectItem key={type} value={type}>
                      {type.replace("_", " ")}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-muted-foreground">
                Status
              </label>
              <Select
                value={query.filters.status ?? "all"}
                onValueChange={(value) =>
                  query.setFilter("status", value === "all" ? undefined : value)
                }
              >
                <SelectTrigger
                  className="h-10 w-full"
                  aria-label="Filter by status"
                >
                  <SelectValue placeholder="Status" />
                </SelectTrigger>
                <SelectContent className="ui-select-content">
                  <SelectItem value="all">All statuses</SelectItem>
                  <SelectItem value="ACTIVE">Active</SelectItem>
                  <SelectItem value="ARCHIVED">Archived</SelectItem>
                </SelectContent>
              </Select>
            </div>
            {maySeeSourcing && (
<>
<div>
              <label className="mb-1.5 block text-xs font-medium text-muted-foreground">
                Supplier
              </label>
              <SupplierCombobox
                value={query.filters.supplier_id ?? "all"}
                onValueChange={(value) =>
                  query.setFilter("supplier_id", value === "all" ? undefined : value)
                }
                label="Supplier"
                placeholder="All suppliers"
                allLabel="All suppliers"
                className="h-10 w-full"
              />
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-muted-foreground">
                Sourcing status
              </label>
              <Select
                value={query.filters.has_supplier ?? "all"}
                onValueChange={(value) =>
                  query.setFilter("has_supplier", value === "all" ? undefined : value)
                }
              >
                <SelectTrigger className="h-10 w-full" aria-label="Filter by sourcing status">
                  <SelectValue placeholder="All" />
                </SelectTrigger>
                <SelectContent className="ui-select-content">
                  <SelectItem value="all">All</SelectItem>
                  <SelectItem value="true">Sourced only (has supplier)</SelectItem>
                  <SelectItem value="false">Unsourced only (no supplier)</SelectItem>
                </SelectContent>
              </Select>
            </div>
            </>
)}
<div>
              <label className="mb-1.5 block text-xs font-medium text-muted-foreground">
                Stocked
              </label>
              <Select
                value={query.filters.is_stocked ?? "all"}
                onValueChange={(value) =>
                  query.setFilter("is_stocked", value === "all" ? undefined : value)
                }
              >
                <SelectTrigger
                  className="h-10 w-full"
                  aria-label="Filter by stocked"
                >
                  <SelectValue placeholder="All" />
                </SelectTrigger>
                <SelectContent className="ui-select-content">
                  <SelectItem value="all">All</SelectItem>
                  <SelectItem value="true">Stocked only</SelectItem>
                  <SelectItem value="false">Non-stocked only</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-muted-foreground">
                Purchasable
              </label>
              <Select
                value={query.filters.is_purchasable ?? "all"}
                onValueChange={(value) =>
                  query.setFilter("is_purchasable", value === "all" ? undefined : value)
                }
              >
                <SelectTrigger
                  className="h-10 w-full"
                  aria-label="Filter by purchasable"
                >
                  <SelectValue placeholder="All" />
                </SelectTrigger>
                <SelectContent className="ui-select-content">
                  <SelectItem value="all">All</SelectItem>
                  <SelectItem value="true">Purchasable only</SelectItem>
                  <SelectItem value="false">Non-purchasable only</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-muted-foreground">
                Sellable
              </label>
              <Select
                value={query.filters.is_sellable ?? "all"}
                onValueChange={(value) =>
                  query.setFilter("is_sellable", value === "all" ? undefined : value)
                }
              >
                <SelectTrigger
                  className="h-10 w-full"
                  aria-label="Filter by sellable"
                >
                  <SelectValue placeholder="All" />
                </SelectTrigger>
                <SelectContent className="ui-select-content">
                  <SelectItem value="all">All</SelectItem>
                  <SelectItem value="true">Sellable only</SelectItem>
                  <SelectItem value="false">Non-sellable only</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-muted-foreground">
                Packaging profiles
              </label>
              <Select
                value={query.filters.has_packaging ?? "all"}
                onValueChange={(value) =>
                  query.setFilter("has_packaging", value === "all" ? undefined : value)
                }
              >
                <SelectTrigger className="h-10 w-full" aria-label="Filter by packaging profiles">
                  <SelectValue placeholder="All" />
                </SelectTrigger>
                <SelectContent className="ui-select-content">
                  <SelectItem value="all">All</SelectItem>
                  <SelectItem value="true">With packaging profiles</SelectItem>
                  <SelectItem value="false">Without packaging</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-muted-foreground">
                Stock UoM
              </label>
              <Select
                value={query.filters.stock_uom_id ?? "all"}
                onValueChange={(value) =>
                  query.setFilter("stock_uom_id", value === "all" ? undefined : value)
                }
              >
                <SelectTrigger className="h-10 w-full" aria-label="Filter by stock unit of measure">
                  <SelectValue placeholder="All UoMs" />
                </SelectTrigger>
                <SelectContent className="ui-select-content">
                  <SelectItem value="all">All UoMs</SelectItem>
                  {uoms.map((u) => (
                    <SelectItem key={u.id} value={String(u.id)}>
                      {u.code} — {u.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-muted-foreground">
                Package tracking mode
              </label>
              <Select
                value={query.filters.package_tracking_mode ?? "all"}
                onValueChange={(value) =>
                  query.setFilter("package_tracking_mode", value === "all" ? undefined : value)
                }
              >
                <SelectTrigger className="h-10 w-full" aria-label="Filter by package tracking mode">
                  <SelectValue placeholder="All modes" />
                </SelectTrigger>
                <SelectContent className="ui-select-content">
                  <SelectItem value="all">All modes</SelectItem>
                  {PACKAGE_TRACKING_MODES.map((mode) => (
                    <SelectItem key={mode} value={mode}>
                      {mode.replace("_", " ")}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-muted-foreground">
                Valuation method
              </label>
              <Select
                value={query.filters.valuation_method ?? "all"}
                onValueChange={(value) =>
                  query.setFilter("valuation_method", value === "all" ? undefined : value)
                }
              >
                <SelectTrigger
                  className="h-10 w-full"
                  aria-label="Filter by valuation method"
                >
                  <SelectValue placeholder="All methods" />
                </SelectTrigger>
                <SelectContent className="ui-select-content">
                  <SelectItem value="all">All methods</SelectItem>
                  {VALUATION_METHODS.map((method) => (
                    <SelectItem key={method} value={method}>
                      {method}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-muted-foreground">
                Tracking
              </label>
              <Select
                value={query.filters.tracking ?? "all"}
                onValueChange={(value) =>
                  query.setFilter("tracking", value === "all" ? undefined : value)
                }
              >
                <SelectTrigger
                  className="h-10 w-full"
                  aria-label="Filter by tracking"
                >
                  <SelectValue placeholder="All tracking" />
                </SelectTrigger>
                <SelectContent className="ui-select-content">
                  <SelectItem value="all">All tracking</SelectItem>
                  {TRACKING_MODES.map((mode) => (
                    <SelectItem key={mode} value={mode}>
                      {mode}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <PrintTrigger
              onPrint={() => print()}
              className="w-full justify-center h-10"
            />
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
            <Badge
              key={chip.id}
              variant="secondary"
              className="ui-chip-active h-7 gap-1.5 pl-2.5 pr-1.5 text-xs font-normal"
            >
              {chip.icon}
              <span>{chip.label}</span>
              <button
                type="button"
                className="ml-0.5 rounded-full p-0.5 hover:bg-muted-foreground/20 cursor-pointer"
                aria-label={`Remove filter ${chip.label}`}
                onClick={chip.onRemove}
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

      {bulkStatus && (
        <BulkRunDialog
          open
          key={bulkStatus.to}
          title={bulkStatus.to === "ARCHIVED" ? "Archive these items" : "Activate these items"}
          intro={`${bulkStatus.items.filter((item) => item.status !== bulkStatus.to).length} ${bulkStatus.to === "ARCHIVED" ? "active" : "archived"} item${bulkStatus.items.filter((item) => item.status !== bulkStatus.to).length === 1 ? "" : "s"} will be changed, each by its own request.${bulkStatus.items.some((item) => item.status === bulkStatus.to) ? ` ${bulkStatus.items.filter((item) => item.status === bulkStatus.to).length} already ${bulkStatus.to === "ARCHIVED" ? "archived" : "active"} are left out.` : ""}`}
          rows={bulkStatus.items.filter((item) => item.status !== bulkStatus.to)}
          ready
          labelOf={(item) => item.code}
          onClose={() => setBulkStatus(null)}
          onChanged={async () => {
            await itemsQuery.refetch();
          }}
          run={(item) => updateStatus.mutateAsync({ item: item.id, data: { status: bulkStatus.to } })}
        >
          <p className="text-sm text-muted-foreground">
            {bulkStatus.to === "ARCHIVED" ? "Archived items cannot be chosen on new documents; everything already recorded keeps them." : "Activated items can be chosen on new documents again."}
          </p>
        </BulkRunDialog>
      )}

      {selectedItems.length > 0 && (
        <div className="ui-bulk-bar fixed bottom-4 left-1/2 z-30 flex -translate-x-1/2 items-center gap-3 rounded-lg border bg-card px-4 py-2 text-card-foreground shadow-lg">
          <span className="text-sm font-medium">
            {selectedItems.length} selected item
            {selectedItems.length === 1 ? "" : "s"}
          </span>
          <Button
            size="sm"
            variant="outline"
            className="h-8 gap-1.5"
            onClick={() => printSelected()}
          >
            Print selected
          </Button>
          {mayManage && selectedItems.some((item) => item.status === "ACTIVE") && (
            <Button size="sm" variant="outline" className="h-8" onClick={() => setBulkStatus({ to: "ARCHIVED", items: selectedItems })} data-testid="bulk-archive">
              Archive…
            </Button>
          )}
          {mayManage && selectedItems.some((item) => item.status === "ARCHIVED") && (
            <Button size="sm" variant="outline" className="h-8" onClick={() => setBulkStatus({ to: "ACTIVE", items: selectedItems })} data-testid="bulk-activate">
              Activate…
            </Button>
          )}
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
        getRowId={(item) => String(item.id)}
        onRowClick={(item: ItemResource) =>
          navigate({ pathname: `/items/${item.id}`, search: listSearch })
        }
        isLoading={itemsQuery.isLoading}
        isError={itemsQuery.isError}
        error={itemsQuery.error}
        onRetry={() => void itemsQuery.refetch()}
        errorMessage="Couldn't load items."
        emptyMessage="No items match these filters."
        view={view}
        onViewChange={setView}
        cardColumns={columns}
        rowActions={mayManage ? renderRowActions : undefined}
      />

      {/* Quick Print — a dedicated print-only layout, hidden until react-to-print clones it. */}
      <div className="hidden">
        <div ref={printRef}>
          <PrintLayout
            title="ITEM LIST"
            printedAt={new Date()}
            recordCount={items.length}
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
              rows={items}
              getRowKey={(item) => item.id}
            />
          </PrintLayout>
        </div>
        <div ref={selectedPrintRef}>
          <PrintLayout
            title="ITEM LIST (SELECTED)"
            printedAt={new Date()}
            recordCount={selectedItems.length}
            meta={[
              { label: "Selection", value: `${selectedItems.length} items` },
            ]}
          >
            <PrintableTable
              columns={columns}
              rows={selectedItems}
              getRowKey={(item) => item.id}
            />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}



function describeFilters(
  filters: Partial<ItemFilters>,
  categories: { id: number; name: string }[],
  uomLabels: Map<number, string>,
  supplierLabel?: string,
): string[] {
  const lines: string[] = [];
  if (filters.category_id && filters.category_id !== "all") {
    const category = categories.find(
      (c) => String(c.id) === filters.category_id,
    );
    lines.push(`Category = ${category?.name ?? filters.category_id}`);
  }
  if (filters.type && filters.type !== "all")
    lines.push(`Type = ${filters.type.replace("_", " ")}`);
  if (filters.status && filters.status !== "all")
    lines.push(`Status = ${filters.status}`);
  if (filters.search) lines.push(`Search = "${filters.search}"`);
  if (filters.supplier_id && filters.supplier_id !== "all")
    lines.push(`Supplier = ${supplierLabel ?? `#${filters.supplier_id}`}`);
  if (filters.has_supplier && filters.has_supplier !== "all")
    lines.push(
      `Sourcing = ${filters.has_supplier === "true" ? "Sourced only" : "Unsourced only"}`,
    );
  if (filters.has_packaging && filters.has_packaging !== "all")
    lines.push(
      `Packaging = ${filters.has_packaging === "true" ? "With packaging profiles" : "Without packaging"}`,
    );
  if (filters.stock_uom_id && filters.stock_uom_id !== "all") {
    const uomName = uomLabels.get(Number(filters.stock_uom_id));
    lines.push(`Stock UoM = ${uomName ?? filters.stock_uom_id}`);
  }
  if (
    filters.package_tracking_mode &&
    filters.package_tracking_mode !== "all"
  ) {
    lines.push(
      `Package tracking = ${filters.package_tracking_mode.replace("_", " ")}`,
    );
  }
  if (filters.is_stocked && filters.is_stocked !== "all")
    lines.push(`Stocked = ${filters.is_stocked === "true" ? "Yes" : "No"}`);
  if (filters.is_purchasable && filters.is_purchasable !== "all")
    lines.push(`Purchasable = ${filters.is_purchasable === "true" ? "Yes" : "No"}`);
  if (filters.is_sellable && filters.is_sellable !== "all")
    lines.push(`Sellable = ${filters.is_sellable === "true" ? "Yes" : "No"}`);
  if (filters.valuation_method && filters.valuation_method !== "all")
    lines.push(`Valuation = ${filters.valuation_method}`);
  if (filters.tracking && filters.tracking !== "all")
    lines.push(`Tracking = ${filters.tracking}`);

  return lines;
}
