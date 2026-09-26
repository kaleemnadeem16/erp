import type { ItemQuantityUnitResource } from '@erp/core-api';
import type { DataColumn } from '../../components/data-table/types';
import { formatDate } from '../../lib/format-date';

export const itemQuantityUnitColumns: DataColumn<ItemQuantityUnitResource>[] = [
  {
    key: 'item',
    header: 'Item',
    value: (row) => (row.item ? `${row.item.code} — ${row.item.name}` : row.item_id),
    cardRole: 'title',
  },
  {
    key: 'uom',
    header: 'Pack unit',
    value: (row) => (row.uom ? `${row.uom.name} (${row.uom.code})` : row.uom_id),
    cardRole: 'subtitle',
  },
  { key: 'factor', header: 'Factor', value: (row) => row.factor, align: 'right' },
  {
    key: 'quantity_precision',
    header: 'Rounding precision',
    value: (row) => row.quantity_precision ?? undefined,
    align: 'right',
  },
  {
    key: 'created_at',
    header: 'Created',
    value: (row) => formatDate(row.created_at),
    sortable: true,
    screenVisible: false,
  },
];
