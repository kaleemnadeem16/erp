import type { SupplierAccountResource } from "@erp/core-api";
import { StatusBadge, type StatusTone } from "../../components/ui/status-badge";
import type { DataColumn } from "../../components/data-table/types";
import { formatDate } from "../../lib/format-date";

export function SupplierStatusBadge({ status }: { status: string }) {
  const tone: StatusTone = status === "ACTIVE" ? "active" : "draft";
  return (
    <StatusBadge tone={tone}>
      {status === "ACTIVE" ? "Active" : "Archived"}
    </StatusBadge>
  );
}

export function getSupplierColumns(
  currencyLabels?: Map<number, string>,
): DataColumn<SupplierAccountResource>[] {
  return [
    {
      key: "code",
      header: "Code",
      value: (supplier) => supplier.code,
      sortable: true,
      filterable: true,
      cardRole: "subtitle",
      cell: (supplier) => (
        <span className="font-semibold text-foreground">{supplier.code}</span>
      ),
    },
    {
      key: "name",
      header: "Trading partner",
      value: (supplier) => supplier.party?.name ?? "",
      filterable: true,
      cardRole: "title",
      cell: (supplier) =>
        supplier.party?.name ? (
          <span>{supplier.party.name}</span>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      key: "status",
      header: "Status",
      value: (supplier) =>
        supplier.status === "ACTIVE" ? "Active" : "Archived",
      cell: (supplier) => <SupplierStatusBadge status={supplier.status} />,
      filterable: true,
    },
    {
      key: "payment_terms_days",
      header: "Payment terms",
      value: (supplier) =>
        supplier.payment_terms_days != null
          ? `${supplier.payment_terms_days} days`
          : "—",
      cell: (supplier) => (
        <span className="tabular-nums">
          {supplier.payment_terms_days} days
        </span>
      ),
    },
    {
      key: "lead_time_days",
      header: "Lead time",
      value: (supplier) =>
        supplier.lead_time_days != null
          ? `${supplier.lead_time_days} days`
          : "—",
      cell: (supplier) =>
        supplier.lead_time_days != null ? (
          <span className="tabular-nums">{supplier.lead_time_days} days</span>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      key: "currency",
      header: "Currency",
      value: (supplier) =>
        supplier.default_currency_id
          ? (currencyLabels?.get(supplier.default_currency_id) ??
            `Currency #${supplier.default_currency_id}`)
          : "Company default",
      cell: (supplier) => {
        const label = supplier.default_currency_id
          ? (currencyLabels?.get(supplier.default_currency_id) ??
            `Currency #${supplier.default_currency_id}`)
          : "Company default";
        return (
          <span
            className={
              supplier.default_currency_id
                ? "text-foreground"
                : "text-muted-foreground"
            }
          >
            {label}
          </span>
        );
      },
    },
    {
      key: "created_at",
      header: "Created",
      value: (supplier) => formatDate(supplier.created_at),
      sortable: true,
      screenVisible: false,
    },
  ];
}

export const supplierColumns = getSupplierColumns();

