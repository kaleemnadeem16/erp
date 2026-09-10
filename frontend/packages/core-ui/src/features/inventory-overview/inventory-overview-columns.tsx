import type { InventoryOverviewResource } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import type { DataColumn } from '../../components/data-table/types';

export const inventoryOverviewColumns: DataColumn<InventoryOverviewResource>[] = [
  {
    key: 'code',
    header: 'Code',
    value: (row) => row.code,
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
    key: 'available_qty',
    header: 'Available',
    value: (row) => row.available_qty,
    align: 'right',
  },
  {
    key: 'qa_held_qty',
    header: 'QA held',
    value: (row) => row.qa_held_qty,
    align: 'right',
  },
  {
    key: 'on_order_qty',
    header: 'On order',
    value: (row) => row.on_order_qty,
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
