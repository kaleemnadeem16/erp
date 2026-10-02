import type { ResourceRateResource } from '@erp/core-api';
import type { DataColumn } from '../../components/data-table/types';

export const resourceRateColumns: DataColumn<ResourceRateResource>[] = [
  {
    key: 'item',
    header: 'Item',
    value: (row) => (row.item ? `${row.item.code} — ${row.item.name}` : row.item_id),
    cardRole: 'title',
  },
  {
    key: 'rate',
    header: 'Rate',
    value: (row) => row.rate == null ? 'Restricted' : `${row.rate} / ${row.rate_uom?.code ?? row.rate_uom_id}`,
    cardRole: 'subtitle',
  },
  { key: 'effective_from', header: 'Effective from', value: (row) => row.effective_from, sortable: true },
];
