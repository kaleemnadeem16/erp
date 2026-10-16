import type { ItemSupplierResource } from "@erp/core-api";
import { Clock, Copy, MoreHorizontal, Pencil, Power, Star } from "lucide-react";
import type { DataColumn } from "../../components/data-table/types";
import { Button } from "../../components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "../../components/ui/dropdown-menu";
import { RecordLink } from "../../components/ui/record-link";
import { StatusBadge } from "../../components/ui/status-badge";
import { formatDate } from "../../lib/format-date";
import { formatDecimal } from "../../lib/decimal";

export function ItemSupplierStatusBadge({ active }: { active: boolean }) {
  return (
    <StatusBadge tone={active ? "active" : "draft"} className="ui-status-badge">
      {active ? "Active" : "Inactive"}
    </StatusBadge>
  );
}

export interface ItemSupplierColumnOptions {
  onRowClick?: (row: ItemSupplierResource) => void;
  onEdit?: (row: ItemSupplierResource) => void;
  onToggleActive?: (row: ItemSupplierResource) => void;
  mayManage?: boolean;
}

export function getItemSupplierColumns(
  options?: ItemSupplierColumnOptions,
): DataColumn<ItemSupplierResource>[] {
  const {
    onRowClick,
    onEdit,
    onToggleActive,
    mayManage = false,
  } = options ?? {};

  const cols: DataColumn<ItemSupplierResource>[] = [
    {
      key: "item",
      header: "Item",
      value: (row) =>
        row.item ? `${row.item.code} — ${row.item.name}` : String(row.item_id),
      cell: (row) => (
        <div className="flex flex-col min-w-0">
          <RecordLink
            onClick={(e) => {
              if (onRowClick) {
                e.stopPropagation();
                onRowClick(row);
              }
            }}
          >
            {row.item?.name ?? `Item #${row.item_id}`}
          </RecordLink>
          {row.item?.code && (
            <span className="text-xs text-muted-foreground">
              {row.item.code}
            </span>
          )}
        </div>
      ),
      filterable: true,
      cardRole: "title",
    },
    {
      key: "supplier",
      header: "Supplier",
      value: (row) =>
        row.supplier_account?.party?.name
          ? `${row.supplier_account.code} — ${row.supplier_account.party.name}`
          : (row.supplier_account?.code ?? `#${row.supplier_account_id}`),
      cell: (row) => (
        <div className="flex flex-col min-w-0">
          <span className="truncate text-[var(--ui-table-row-text)] font-medium">
            {row.supplier_account?.party?.name ??
              row.supplier_account?.code ??
              `Supplier #${row.supplier_account_id}`}
          </span>
          {row.supplier_account?.party?.name && row.supplier_account?.code && (
            <span className="text-xs font-mono text-muted-foreground">
              {row.supplier_account.code}
            </span>
          )}
        </div>
      ),
      filterable: true,
      cardRole: "subtitle",
    },
    {
      key: "supplier_item_code",
      header: "Supplier item code",
      value: (row) => row.supplier_item_code ?? "—",
      cell: (row) =>
        row.supplier_item_code ? (
          <code className="rounded bg-muted/70 px-1.5 py-0.5 font-mono text-xs text-foreground">
            {row.supplier_item_code}
          </code>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
      sortable: true,
    },
    {
      key: "priority",
      header: "Priority",
      value: (row) => row.priority ?? null,
      cell: (row) =>
        row.priority === 1 ? (
          <StatusBadge
            tone="success"
            className="font-semibold gap-1 inline-flex items-center"
          >
            <Star className="h-3 w-3 fill-current" />
            #1 Preferred
          </StatusBadge>
        ) : row.priority != null ? (
          <span className="tabular-nums font-medium text-muted-foreground">
            #{row.priority}
          </span>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
      sortable: true,
      align: "center",
    },
    {
      key: "lead_time_days",
      header: "Lead time",
      value: (row) =>
        row.resolved_lead_time_days != null
          ? `${row.resolved_lead_time_days} days`
          : "—",
      cell: (row) =>
        row.resolved_lead_time_days != null ? (
          <div className="inline-flex items-center gap-1.5 text-sm tabular-nums">
            <Clock className="h-3.5 w-3.5 text-muted-foreground/70" />
            <span className="text-[var(--ui-table-row-text)] font-medium">
              {row.resolved_lead_time_days}{" "}
              {row.resolved_lead_time_days === 1 ? "day" : "days"}
            </span>
            {row.lead_time_days != null && (
              <span
                className="rounded bg-primary/10 px-1 text-[10px] font-medium text-primary"
                title="Custom relationship override"
              >
                override
              </span>
            )}
          </div>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
      sortable: true,
      align: "right",
    },
    {
      key: "minimum_order_qty",
      header: "MOQ",
      value: (row) =>
        row.minimum_order_qty ? Number(row.minimum_order_qty) : null,
      cell: (row) =>
        row.minimum_order_qty ? (
          <span className="tabular-nums font-medium text-[var(--ui-table-row-text)]">
            {formatDecimal(row.minimum_order_qty)}
            {row.purchase_uom ? ` ${row.purchase_uom.code}` : ""}
          </span>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
      sortable: true,
      align: "right",
    },
    {
      key: "purchase_uom",
      header: "Purchase unit",
      value: (row) =>
        row.purchase_uom
          ? `${row.purchase_uom.name} (${row.purchase_uom.code})`
          : "Stock unit",
      cell: (row) =>
        row.purchase_uom ? (
          <span className="text-xs font-medium text-[var(--ui-table-row-text)]">
            {row.purchase_uom.code}{" "}
            <span className="text-[11px] text-muted-foreground">
              ({row.purchase_uom.name})
            </span>
          </span>
        ) : (
          <span className="text-xs italic text-muted-foreground">
            Stock unit
          </span>
        ),
    },
    {
      key: "active",
      header: "Status",
      value: (row) => (row.active ? "Active" : "Inactive"),
      cell: (row) => <ItemSupplierStatusBadge active={row.active} />,
      sortable: true,
      filterable: true,
    },
    {
      key: "created_at",
      header: "Created",
      value: (row) => formatDate(row.created_at),
      sortable: true,
      screenVisible: false,
    },
    {
      key: "updated_at",
      header: "Updated",
      value: (row) => formatDate(row.updated_at),
      sortable: true,
      screenVisible: false,
    },
  ];

  if (onRowClick || onEdit || onToggleActive) {
    cols.push({
      key: "actions",
      header: "",
      value: () => "",
      align: "right",
      cell: (row) => (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8 p-0"
              onClick={(e) => e.stopPropagation()}
              aria-label="Relationship actions"
            >
              <MoreHorizontal className="h-4 w-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {onRowClick && (
              <DropdownMenuItem
                onClick={(e) => {
                  e.stopPropagation();
                  onRowClick(row);
                }}
              >
                View details
              </DropdownMenuItem>
            )}
            {mayManage && onEdit && (
              <DropdownMenuItem
                onClick={(e) => {
                  e.stopPropagation();
                  onEdit(row);
                }}
              >
                <Pencil className="mr-2 h-4 w-4" />
                Edit sourcing terms
              </DropdownMenuItem>
            )}
            {row.supplier_item_code && (
              <DropdownMenuItem
                onClick={(e) => {
                  e.stopPropagation();
                  navigator.clipboard?.writeText(row.supplier_item_code ?? "");
                }}
              >
                <Copy className="mr-2 h-4 w-4" />
                Copy SKU ({row.supplier_item_code})
              </DropdownMenuItem>
            )}
            {mayManage && onToggleActive && (
              <>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  onClick={(e) => {
                    e.stopPropagation();
                    onToggleActive(row);
                  }}
                  className={
                    row.active
                      ? "text-destructive focus:text-destructive"
                      : "text-emerald-600 focus:text-emerald-600"
                  }
                >
                  <Power className="mr-2 h-4 w-4" />
                  {row.active
                    ? "Archive relationship"
                    : "Activate relationship"}
                </DropdownMenuItem>
              </>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      ),
    });
  }

  return cols;
}

export const itemSupplierColumns = getItemSupplierColumns();
