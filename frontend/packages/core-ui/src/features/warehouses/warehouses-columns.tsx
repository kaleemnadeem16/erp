import type { WarehouseResource } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import { RecordLink } from '../../components/ui/record-link';
import type { DataColumn } from '../../components/data-table/types';
import { formatDate } from '../../lib/format-date';

export const warehouseColumns: DataColumn<WarehouseResource>[] = [
  {
    key: 'code',
    header: 'Code',
    value: (warehouse) => warehouse.code,
    cell: (warehouse) => <RecordLink>{warehouse.code}</RecordLink>,
    sortable: true,
    filterable: true,
    cardRole: 'subtitle',
  },
  {
    key: 'name',
    header: 'Name',
    value: (warehouse) => warehouse.name,
    cell: (warehouse) => <span className="font-medium text-foreground">{warehouse.name}</span>,
    sortable: true,
    filterable: true,
    cardRole: 'title',
  },
  {
    key: 'locations',
    header: 'Locations',
    value: (warehouse) => {
      const count = warehouse.locations_count ?? warehouse.locations?.length;
      return count != null && Number(count) > 0 ? `${count} locations` : 'None';
    },
    cell: (warehouse) => {
      const count = warehouse.locations_count ?? warehouse.locations?.length;
      return count != null && Number(count) > 0 ? (
        <Badge variant="secondary" className="font-normal text-xs">
          {count} {Number(count) === 1 ? 'location' : 'locations'}
        </Badge>
      ) : (
        <span className="text-muted-foreground text-xs">—</span>
      );
    },
  },
  {
    key: 'is_default',
    header: 'Default',
    value: (warehouse) => (warehouse.is_default ? 'Default' : 'No'),
    cell: (warehouse) =>
      warehouse.is_default ? <Badge>Default</Badge> : <span className="text-muted-foreground text-xs">—</span>,
    sortable: true,
  },
  {
    key: 'active',
    header: 'Status',
    value: (warehouse) => (warehouse.active ? 'Active' : 'Inactive'),
    cell: (warehouse) => (
      <Badge variant={warehouse.active ? 'success' : 'secondary'}>{warehouse.active ? 'Active' : 'Inactive'}</Badge>
    ),
    sortable: true,
    filterable: true,
  },
  {
    key: 'created_at',
    header: 'Created',
    value: (warehouse) => formatDate(warehouse.created_at),
    sortable: true,
    screenVisible: false,
  },
  {
    key: 'updated_at',
    header: 'Updated',
    value: (warehouse) => formatDate(warehouse.updated_at),
    sortable: true,
    screenVisible: false,
  },
];
