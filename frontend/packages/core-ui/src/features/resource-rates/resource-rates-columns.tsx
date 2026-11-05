import type { ResourceRateResource } from '@erp/core-api';
import { RecordLink } from '../../components/ui/record-link';
import type { DataColumn } from '../../components/data-table/types';
import { formatDate } from '../../lib/format-date';

const itemLabel = (row: ResourceRateResource) => (row.item ? `${row.item.code} — ${row.item.name}` : `Item #${row.item_id}`);

export const resourceRateColumns: DataColumn<ResourceRateResource>[] = [
  {
    key: 'item',
    header: 'Item',
    value: itemLabel,
    cell: (row) => <RecordLink>{itemLabel(row)}</RecordLink>,
    cardRole: 'title',
  },
  {
    key: 'rate',
    header: 'Rate',
    value: (row) => (row.rate == null ? 'Restricted' : `${row.rate} / ${row.rate_uom?.code ?? row.rate_uom_id}`),
    cardRole: 'subtitle',
  },
  { key: 'effective_from', header: 'Effective from', value: (row) => formatDate(row.effective_from) ?? row.effective_from, sortable: true },
];
