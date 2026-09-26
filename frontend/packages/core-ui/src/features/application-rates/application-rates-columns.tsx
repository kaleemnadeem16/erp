import type { ApplicationRateResource } from '@erp/core-api';
import { Badge } from '../../components/ui/badge';
import type { DataColumn } from '../../components/data-table/types';

export const applicationRateColumns: DataColumn<ApplicationRateResource>[] = [
  {
    key: 'item',
    header: 'Item',
    value: (row) => (row.item ? `${row.item.code} — ${row.item.name}` : row.item_id),
    filterable: true,
    cardRole: 'title',
  },
  {
    key: 'work_uom',
    header: 'Per',
    value: (row) => row.work_uom?.code ?? row.work_uom_id,
    cardRole: 'subtitle',
  },
  { key: 'qty_per_work_unit', header: 'Qty', value: (row) => row.qty_per_work_unit, align: 'right' },
  { key: 'uom', header: 'Unit', value: (row) => row.uom?.code ?? row.uom_id },
  {
    key: 'dimension_values',
    header: 'Tagged',
    value: (row) => (row.dimension_values.length ? row.dimension_values.map((v) => v.name).join(', ') : '—'),
  },
  {
    key: 'is_default',
    header: 'Default',
    value: (row) => (row.is_default ? 'Default' : ''),
    cell: (row) => (row.is_default ? <Badge>Default</Badge> : null),
  },
];
