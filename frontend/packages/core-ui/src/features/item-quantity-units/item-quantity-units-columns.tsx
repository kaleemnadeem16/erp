import type { UomResource } from '@erp/core-api';
import type { DataColumn } from '../../components/data-table/types';
import { formatDate } from '../../lib/format-date';
import { trimQty } from '../package-stock/quantity';
import { packMeaning, type PackRow } from './pack-conversion-common';

/** The columns of the pack conversion table. `uoms` resolves the stock unit for "What it means"; every other fact the record carries is an optional column. */
export const itemQuantityUnitColumns = (uoms: UomResource[] = []): DataColumn<PackRow>[] => [
  {
    key: 'item',
    header: 'Item',
    value: (row) => (row.item ? `${row.item.code} — ${row.item.name}` : row.item_id),
    cell: (row) => <span className="ui-order-link font-medium">{row.item ? `${row.item.code} — ${row.item.name}` : `Item #${row.item_id}`}</span>,
    cardRole: 'title',
  },
  { key: 'uom', header: 'Pack unit', value: (row) => (row.uom ? `${row.uom.name} (${row.uom.code})` : row.uom_id), cardRole: 'subtitle' },
  { key: 'meaning', header: 'What it means', value: (row) => packMeaning(row, uoms) },
  { key: 'factor', header: 'Factor', value: (row) => trimQty(row.factor) },
  { key: 'quantity_precision', header: 'Rounding precision', value: (row) => (row.quantity_precision ? trimQty(row.quantity_precision) : 'Unrestricted') },
  { key: 'created_at', header: 'Added', value: (row) => formatDate(row.created_at), sortable: true, screenVisible: false },
  { key: 'updated_at', header: 'Last changed', value: (row) => formatDate(row.updated_at), screenVisible: false },
];
