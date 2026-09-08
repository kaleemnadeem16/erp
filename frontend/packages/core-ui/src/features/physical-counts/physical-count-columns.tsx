import type { PhysicalCountResource } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import type { DataColumn } from '../../components/data-table/types';

const STATUS_VARIANT: Record<string, 'secondary' | 'success' | 'destructive'> = {
  DRAFT: 'secondary',
  POSTED: 'success',
  CANCELLED: 'destructive',
};

export const physicalCountColumns: DataColumn<PhysicalCountResource>[] = [
  {
    key: 'id',
    header: 'Count',
    value: (count) => `#${count.id}`,
  },
  {
    key: 'reference',
    header: 'Reference',
    value: (count) => count.reference,
  },
  {
    key: 'warehouse',
    header: 'Warehouse',
    value: (count) => count.warehouse?.name ?? null,
  },
  {
    key: 'status',
    header: 'Status',
    value: (count) => count.status,
    cell: (count) => <Badge variant={STATUS_VARIANT[count.status] ?? 'secondary'}>{count.status}</Badge>,
    filterable: true,
  },
  {
    key: 'created_at',
    header: 'Created',
    value: (count) => (count.created_at ? new Date(count.created_at).toLocaleString() : null),
    sortable: true,
  },
  {
    key: 'posted_at',
    header: 'Posted',
    value: (count) => (count.posted_at ? new Date(count.posted_at).toLocaleString() : null),
    sortable: true,
    screenVisible: false,
  },
];
