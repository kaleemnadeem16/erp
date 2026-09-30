import type { PurchaseOrderResource } from "@erp/core-api";

import { Badge } from "../../components/ui/badge";
import type { DataColumn } from "../../components/data-table/types";
import { formatDate } from "../../lib/format-date";

const STATUS_VARIANT: Record<
  string,
  "secondary" | "default" | "success" | "destructive"
> = {
  DRAFT: "secondary",
  ISSUED: "default",
  CLOSED: "success",
  CANCELLED: "destructive",
};

export function purchaseOrderColumns(
  suppliers: ReadonlyMap<number, string>,
  currencies: ReadonlyMap<number, string>,
  mayViewCommercialCost: boolean,
): DataColumn<PurchaseOrderResource>[] {
  return [
    {
      key: "number",
      header: "Order",
      value: (order) => order.number ?? `Draft #${order.id}`,
      sortable: true,
      cardRole: "title",
    },
    {
      key: "supplier",
      header: "Supplier",
      value: (order) =>
        suppliers.get(order.supplier_account_id) ??
        `#${order.supplier_account_id}`,
      cardRole: "subtitle",
    },
    {
      key: "order_date",
      header: "Order date",
      value: (order) => order.order_date,
      sortable: true,
    },
    {
      key: "expected_delivery_date",
      header: "Expected",
      value: (order) => order.expected_delivery_date,
      sortable: true,
    },
    {
      key: "item_count",
      header: "Items",
      // Requires `?include=lines` on the list request — absent that, `lines` is
      // unset and this reads as "—" rather than a misleading 0.
      value: (order) => order.lines?.length ?? null,
      align: "right",
    },
    ...(mayViewCommercialCost
      ? [
          {
            key: "total_amount",
            header: "Total value",
            value: (order) => order.total_amount ?? null,
            cell: (order) =>
              order.total_amount === undefined ? (
                "—"
              ) : (
                <span className="tabular-nums">
                  {order.total_amount} {currencies.get(order.currency_id) ?? ""}
                </span>
              ),
            align: "right",
            sortable: true,
          } satisfies DataColumn<PurchaseOrderResource>,
        ]
      : []),
    {
      key: "status",
      header: "Status",
      value: (order) => order.status,
      cell: (order) => (
        <Badge variant={STATUS_VARIANT[order.status] ?? "secondary"}>
          {order.status}
        </Badge>
      ),
      filterable: true,
    },
    {
      key: "issued_at",
      header: "Issued",
      value: (order) => formatDate(order.issued_at) ?? null,
      screenVisible: false,
    },
    {
      key: "created_at",
      header: "Created",
      value: (order) => formatDate(order.created_at) ?? null,
      sortable: true,
      screenVisible: false,
    },
  ];
}
