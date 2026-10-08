import type { GoodsReceiptResource } from "@erp/core-api";

import { StatusBadge, type StatusTone } from "../../components/ui/status-badge";
import type { DataColumn } from "../../components/data-table/types";
import { formatDateTime } from "../../lib/format-date";

export function GoodsReceiptStatusBadge({ status }: { status: string }) {
  const tone: StatusTone =
    status === "POSTED"
      ? "success"
      : status === "REVERSED"
        ? "danger"
        : "draft";

  return (
    <StatusBadge tone={tone} className="ui-status-badge">
      {status}
    </StatusBadge>
  );
}

function formatAmount(
  amount: string | number | null | undefined,
  currencyCode: string | undefined,
): string {
  if (amount === null || amount === undefined) return "—";
  const number = Number(amount);
  const text = Number.isFinite(number)
    ? number.toLocaleString("en-US", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
      })
    : String(amount);
  return currencyCode ? `${currencyCode} ${text}` : text;
}

export function goodsReceiptColumns(
  suppliers: ReadonlyMap<number, string>,
  currencies: ReadonlyMap<number, string> = new Map(),
  mayViewCommercialCost = false,
): DataColumn<GoodsReceiptResource>[] {
  return [
    {
      key: "number",
      header: "Receipt",
      value: (receipt) => receipt.number ?? `Draft #${receipt.id}`,
      cell: (receipt) => (
        <span className="font-semibold text-primary hover:underline cursor-pointer">
          {receipt.number ?? `Draft #${receipt.id}`}
        </span>
      ),
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
    ...(mayViewCommercialCost
      ? [
          {
            key: "total_amount",
            header: "Total value",
            value: (receipt) => receipt.total_amount ?? null,
            cell: (receipt) =>
              receipt.total_amount === undefined ||
              receipt.total_amount === null ? (
                "—"
              ) : (
                <span className="tabular-nums font-medium text-foreground">
                  {formatAmount(
                    receipt.total_amount,
                    currencies.get(Number(receipt.currency_id ?? 0)),
                  )}
                </span>
              ),
            align: "right",
            sortable: true,
          } satisfies DataColumn<GoodsReceiptResource>,
        ]
      : []),
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
