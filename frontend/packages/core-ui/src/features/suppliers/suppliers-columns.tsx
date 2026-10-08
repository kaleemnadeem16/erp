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
  accountLabels?: Map<number, string>,
): DataColumn<SupplierAccountResource>[] {
  return [
    {
      key: "code",
      header: "Supplier code",
      value: (supplier) => supplier.code,
      sortable: true,
      filterable: true,
      cardRole: "subtitle",
      cell: (supplier) => (
        <span className="font-semibold text-foreground tracking-tight">
          {supplier.code}
        </span>
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
          <span className="font-medium">{supplier.party.name}</span>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      key: "party_code",
      header: "Partner code",
      value: (supplier) => supplier.party?.code ?? "",
      screenVisible: false,
      cell: (supplier) => (
        <span className="text-muted-foreground">{supplier.party?.code ?? "—"}</span>
      ),
    },
    {
      key: "status",
      header: "Status",
      value: (supplier) =>
        supplier.status === "ACTIVE" ? "Active" : "Archived",
      sortable: true,
      filterable: true,
      cell: (supplier) => <SupplierStatusBadge status={supplier.status} />,
    },
    {
      key: "payment_terms_days",
      header: "Payment terms",
      value: (supplier) =>
        supplier.payment_terms_days != null
          ? `${supplier.payment_terms_days} days`
          : "—",
      sortable: true,
      filterable: true,
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
      sortable: true,
      filterable: true,
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
      filterable: true,
      cell: (supplier) => {
        const label = supplier.default_currency_id
          ? (currencyLabels?.get(supplier.default_currency_id) ??
            `Currency #${supplier.default_currency_id}`)
          : "Company default";
        return (
          <span
            className={
              supplier.default_currency_id
                ? "text-foreground font-medium"
                : "text-muted-foreground"
            }
          >
            {label}
          </span>
        );
      },
    },
    {
      key: "tax_id",
      header: "Tax ID",
      value: (supplier) => supplier.party?.tax_id ?? "—",
      screenVisible: true,
      cell: (supplier) => (
        <span className="text-muted-foreground tabular-nums">
          {supplier.party?.tax_id || "—"}
        </span>
      ),
    },
    {
      key: "registration_no",
      header: "Registration no.",
      value: (supplier) => supplier.party?.registration_no ?? "—",
      screenVisible: false,
      cell: (supplier) => (
        <span className="text-muted-foreground tabular-nums">
          {supplier.party?.registration_no || "—"}
        </span>
      ),
    },
    {
      key: "website",
      header: "Website",
      value: (supplier) => supplier.party?.website ?? "—",
      screenVisible: false,
      cell: (supplier) =>
        supplier.party?.website ? (
          <a
            href={supplier.party.website}
            target="_blank"
            rel="noreferrer"
            className="text-primary hover:underline truncate max-w-[180px] inline-block"
            onClick={(e) => e.stopPropagation()}
          >
            {supplier.party.website}
          </a>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      key: "payable_account",
      header: "Payable account",
      value: (supplier) =>
        supplier.payable_account_id
          ? (accountLabels?.get(supplier.payable_account_id) ??
            `Account #${supplier.payable_account_id}`)
          : "—",
      screenVisible: false,
      cell: (supplier) => (
        <span className="text-muted-foreground">
          {supplier.payable_account_id
            ? (accountLabels?.get(supplier.payable_account_id) ??
              `Account #${supplier.payable_account_id}`)
            : "—"}
        </span>
      ),
    },
    {
      key: "created_at",
      header: "Created",
      value: (supplier) => formatDate(supplier.created_at),
      sortable: true,
      screenVisible: false,
    },
    {
      key: "updated_at",
      header: "Updated",
      value: (supplier) => formatDate(supplier.updated_at),
      sortable: true,
      screenVisible: false,
    },
  ];
}

export const supplierColumns = getSupplierColumns();
