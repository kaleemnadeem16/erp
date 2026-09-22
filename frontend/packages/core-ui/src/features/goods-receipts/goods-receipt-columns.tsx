import type { GoodsReceiptResource } from "@erp/core-api";

import { Badge } from "../../components/ui/badge";
import type { DataColumn } from "../../components/data-table/types";
import { formatDateTime } from "../../lib/format-date";

const STATUS_VARIANT: Record<string, "secondary" | "default" | "destructive"> =
  {
    DRAFT: "secondary",
    POSTED: "default",
    REVERSED: "destructive",
  };

export function goodsReceiptColumns(
  suppliers: ReadonlyMap<number, string>,
): DataColumn<GoodsReceiptResource>[] {
  return [
    {
      key: "number",
      header: "Receipt",
      value: (receipt) => receipt.number ?? `Draft #${receipt.id}`,
      sortable: true,
      cardRole: "title",
    },
    {
      key: "supplier",
      header: "Supplier",
      value: (receipt) =>
        suppliers.get(receipt.supplier_account_id) ??
        `#${receipt.supplier_account_id}`,
      cardRole: "subtitle",
    },
    {
      key: "posting_date",
      header: "Posting date",
      value: (receipt) => receipt.posting_date,
      sortable: true,
    },
    {
      key: "physical_arrival_at",
      header: "Arrived",
      value: (receipt) => formatDateTime(receipt.physical_arrival_at),
      sortable: true,
    },
    {
      key: "status",
      header: "Status",
      value: (receipt) => receipt.status,
      cell: (receipt) => (
        <Badge variant={STATUS_VARIANT[receipt.status] ?? "secondary"}>
          {receipt.status}
        </Badge>
      ),
      filterable: true,
    },
    {
      key: "created_at",
      header: "Created",
      value: (receipt) => `#${receipt.id}`,
      sortable: true,
      screenVisible: false,
    },
  ];
}
