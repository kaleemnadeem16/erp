import type { WarehouseResource } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import type { DataColumn } from '../../components/data-table/types';

export const warehouseColumns: DataColumn<WarehouseResource>[] = [
  {
    key: 'code',
    header: 'Code',
    value: (warehouse) => warehouse.code,
    sortable: true,
    filterable: true,
    cardRole: 'subtitle',
  },
  {
    key: 'name',
    header: 'Name',
    value: (warehouse) => warehouse.name,
    sortable: true,
    filterable: true,
    cardRole: 'title',
  },
  {
    key: 'is_default',
    header: 'Default',
    value: (warehouse) => warehouse.is_default,
    cell: (warehouse) =>
      warehouse.is_default ? <Badge>Default</Badge> : <span className="text-muted-foreground">No</span>,
  },
  {
    key: 'active',
    header: 'Status',
    value: (warehouse) => (warehouse.active ? 'Active' : 'Inactive'),
    cell: (warehouse) => (
      <Badge variant={warehouse.active ? 'success' : 'secondary'}>{warehouse.active ? 'Active' : 'Inactive'}</Badge>
    ),
    filterable: true,
  },
  {
    key: 'created_at',
    header: 'Created',
    value: (warehouse) => (warehouse.created_at ? new Date(warehouse.created_at).toLocaleDateString() : null),
    sortable: true,
    screenVisible: false,
  },
];
