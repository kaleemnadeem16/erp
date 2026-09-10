import type { ItemSupplierResource } from '@erp/core-api';
import { Badge } from '../../components/ui/badge';
import type { DataColumn } from '../../components/data-table/types';

export const itemSupplierColumns: DataColumn<ItemSupplierResource>[] = [
  {
    key: 'item',
    header: 'Item',
    value: (row) => (row.item ? `${row.item.code} — ${row.item.name}` : row.item_id),
    filterable: true,
    cardRole: 'title',
  },
  {
    key: 'supplier',
    header: 'Supplier',
    value: (row) => row.supplier_account?.code ?? row.supplier_account_id,
    filterable: true,
    cardRole: 'subtitle',
  },
  { key: 'supplier_item_code', header: 'Supplier item code', value: (row) => row.supplier_item_code },
  { key: 'priority', header: 'Priority', value: (row) => row.priority, sortable: true, align: 'right' },
  { key: 'lead_time_days', header: 'Lead time', value: (row) => `${row.resolved_lead_time_days} days`, align: 'right' },
  {
    key: 'active',
    header: 'Status',
    value: (row) => (row.active ? 'Active' : 'Inactive'),
    cell: (row) => <Badge variant={row.active ? 'success' : 'secondary'}>{row.active ? 'Active' : 'Inactive'}</Badge>,
    filterable: true,
  },
  {
    key: 'created_at',
    header: 'Created',
    value: (row) => (row.created_at ? new Date(row.created_at).toLocaleDateString() : null),
    sortable: true,
    screenVisible: false,
  },
];
