import type { ItemResource } from "@erp/core-api";

import { RecordLink } from "../../components/ui/record-link";
import { StatusBadge, type StatusTone } from "../../components/ui/status-badge";
import type { DataColumn } from "../../components/data-table/types";
import { formatDate } from "../../lib/format-date";

/** One status pill for every Items view (list and detail screen), matching system palette tokens. */
export function ItemStatusBadge({ status }: { status: string }) {
  const tone: StatusTone = status === "ACTIVE" ? "success" : "draft";
  return (
    <StatusBadge tone={tone} className="ui-status-badge">
      {status === "ACTIVE" ? "Active" : "Archived"}
    </StatusBadge>
  );
}

/**
 * The single source of truth for what an Item "is" on screen, in Quick Print
 * and export. Add a field here once; it shows up everywhere consistently.
 */
export function itemColumns(
  uomMap?: ReadonlyMap<number, string>,
): DataColumn<ItemResource>[] {
  return [
    {
      key: "code",
      header: "Code",
      value: (item) => item.code,
      cell: (item) => (
        <RecordLink className="font-mono text-xs">{item.code}</RecordLink>
      ),
      sortable: true,
      cardRole: "title",
    },
    {
      key: "name",
      header: "Name",
      value: (item) => item.name,
      cell: (item) => (
        <span className="font-medium text-foreground">{item.name}</span>
      ),
      sortable: true,
      cardRole: "subtitle",
    },
    {
      key: "category",
      header: "Category",
      value: (item) => item.category?.name ?? null,
      cell: (item) =>
        item.category?.name ? (
          <span>{item.category.name}</span>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      key: "type",
      header: "Type",
      value: (item) => item.type,
      cell: (item) => (
        <span className="capitalize">
          {item.type.toLowerCase().replace("_", " ")}
        </span>
      ),
      filterable: true,
    },
    {
      key: "stock_uom",
      header: "Stock unit",
      value: (item) =>
        uomMap ? (uomMap.get(item.stock_uom_id) ?? null) : null,
      cell: (item) => {
        const uom = uomMap?.get(item.stock_uom_id);
        return uom ? (
          <span>{uom}</span>
        ) : (
          <span className="text-muted-foreground">—</span>
        );
      },
    },
    {
      key: "valuation_method",
      header: "Valuation",
      value: (item) => item.valuation_method,
    },
    {
      key: "tracking",
      header: "Tracking",
      value: (item) => item.tracking,
      screenVisible: false,
    },
    {
      key: "status",
      header: "Status",
      value: (item) => item.status,
      cell: (item) => <ItemStatusBadge status={item.status} />,
      filterable: true,
    },
    {
      key: "created_at",
      header: "Created",
      value: (item) => formatDate(item.created_at),
      sortable: true,
      screenVisible: false,
    },
  ];
}

export const defaultItemColumns: DataColumn<ItemResource>[] = itemColumns();
