import type { InventoryOverviewResource } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { RecordLink } from '../../components/ui/record-link';
import type { DataColumn } from '../../components/data-table/types';

/** `uoms` is looked up client-side, same pattern as purchaseOrderColumns' supplier/currency maps. */
export function inventoryOverviewColumns(uoms: ReadonlyMap<number, string>): DataColumn<InventoryOverviewResource>[] {
  return [
  {
    key: 'code',
    header: 'Code',
    value: (row) => row.code,
    cell: (row) => <RecordLink>{row.code}</RecordLink>,
    sortable: true,
    filterable: true,
    cardRole: 'subtitle',
  },
  {
    key: 'name',
    header: 'Name',
    value: (row) => row.name,
    sortable: true,
    filterable: true,
    cardRole: 'title',
  },
  {
    key: 'category',
    header: 'Category',
    value: (row) => row.category?.name ?? null,
  },
  {
    key: 'type',
    header: 'Type',
    value: (row) => row.type,
    cell: (row) => <span className="capitalize">{row.type.toLowerCase().replace('_', ' ')}</span>,
    screenVisible: false,
  },
  {
    key: 'tracking',
    header: 'Tracking',
    value: (row) => (row as { tracking?: string }).tracking ?? 'NONE',
    cell: (row) => <span className="capitalize">{((row as { tracking?: string }).tracking ?? 'NONE').toLowerCase()}</span>,
    screenVisible: false,
  },
  {
    key: 'uom',
    header: 'UoM',
    value: (row) => uoms.get(row.stock_uom_id) ?? `#${row.stock_uom_id}`,
  },
  {
    key: 'valuation_method',
    header: 'Valuation',
    value: (row) => row.valuation_method,
    screenVisible: false,
  },
  {
    key: 'on_hand_qty',
    header: 'On hand',
    value: (row) => row.on_hand_qty,
    align: 'right',
  },
  {
    key: 'on_hand_value',
    header: 'On-hand value',
    value: (row) => row.on_hand_value,
    cell: (row) => <span className="tabular-nums text-status-info">{row.on_hand_value}</span>,
    align: 'right',
    screenVisible: false,
  },
  {
    key: 'available_qty',
    header: 'Available',
    value: (row) => row.available_qty,
    align: 'right',
  },
  {
    key: 'available_value',
    header: 'Available value',
    value: (row) => row.available_value,
    cell: (row) => <span className="tabular-nums text-status-success">{row.available_value}</span>,
    align: 'right',
    screenVisible: false,
  },
  {
    key: 'qa_held_qty',
    header: 'QA held',
    value: (row) => row.qa_held_qty,
    cell: (row) =>
      Number(row.qa_held_qty) > 0 ? (
        <Badge variant="warning">{row.qa_held_qty}</Badge>
      ) : (
        <span className="text-muted-foreground">{row.qa_held_qty}</span>
      ),
    align: 'right',
  },
  {
    key: 'on_order_qty',
    header: 'On order',
    value: (row) => row.on_order_qty,
    cell: (row) =>
      Number(row.on_order_qty) > 0 ? (
        <Badge variant="info">{row.on_order_qty}</Badge>
      ) : (
        <span className="text-muted-foreground">{row.on_order_qty}</span>
      ),
    align: 'right',
  },
  {
    key: 'shortage_qty',
    header: 'Shortage',
    value: (row) => row.shortage_qty,
    cell: (row) =>
      Number(row.shortage_qty) > 0 ? (
        <Badge variant="destructive">{row.shortage_qty}</Badge>
      ) : (
        <span className="text-muted-foreground">{row.shortage_qty}</span>
      ),
    align: 'right',
  },
  ];
}
