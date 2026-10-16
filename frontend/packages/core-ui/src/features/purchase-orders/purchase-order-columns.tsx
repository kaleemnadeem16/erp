import type { PurchaseOrderResource } from "@erp/core-api";

import { StatusBadge, type StatusTone } from "../../components/ui/status-badge";
import type { DataColumn } from "../../components/data-table/types";
import { formatDate } from "../../lib/format-date";
import { formatMoney } from "../../lib/decimal";
import { REQUEST_STATUS_LABELS } from "../approvals/approval-labels";

// Domain states map to shared visual roles in both colour modes.
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
 * "PKR 250,000.00": thousands separators and at least the currency's own minor-unit places (two when unknown, none for a yen, three for a dinar), but never
 * fewer digits than the stored value needs (totals carry up to four), so a figure is grouped for reading and not rounded. Works on the decimal string:
 * no float is involved.
 */
function formatAmount(
  amount: string | number,
  currencyCode: string | undefined,
  places = 2,
): string {
  const text = formatMoney(String(amount), { decimal_places: places });
  return currencyCode ? `${currencyCode} ${text}` : text;
}

/** One status pill for every Purchase Orders view (list and order screen), so the two cannot drift apart. */
export function PurchaseOrderStatusBadge({ status }: { status: string }) {
  return (
    <StatusBadge
      tone={
        (STATUS_CLASS[status] ?? "po-status-draft").replace(
          "po-status-",
          "",
        ) as StatusTone
      }
      className={`po-status-badge ${STATUS_CLASS[status] ?? "po-status-draft"}`}
    >
      {status.replace(/_/g, " ")}
    </StatusBadge>
  );
}

/**
 * Where an order stands with approval, in the list's words. Orders that need no approval show a dash rather than "Not
 * required": in a company that uses approvals most orders do not need one, and a word on every row is noise.
 */
export const APPROVAL_STATUS_LABELS: Record<string, string> = {
  NOT_SUBMITTED: "Needs approval",
  INVALIDATED: "Changed since approval",
  ...REQUEST_STATUS_LABELS,
};

const APPROVAL_CLASS: Record<string, string> = {
  NOT_SUBMITTED: "po-status-pending",
  PENDING: "po-status-pending",
  APPROVED: "po-status-success",
  REJECTED: "po-status-danger",
  WITHDRAWN: "po-status-draft",
  EXPIRED: "po-status-draft",
  INVALIDATED: "po-status-pending",
};

export function PurchaseOrderApprovalBadge({
  status,
}: {
  status: string | null | undefined;
}) {
  if (!status || status === "NOT_REQUIRED")
    return <span className="text-muted-foreground">—</span>;
  return (
    <StatusBadge
      tone={
        (APPROVAL_CLASS[status] ?? "po-status-draft").replace(
          "po-status-",
          "",
        ) as StatusTone
      }
      className={`po-status-badge ${APPROVAL_CLASS[status] ?? "po-status-draft"}`}
    >
      {APPROVAL_STATUS_LABELS[status] ?? status}
    </StatusBadge>
  );
}

export function purchaseOrderColumns(
  suppliers: ReadonlyMap<number, string>,
  currencies: ReadonlyMap<number, string>,
  mayViewCommercialCost: boolean,
  /** Whether the company uses approvals at all; the Approval column is left out when it does not. */
  approvalsInUse = false,
  /** Minor-unit places by currency id, so a total reads in its own currency's places. */
  currencyPlaces: ReadonlyMap<number, number> = new Map(),
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
                  {formatAmount(
                    order.total_amount,
                    currencies.get(order.currency_id),
                    currencyPlaces.get(order.currency_id),
                  )}
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
    ...(approvalsInUse
      ? [
          {
            key: "approval_status",
            header: "Approval",
            value: (order) =>
              order.approval_status && order.approval_status !== "NOT_REQUIRED"
                ? (APPROVAL_STATUS_LABELS[order.approval_status] ??
                  order.approval_status)
                : null,
            cell: (order) => (
              <PurchaseOrderApprovalBadge status={order.approval_status} />
            ),
            filterable: true,
          } satisfies DataColumn<PurchaseOrderResource>,
        ]
      : []),
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
