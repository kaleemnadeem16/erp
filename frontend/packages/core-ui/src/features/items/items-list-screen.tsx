import { useMemo, useState, type ReactNode } from "react";
import {
  Archive,
  ArchiveRestore,
  Boxes,
  ChevronUp,
  Layers,
  Pencil,
  Search,
  SlidersHorizontal,
  Tag,
  X,
} from "lucide-react";
import { Link, useLocation, useNavigate } from "react-router";
import type {
  PaginationState,
  RowSelectionState,
  SortingState,
} from "@tanstack/react-table";
import {
  useCategoriesIndex,
  useItemsIndex,
  useItemsUpdate,
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
import { itemColumns } from "./items-columns";

interface ItemFilters {
  category_id: string;
  type: string;
  status: string;
  search: string;
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

export function ItemsListScreen() {
  const mayManage = usePermission("inventory.item.manage");
  const navigate = useNavigate();
  const { search } = useLocation();
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
      query.filters.category_id ||
        (query.filters.type && query.filters.type !== "all"),
    ),
  );
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  const [statusError, setStatusError] = useState<string | null>(null);
  const { view, setView } = useListViewPreference("items-list");
  const updateStatus = useItemsUpdate();

  const categoriesQuery = useCategoriesIndex({ per_page: 100, sort: "name" });
  const categories =
    categoriesQuery.data?.status === 200 ? categoriesQuery.data.data.data : [];

  const uomsQuery = useUomsIndex({ per_page: 100 });
  const uoms = uomsQuery.data?.status === 200 ? uomsQuery.data.data.data : [];
  const uomLabels = useMemo(
    () => new Map(uoms.map((u) => [u.id, u.code])),
    [uoms],
  );

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
    query.filters.category_id,
    query.filters.type && query.filters.type !== "all",
  ].filter(Boolean).length;

  const clearAdvancedFilters = () => {
    query.setFilters({
      category_id: undefined,
      type: undefined,
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
    if (query.filters.status && query.filters.status !== "all") {
      chips.push({
        id: "status",
        icon: <Tag className="h-3.5 w-3.5" />,
        label: `Status: ${query.filters.status}`,
        onRemove: () => query.setFilter("status", undefined),
      });
    }
    if (query.filters.category_id) {
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
    return chips;
  }, [query.filters, categories, query]);

  const mobileActiveFilterCount = [
    query.filters.search,
    query.filters.status && query.filters.status !== "all",
    query.filters.category_id,
    query.filters.type && query.filters.type !== "all",
  ].filter(Boolean).length;

  const clearAllFilters = () => {
    query.setFilters({
      category_id: undefined,
      type: undefined,
      status: undefined,
      search: undefined,
    });
  };

  const activeFilterLines = describeFilters(query.filters, categories);
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
              <Link to={{ pathname: `/items/${item.id}/edit`, search }}>
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
                onClick: () => navigate({ pathname: "/items/new", search }),
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
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
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
            </div>
          </div>
        </div>
      </div>

      <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
        <SheetContent side="bottom" className="md:hidden">
          <SheetHeader>
            <SheetTitle>Filters</SheetTitle>
          </SheetHeader>
          <div className="flex flex-col gap-3 py-4">
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
        onRowClick={(item: ItemResource) =>
          navigate({ pathname: `/items/${item.id}`, search })
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
): string[] {
  const lines: string[] = [];
  if (filters.category_id) {
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

  return lines;
}
