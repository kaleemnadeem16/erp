import { useMemo, useState } from "react";
import { useItemsById, useSuppliersById } from "../../lib/use-records-by-id";
import { useSupplierLookup } from "../../components/lookups/entity-comboboxes";
import { ItemCombobox } from "../../components/lookups/entity-comboboxes";
import {
  Boxes,
  Building2,
  ChevronUp,
  Clock,
  Layers,
  Search,
  SlidersHorizontal,
  Star,
  Tag,
  X,
} from "lucide-react";
import { useLocation, useNavigate } from "react-router";
import type {
  PaginationState,
  RowSelectionState,
  SortingState,
} from "@tanstack/react-table";
import {
  useItemSuppliersDestroy,
  useItemSuppliersIndex,
  useItemSuppliersUpdate,
  type SupplierAccountResource,
  type ItemResource,
  type ItemSupplierResource,
  type ItemSuppliersIndexSort,
} from "@erp/core-api";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { Combobox } from "../../components/ui/combobox";
import { Input } from "../../components/ui/input";
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
import { ScreenTemplate } from "../../components/page/screen-template";
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
import { lookupState, type LookupState } from "../../lib/api-errors";
import { cn } from "../../lib/utils";
import { getItemSupplierColumns } from "./item-suppliers-columns";
import { hasUnfinishedItemSupplier } from "./item-supplier-form";

interface Filters {
  search: string;
  item_id: string;
  supplier_account_id: string;
  active: string;
  preferred: string;
  supplier_item_code: string;
  lead_time_min: string;
  lead_time_max: string;
  moq_min: string;
  moq_max: string;
  [key: string]: string;
}

