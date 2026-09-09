import type { PurchaseOrderResource } from "@erp/core-api";

import { Badge } from "../../components/ui/badge";
import type { DataColumn } from "../../components/data-table/types";

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
): DataColumn<PurchaseOrderResource>[] {
  return [
    {
      key: "number",
      header: "Order",
      value: (order) => order.number ?? `Draft #${order.id}`,
      sortable: true,
    },
    {
      key: "supplier",
      header: "Supplier",
      value: (order) =>
        suppliers.get(order.supplier_account_id) ??
        `#${order.supplier_account_id}`,
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
    },
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
      key: "created_at",
      header: "Created",
      value: (order) => `#${order.id}`,
      sortable: true,
      screenVisible: false,
    },
  ];
}
