import { useMemo, useState } from "react";
import { useNavigate } from "react-router";
import { Plus } from "lucide-react";
import type {
  PaginationState,
  SortingState,
  VisibilityState,
} from "@tanstack/react-table";
import {
  useGoodsReceiptsIndex,
  useSupplierAccountsIndex,
  type GoodsReceiptResource,
  type GoodsReceiptsIndexSort,
} from "@erp/core-api";

import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "../../components/ui/select";
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
  const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
    () =>
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

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="Goods Receipts"
        description="Record physical arrivals, post stock and resolve inspection holds."
        actions={
          <Button onClick={() => navigate("/goods-receipts/new")}>
            <Plus className="size-4" />
            New receipt
          </Button>
        }
      />
      <div className="flex flex-wrap gap-2">
        <Input
          className="h-9 w-56"
          placeholder="Search receipt number"
          value={query.filters.search ?? ""}
          onChange={(event) =>
            query.setFilter("search", event.target.value || undefined)
          }
        />
        <Select
          value={query.filters.supplier_account_id ?? "all"}
          onValueChange={(value) =>
            query.setFilter(
              "supplier_account_id",
              value === "all" ? undefined : value,
            )
          }
        >
          <SelectTrigger className="h-9 w-56">
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
          <SelectTrigger className="h-9 w-40">
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
      </div>
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
      />
    </div>
  );
}