export function ItemSuppliersListScreen() {
  const mayManage = usePermission("purchasing.item-supplier.manage");
  const navigate = useNavigate();
  const { search } = useLocation();

  const query = useListQueryState<Filters>({
    defaultSort: "priority",
    defaultPerPage: 25,
  });

  const searchBox = useDebouncedSearch(query.filters.search, (val) =>
    query.setFilter("search", val),
  );

  const [filtersOpen, setFiltersOpen] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(() =>
    Boolean(
      query.filters.supplier_item_code ||
      query.filters.lead_time_min ||
      query.filters.lead_time_max ||
      query.filters.moq_min ||
      query.filters.moq_max,
    ),
  );
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  const { view, setView } = useListViewPreference("item-suppliers-list");

  // Only the filtered item needs a label (the filter chip); it is resolved by id.
  const items = useItemsById<ItemResource>(
    query.filters.item_id ? [Number(query.filters.item_id)] : [],
    [],
  );
  const itemLabels = useMemo(
    () => new Map(items.map((i) => [i.id, `${i.code} — ${i.name}`])),
    [items],
  );

  // Only the filtered supplier needs a label (the filter chip); it is resolved by id and the picker searches the server.
  const suppliers = useSuppliersById<SupplierAccountResource>(
    query.filters.supplier_account_id
      ? [Number(query.filters.supplier_account_id)]
      : [],
    [],
  );
  const supplierLabels = useMemo(
    () =>
      new Map(
        suppliers.map((s) => [
          s.id,
          `${s.code}${s.party?.name ? ` — ${s.party.name}` : ""}`,
        ]),
      ),
    [suppliers],
  );

  const result = useItemSuppliersIndex({
    "filter[item_id]": query.filters.item_id
      ? Number(query.filters.item_id)
      : undefined,
    "filter[supplier_account_id]": query.filters.supplier_account_id
      ? Number(query.filters.supplier_account_id)
      : undefined,
    "filter[active]": query.filters.active
      ? query.filters.active === "true"
      : undefined,
    "filter[preferred]": query.filters.preferred ? true : undefined,
    "filter[search]": query.filters.search || undefined,
    "filter[supplier_item_code]": query.filters.supplier_item_code || undefined,
    "filter[lead_time_min]": query.filters.lead_time_min
      ? Number(query.filters.lead_time_min)
      : undefined,
    "filter[lead_time_max]": query.filters.lead_time_max
      ? Number(query.filters.lead_time_max)
      : undefined,
    "filter[moq_min]": query.filters.moq_min
      ? Number(query.filters.moq_min)
      : undefined,
    "filter[moq_max]": query.filters.moq_max
      ? Number(query.filters.moq_max)
      : undefined,
    include: "item,supplierAccount,purchaseUom",
    sort: query.sort as ItemSuppliersIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });

  const rows = result.data?.status === 200 ? result.data.data.data : [];
  const total = result.data?.status === 200 ? result.data.data.meta.total : 0;

  const destroyMutation = useItemSuppliersDestroy();
  const updateMutation = useItemSuppliersUpdate();

  const handleToggleActive = (row: ItemSupplierResource) => {
    if (row.active) {
      destroyMutation.mutate(
        { itemSupplier: row.id },
        { onSuccess: () => void result.refetch() },
      );
    } else {
      updateMutation.mutate(
        { itemSupplier: row.id, data: { active: true } },
        { onSuccess: () => void result.refetch() },
      );
    }
  };

  const baseColumns = useMemo(
    () =>
      getItemSupplierColumns({
        onRowClick: (row) =>
          navigate({ pathname: `/item-suppliers/${row.id}`, search }),
        onEdit: (row) =>
          navigate({ pathname: `/item-suppliers/${row.id}/edit`, search }),
        onToggleActive: handleToggleActive,
        mayManage,
      }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [mayManage, search],
  );

  const [visibility, setVisibility] = useColumnVisibilityPreference(
    "item-suppliers-list",
    Object.fromEntries(
      baseColumns.map((c) => [c.key, isColumnVisibleByDefault(c)]),
    ),
  );

  const tanStackColumns = useMemo(
    () => [
      selectionColumn<ItemSupplierResource>(),
      ...toTanStackColumns(baseColumns),
    ],
    [baseColumns],
  );
  const sorting: SortingState = apiSortToTableSorting(query.sort, baseColumns);
  const pagination: PaginationState = {
    pageIndex: query.page - 1,
    pageSize: query.perPage,
  };

  const { contentRef, print } = useQuickPrint({
    documentTitle: "Item Supplier List",
  });
  const { contentRef: selectedPrintRef, print: printSelected } = useQuickPrint({
    documentTitle: "Item Supplier List (Selected)",
  });

  const selectedRows: ItemSupplierResource[] = useMemo(
    () =>
      Object.keys(rowSelection)
        .filter((k) => rowSelection[k])
        .map((idx) => rows[Number(idx)])
        .filter((r): r is ItemSupplierResource => Boolean(r)),
    [rowSelection, rows],
  );

  const filter = (name: keyof Filters, value: string) =>
    query.setFilter(name, value === "all" || value === "" ? undefined : value);

  const clearAllFilters = () => {
    query.setFilters({
      search: undefined,
      item_id: undefined,
      supplier_account_id: undefined,
      active: undefined,
      preferred: undefined,
      supplier_item_code: undefined,
      lead_time_min: undefined,
      lead_time_max: undefined,
      moq_min: undefined,
      moq_max: undefined,
    });
  };

  const clearAdvancedFilters = () => {
    query.setFilters({
      supplier_item_code: undefined,
      lead_time_min: undefined,
      lead_time_max: undefined,
      moq_min: undefined,
      moq_max: undefined,
    });
  };

  const advancedFilterCount = [
    query.filters.supplier_item_code,
    query.filters.lead_time_min,
    query.filters.lead_time_max,
    query.filters.moq_min,
    query.filters.moq_max,
  ].filter(Boolean).length;

  const mobileActiveFilterCount = [
    query.filters.search,
    query.filters.item_id,
    query.filters.supplier_account_id,
    query.filters.active,
    query.filters.preferred,
    query.filters.supplier_item_code,
    query.filters.lead_time_min,
    query.filters.lead_time_max,
    query.filters.moq_min,
    query.filters.moq_max,
  ].filter(Boolean).length;

  // Active filter chips
  const activeChips = useMemo(() => {
    const chips: {
      id: string;
      label: string;
      icon: React.ReactNode;
      onRemove: () => void;
    }[] = [];

    if (query.filters.search) {
      chips.push({
        id: "search",
        label: `"${query.filters.search}"`,
        icon: <Search className="h-3 w-3" />,
        onRemove: () => query.setFilter("search", undefined),
      });
    }

    if (query.filters.item_id) {
      const itemText =
        itemLabels.get(Number(query.filters.item_id)) ??
        `Item #${query.filters.item_id}`;
      chips.push({
        id: "item_id",
        label: itemText,
        icon: <Boxes className="h-3 w-3" />,
        onRemove: () => query.setFilter("item_id", undefined),
      });
    }

    if (query.filters.supplier_account_id) {
      const supplierText =
        supplierLabels.get(Number(query.filters.supplier_account_id)) ??
        `Supplier #${query.filters.supplier_account_id}`;
      chips.push({
        id: "supplier_account_id",
        label: supplierText,
        icon: <Building2 className="h-3 w-3" />,
        onRemove: () => query.setFilter("supplier_account_id", undefined),
      });
    }

    if (query.filters.active) {
      chips.push({
        id: "active",
        label:
          query.filters.active === "true" ? "Active only" : "Inactive only",
        icon: <Layers className="h-3 w-3" />,
        onRemove: () => query.setFilter("active", undefined),
      });
    }

    if (query.filters.preferred) {
      chips.push({
        id: "preferred",
        label: "Preferred (#1) only",
        icon: <Star className="h-3 w-3" />,
        onRemove: () => query.setFilter("preferred", undefined),
      });
    }

    if (query.filters.supplier_item_code) {
      chips.push({
        id: "supplier_item_code",
        label: `SKU: ${query.filters.supplier_item_code}`,
        icon: <Tag className="h-3 w-3" />,
        onRemove: () => query.setFilter("supplier_item_code", undefined),
      });
    }

    if (query.filters.lead_time_min || query.filters.lead_time_max) {
      const min = query.filters.lead_time_min;
      const max = query.filters.lead_time_max;
      const rangeText =
        min && max ? `${min}–${max}d` : min ? `≥${min}d` : `≤${max}d`;
      chips.push({
        id: "lead_time",
        label: `Lead time: ${rangeText}`,
        icon: <Clock className="h-3 w-3" />,
        onRemove: () => {
          query.setFilters({
            lead_time_min: undefined,
            lead_time_max: undefined,
          });
        },
      });
    }

    if (query.filters.moq_min || query.filters.moq_max) {
      const min = query.filters.moq_min;
      const max = query.filters.moq_max;
      const rangeText =
        min && max ? `${min}–${max}` : min ? `≥${min}` : `≤${max}`;
      chips.push({
        id: "moq",
        label: `MOQ: ${rangeText}`,
        icon: <SlidersHorizontal className="h-3 w-3" />,
        onRemove: () => {
          query.setFilters({ moq_min: undefined, moq_max: undefined });
        },
      });
    }

    return chips;
  }, [query.filters, itemLabels, supplierLabels, query]);

  return (
    <ScreenTemplate kind="list" className="erp-screen">
      <PageHeader
        title="Item suppliers"
        description="Approved supplier relationships and item-specific sourcing terms."
        primaryAction={
          mayManage
            ? {
                label: hasUnfinishedItemSupplier()
                  ? "Continue new relationship"
                  : "New relationship",
                onClick: () =>
                  navigate({ pathname: "/item-suppliers/new", search }),
              }
            : undefined
        }
      />

      {/* Top Filter Bar */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative flex-1 md:w-80 md:flex-none">
          <Search
            aria-hidden="true"
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
          />
          <Input
            className="h-10 w-full pl-10"
            placeholder="Search item, supplier, or SKU…"
            {...searchBox.searchProps}
          />
        </div>

        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          <ItemSupplierFilterControls query={query} filter={filter} />

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

      {/* Collapsible Advanced Filters Drawer */}
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
                Advanced sourcing filters
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
              <AdvancedItemSupplierFields query={query} filter={filter} />
            </div>
          </div>
        </div>
      </div>

      {/* Mobile Filters Sheet */}
      <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
        <SheetContent side="bottom" className="md:hidden">
          <SheetHeader>
            <SheetTitle>Filters</SheetTitle>
          </SheetHeader>
          <div className="flex flex-col gap-4 py-4 max-h-[70vh] overflow-y-auto">
            <ItemSupplierFilterControls query={query} filter={filter} full />
            <div className="border-t pt-3 space-y-4">
              <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                Advanced filters
              </p>
              <AdvancedItemSupplierFields query={query} filter={filter} full />
            </div>
            <PrintTrigger
              onPrint={() => print()}
              className="w-full justify-center"
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

      {/* Active Filter Chips */}
      {activeChips.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 pt-1">
          <span className="hidden text-sm font-medium md:inline">
            Active filters:
          </span>
          {activeChips.map((chip) => (
            <span
              key={chip.id}
              className="ui-chip-active inline-flex max-w-full items-center rounded-full border bg-primary/5 px-3 py-1.5 text-xs text-primary !gap-2 [&>svg]:shrink-0"
            >
              {chip.icon}
              <span className="min-w-0 break-words">{chip.label}</span>
              <button
                type="button"
                className="shrink-0 hover:opacity-75 cursor-pointer"
                aria-label={`Remove filter ${chip.label}`}
                onClick={chip.onRemove}
              >
                <X className="h-3 w-3" />
              </button>
            </span>
          ))}
          <button
            type="button"
            className="ui-link-action text-xs ml-1 cursor-pointer"
            onClick={clearAllFilters}
          >
            Clear all
          </button>
        </div>
      )}

      {/* Bulk Action Bar */}
      {selectedRows.length > 0 && (
        <div className="ui-bulk-bar fixed bottom-4 left-1/2 z-30 flex -translate-x-1/2 items-center gap-3 rounded-lg border bg-card px-4 py-2 text-card-foreground shadow-lg">
          <span className="text-sm font-medium">
            {selectedRows.length} relationship
            {selectedRows.length === 1 ? "" : "s"} selected
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
            className="h-8 text-xs text-muted-foreground hover:text-foreground"
            onClick={() => setRowSelection({})}
          >
            Clear selection
          </Button>
        </div>
      )}

      {/* Data Table */}
      <DataTable
        columns={tanStackColumns}
        data={rows}
        rowCount={total}
        pagination={pagination}
        onPaginationChange={(u) => {
          const n = typeof u === "function" ? u(pagination) : u;
          n.pageSize !== pagination.pageSize
            ? query.setPerPage(n.pageSize)
            : query.setPage(n.pageIndex + 1);
        }}
        sorting={sorting}
        onSortingChange={(u) =>
          query.setSort(
            tableSortingToApiSort(
              typeof u === "function" ? u(sorting) : u,
              baseColumns,
            ),
          )
        }
        columnVisibility={visibility}
        onColumnVisibilityChange={setVisibility}
        rowSelection={rowSelection}
        onRowSelectionChange={setRowSelection}
        onRowClick={(row: ItemSupplierResource) =>
          navigate({ pathname: `/item-suppliers/${row.id}`, search })
        }
        isLoading={result.isLoading}
        isError={result.isError}
        error={result.error}
        onRetry={() => void result.refetch()}
        errorMessage="Couldn't load item suppliers."
        emptyMessage="No relationships match these filters."
        view={view}
        onViewChange={setView}
        cardColumns={baseColumns}
      />

      {/* Print Templates (Hidden until react-to-print triggers) */}
      <div className="hidden">
        <div ref={contentRef}>
          <PrintLayout
            title="ITEM SUPPLIER LIST"
            printedAt={new Date()}
            recordCount={rows.length}
            meta={
              query.sort
                ? [
                    {
                      label: "Sort",
                      value:
                        describeSort(query.sort, baseColumns) ?? query.sort,
                    },
                  ]
                : []
            }
          >
            <PrintableTable
              columns={baseColumns}
              rows={rows}
              getRowKey={(row) => row.id}
            />
          </PrintLayout>
        </div>

        <div ref={selectedPrintRef}>
          <PrintLayout
            title="ITEM SUPPLIER LIST (SELECTED)"
            printedAt={new Date()}
            recordCount={selectedRows.length}
            meta={[
              {
                label: "Selection",
                value: `${selectedRows.length} relationships`,
              },
            ]}
          >
            <PrintableTable
              columns={baseColumns}
              rows={selectedRows}
              getRowKey={(row) => row.id}
            />
          </PrintLayout>
        </div>
      </div>
    </ScreenTemplate>
  );
}

interface ItemSupplierFilterControlsProps {
  query: ReturnType<typeof useListQueryState<Filters>>;
  filter: (name: keyof Filters, value: string) => void;
  full?: boolean;
}

function ItemSupplierFilterControls({
  query,
  filter,
  full,
}: ItemSupplierFilterControlsProps) {
  const supplierLookup = useSupplierLookup(
    query.filters.supplier_account_id ?? "all",
    { allLabel: "All suppliers" },
  );

  return (
    <>
      <ItemCombobox
        label="Item"
        placeholder="Filter by item"
        allLabel="All items"
        className={full ? "h-10 w-full" : "h-10 w-48"}
        value={query.filters.item_id ?? "all"}
        onValueChange={(v) => filter("item_id", v)}
      />

      <Combobox
        {...supplierLookup}
        className={full ? "h-10 w-full" : "h-10 w-48"}
        aria-label="Supplier"
        value={query.filters.supplier_account_id ?? "all"}
        onValueChange={(v) => filter("supplier_account_id", v)}
        placeholder="Filter by supplier"
        searchPlaceholder="Search suppliers…"
        emptyText="No suppliers found."
      />

      <Select
        value={query.filters.active ?? "all"}
        onValueChange={(v) => filter("active", v)}
      >
        <SelectTrigger className={full ? "h-10 w-full" : "h-10 w-36"}>
          <SelectValue placeholder="All statuses" />
        </SelectTrigger>
        <SelectContent className="ui-select-content">
          <SelectItem value="all">All statuses</SelectItem>
          <SelectItem value="true">Active only</SelectItem>
          <SelectItem value="false">Inactive only</SelectItem>
        </SelectContent>
      </Select>

      <Select
        value={query.filters.preferred ?? "all"}
        onValueChange={(v) => filter("preferred", v)}
      >
        <SelectTrigger className={full ? "h-10 w-full" : "h-10 w-40"}>
          <SelectValue placeholder="All priorities" />
        </SelectTrigger>
        <SelectContent className="ui-select-content">
          <SelectItem value="all">All priorities</SelectItem>
          <SelectItem value="true">Preferred only (#1)</SelectItem>
        </SelectContent>
      </Select>
    </>
  );
}

interface AdvancedFieldsProps {
  query: ReturnType<typeof useListQueryState<Filters>>;
  filter: (name: keyof Filters, value: string) => void;
  full?: boolean;
}

function AdvancedItemSupplierFields({
  query,
  filter,
  full,
}: AdvancedFieldsProps) {
  return (
    <>
      <div className="space-y-1.5">
        <label className="text-xs font-medium text-muted-foreground">
          Supplier item code (SKU)
        </label>
        <Input
          placeholder="e.g. ACM-PART"
          className="h-9"
          value={query.filters.supplier_item_code ?? ""}
          onChange={(e) => filter("supplier_item_code", e.target.value)}
        />
      </div>

      <div className="space-y-1.5">
        <label className="text-xs font-medium text-muted-foreground">
          Lead time (days)
        </label>
        <div className="flex items-center gap-2">
          <Input
            type="number"
            min="0"
            placeholder="Min"
            className="h-9"
            value={query.filters.lead_time_min ?? ""}
            onChange={(e) => filter("lead_time_min", e.target.value)}
          />
          <span className="text-muted-foreground text-xs">to</span>
          <Input
            type="number"
            min="0"
            placeholder="Max"
            className="h-9"
            value={query.filters.lead_time_max ?? ""}
            onChange={(e) => filter("lead_time_max", e.target.value)}
          />
        </div>
      </div>

      <div
        className={cn("space-y-1.5", full ? "" : "sm:col-span-2 lg:col-span-1")}
      >
        <label className="text-xs font-medium text-muted-foreground">
          Minimum order qty (MOQ)
        </label>
        <div className="flex items-center gap-2">
          <Input
            type="number"
            min="0"
            placeholder="Min"
            className="h-9"
            value={query.filters.moq_min ?? ""}
            onChange={(e) => filter("moq_min", e.target.value)}
          />
          <span className="text-muted-foreground text-xs">to</span>
          <Input
            type="number"
            min="0"
            placeholder="Max"
            className="h-9"
            value={query.filters.moq_max ?? ""}
            onChange={(e) => filter("moq_max", e.target.value)}
          />
        </div>
      </div>
    </>
  );
}
