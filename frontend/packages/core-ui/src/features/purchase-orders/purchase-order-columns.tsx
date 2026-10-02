import type { PurchaseOrderResource } from "@erp/core-api";

import { Badge } from "../../components/ui/badge";
import type { DataColumn } from "../../components/data-table/types";
import { formatDate } from "../../lib/format-date";

// The shared Badge variant is the base look (dark mode and anywhere the PO palette is not active);
// the po-status-* class restyles it from the erp-blue status tokens inside the light PO screen.
const STATUS_VARIANT: Record<
  string,
  "secondary" | "default" | "success" | "destructive"
> = {
  DRAFT: "secondary",
  ISSUED: "default",
  CLOSED: "success",
  CANCELLED: "destructive",
};

const STATUS_CLASS: Record<string, string> = {
  DRAFT: "po-status-draft",
  WAITING_APPROVAL: "po-status-pending",
  ISSUED: "po-status-active",
  PARTIALLY_RECEIVED: "po-status-progress",
  CLOSED: "po-status-success",
  CANCELLED: "po-status-danger",
  REJECTED: "po-status-danger",
};

/**
 * "PKR 250,000.00": thousands separators and at least two decimals, but never fewer digits than the
 * stored value needs (totals carry up to four), so a figure is grouped for reading and not rounded.
 */
function formatAmount(amount: string | number, currencyCode: string | undefined): string {
  const number = Number(amount);
  const text = Number.isFinite(number)
    ? number.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 4 })
    : String(amount);
  return currencyCode ? `${currencyCode} ${text}` : text;
}

/** One status pill for every Purchase Orders view (list and order screen), so the two cannot drift apart. */
export function PurchaseOrderStatusBadge({ status }: { status: string }) {
  return (
    <Badge
      variant={STATUS_VARIANT[status] ?? "secondary"}
      className={`po-status-badge ${STATUS_CLASS[status] ?? "po-status-draft"}`}
    >
      {status.replace(/_/g, " ")}
    </Badge>
  );
}

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
      cell: (order) => (
        <span className="po-order-link cursor-pointer">
          {order.number ?? `Draft #${order.id}`}
        </span>
      ),
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
                <span className="tabular-nums font-medium text-[var(--po-text-primary)]">
                  {formatAmount(order.total_amount, currencies.get(order.currency_id))}
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
      cell: (order) => <PurchaseOrderStatusBadge status={order.status} />,
      filterable: true,
    },
    {
      key: "issued_at",
      header: "Issued",
      value: (order) => formatDate(order.issued_at) ?? null,
    },
    {
      key: "created_at",
      header: "Created",
      value: (order) => formatDate(order.created_at) ?? null,
      sortable: true,
    },
  ];
}
