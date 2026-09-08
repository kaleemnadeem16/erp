import type { ItemResource } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import type { DataColumn } from '../../components/data-table/types';

/**
 * The single source of truth for what an Item "is" on screen, in Quick Print
 * and (later) export — see docs on DataColumn. Add a field here once; it
 * shows up everywhere consistently.
 */
export const itemColumns: DataColumn<ItemResource>[] = [
  {
    key: 'code',
    header: 'Code',
    value: (item) => item.code,
    sortable: true,
    filterable: true,
  },
  {
    key: 'name',
    header: 'Name',
    value: (item) => item.name,
    sortable: true,
    filterable: true,
  },
  {
    key: 'category',
    header: 'Category',
    value: (item) => item.category?.name ?? null,
  },
  {
    key: 'type',
    header: 'Type',
    value: (item) => item.type,
    cell: (item) => <span className="capitalize">{item.type.toLowerCase().replace('_', ' ')}</span>,
    filterable: true,
  },
  {
    key: 'valuation_method',
    header: 'Valuation',
    value: (item) => item.valuation_method,
  },
  {
    key: 'tracking',
    header: 'Tracking',
    value: (item) => item.tracking,
    screenVisible: false,
  },
  {
    key: 'status',
    header: 'Status',
    value: (item) => item.status,
    cell: (item) => <Badge variant={item.status === 'ACTIVE' ? 'success' : 'secondary'}>{item.status}</Badge>,
    filterable: true,
  },
  {
    key: 'created_at',
    header: 'Created',
    value: (item) => (item.created_at ? new Date(item.created_at).toLocaleDateString() : null),
    sortable: true,
    screenVisible: false,
  },
];
