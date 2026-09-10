import { useMemo, useState } from "react";
import { useNavigate } from "react-router";
import { SlidersHorizontal } from "lucide-react";
import type {
  PaginationState,
  SortingState,
} from "@tanstack/react-table";
import {
  useGoodsReceiptsIndex,
  useSupplierAccountsIndex,
  type GoodsReceiptResource,
  type GoodsReceiptsIndexSort,
} from "@erp/core-api";

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
  apiSortToTableSorting,
  tableSortingToApiSort,
  useListQueryState,
} from "../../lib/list-query-state";
import { useColumnVisibilityPreference } from "../../lib/use-column-visibility-preference";
import { useListViewPreference } from "../../lib/use-view-preference";
import { goodsReceiptColumns } from "./goods-receipt-columns";

interface ReceiptFilters {
  search: string;
  supplier_account_id: string;
  status: string;
  [key: string]: string;
}

const STATUSES = ["DRAFT", "POSTED", "REVERSED"] as const;

export function GoodsReceiptsListScreen() {
  const navigate = useNavigate();
  const query = useListQueryState<ReceiptFilters>({
    defaultSort: "-created_at",
    defaultPerPage: 25,
  });
  const [filtersOpen, setFiltersOpen] = useState(false);
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
  const columns = useMemo(
    () => goodsReceiptColumns(supplierLabels),
    [supplierLabels],
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
      (query.filters.status as (typeof STATUSES)[number] | undefined) ||
      undefined,
    sort: query.sort as GoodsReceiptsIndexSort | undefined,
    per_page: query.perPage,
    page: query.page,
  });
  const receipts =
    receiptsQuery.data?.status === 200 ? receiptsQuery.data.data.data : [];
  const meta =
    receiptsQuery.data?.status === 200
      ? receiptsQuery.data.data.meta
      : undefined;
  const tanStackColumns = useMemo(() => toTanStackColumns(columns), [columns]);
  const sorting: SortingState = apiSortToTableSorting(query.sort, columns);
  const pagination: PaginationState = {
    pageIndex: query.page - 1,
    pageSize: query.perPage,
  };
  const activeFilterCount = [
    query.filters.supplier_account_id,
    query.filters.status,
  ].filter(Boolean).length;

  return (
    // Extra bottom padding below `md` clears the fixed MobileFab — see
    // PageHeader's `primaryAction` note in docs/architecture/user-interface.md.
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader
        title="Goods Receipts"
        description="Record physical arrivals, post stock and resolve inspection holds."
        primaryAction={{ label: "New receipt", onClick: () => navigate("/goods-receipts/new") }}
      />
      <div className="flex flex-wrap items-center gap-2">
        <Input
          className="h-9 flex-1 md:w-56 md:flex-none"
          placeholder="Search receipt number"
          value={query.filters.search ?? ""}
          onChange={(event) =>
            query.setFilter("search", event.target.value || undefined)
          }
        />
        <div className="hidden items-center gap-2 md:flex md:flex-wrap">
          <ReceiptFilterControls
            suppliers={suppliers}
            supplierLabels={supplierLabels}
            query={query}
          />
        </div>
        <Button
          variant="outline"
          size="sm"
          className="h-9 shrink-0 md:hidden"
          onClick={() => setFiltersOpen(true)}
        >
          <SlidersHorizontal className="h-4 w-4" />
          Filters
          {activeFilterCount > 0 && <Badge className="ml-1">{activeFilterCount}</Badge>}
        </Button>
      </div>

      <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
        <SheetContent side="bottom" className="md:hidden">
          <SheetHeader>
            <SheetTitle>Filters</SheetTitle>
          </SheetHeader>
          <div className="flex flex-col gap-3 py-4">
            <ReceiptFilterControls
              suppliers={suppliers}
              supplierLabels={supplierLabels}
              query={query}
              full
            />
          </div>
          <SheetFooter>
            <Button
              variant="ghost"
              onClick={() => query.setFilters({ supplier_account_id: undefined, status: undefined })}
            >
              Clear filters
            </Button>
            <Button onClick={() => setFiltersOpen(false)}>Done</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>

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
        onRowClick={(receipt: GoodsReceiptResource) =>
          navigate(`/goods-receipts/${receipt.id}`)
        }
        isLoading={receiptsQuery.isLoading}
        isError={receiptsQuery.isError}
        errorMessage="Couldn't load goods receipts."
        emptyMessage="No goods receipts match these filters."
        view={view}
        onViewChange={setView}
        cardColumns={columns}
      />
    </div>
  );
}

interface ReceiptFilterControlsProps {
  suppliers: { id: number }[];
  supplierLabels: ReadonlyMap<number, string>;
  query: ReturnType<typeof useListQueryState<ReceiptFilters>>;
  full?: boolean;
}

/** Supplier/Status selects — shared by the always-visible desktop row and the mobile Filters sheet. */
function ReceiptFilterControls({ suppliers, supplierLabels, query, full }: ReceiptFilterControlsProps) {
  return (
    <>
      <Select
        value={query.filters.supplier_account_id ?? "all"}
        onValueChange={(value) =>
          query.setFilter(
            "supplier_account_id",
            value === "all" ? undefined : value,
          )
        }
      >
        <SelectTrigger className={full ? "h-9 w-full" : "h-9 w-56"}>
          <SelectValue placeholder="Supplier" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All suppliers</SelectItem>
          {suppliers.map((supplier) => (
            <SelectItem key={supplier.id} value={String(supplier.id)}>
              {supplierLabels.get(supplier.id)}
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
        <SelectTrigger className={full ? "h-9 w-full" : "h-9 w-40"}>
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
