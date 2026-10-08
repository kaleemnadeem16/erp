import type { GoodsReceiptResource } from "@erp/core-api";

import { cn } from "../../lib/utils";
import { Badge } from "../../components/ui/badge";
import type { DataColumn } from "../../components/data-table/types";
import { formatDateTime } from "../../lib/format-date";

export function GoodsReceiptStatusBadge({ status }: { status: string }) {
  const isPosted = status === "POSTED";
  const isReversed = status === "REVERSED";

  return (
    <Badge
      variant={isReversed ? "destructive" : isPosted ? "default" : "secondary"}
      className={cn(
        "ui-status-badge text-[11px] font-semibold uppercase tracking-wider",
        isPosted &&
          "ui-status-success border-transparent bg-emerald-600/15 text-emerald-700 hover:bg-emerald-600/25 dark:bg-emerald-400/15 dark:text-emerald-300",
        isReversed &&
          "ui-status-danger border-transparent bg-destructive/15 text-destructive hover:bg-destructive/25",
        status === "DRAFT" && "ui-status-muted text-muted-foreground",
      )}
    >
      {status}
    </Badge>
  );
}

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
      key: "status",
      header: "Status",
      value: (receipt) => receipt.status,
      cell: (receipt) => <GoodsReceiptStatusBadge status={receipt.status} />,
      sortable: true,
      filterable: true,
    },
    {
      key: "lines_count",
      header: "Lines",
      value: (receipt) =>
        `${receipt.lines_count ?? receipt.lines?.length ?? 0}`,
      align: "right",
    },
    {
      key: "posting_date",
      header: "Posting date",
      value: (receipt) => receipt.posting_date,
      sortable: true,
    },
    {
      key: "physical_arrival_at",
      header: "Arrival date",
      value: (receipt) => formatDateTime(receipt.physical_arrival_at),
      sortable: true,
    },
    {
      key: "recorded_at",
      header: "Recorded",
      value: (receipt) => formatDateTime(receipt.recorded_at),
      sortable: true,
      screenVisible: false,
    },
    {
      key: "posted_at",
      header: "Posted",
      value: (receipt) =>
        receipt.posted_at ? formatDateTime(receipt.posted_at) : "—",
      sortable: true,
      screenVisible: false,
    },
    {
      key: "created_at",
      header: "Created",
      value: (receipt) =>
        receipt.created_at
          ? formatDateTime(receipt.created_at)
          : `#${receipt.id}`,
      sortable: true,
      screenVisible: false,
    },
  ];
}
