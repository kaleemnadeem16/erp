import type { PhysicalCountResource } from '@erp/core-api';

import { StatusBadge, type StatusTone } from '../../components/ui/status-badge';
import { RecordLink } from '../../components/ui/record-link';
import type { DataColumn } from '../../components/data-table/types';
import { formatDateTime } from '../../lib/format-date';

const STATUS_TONE: Record<string, StatusTone> = {
  DRAFT: 'draft',
  POSTED: 'success',
  CANCELLED: 'danger',
};

export const physicalCountColumns: DataColumn<PhysicalCountResource>[] = [
  {
    key: 'id',
    header: 'Count',
    value: (count) => `#${count.id}`,
    cell: (count) => <RecordLink>#{count.id}</RecordLink>,
    cardRole: 'title',
  },
  {
    key: 'warehouse',
    header: 'Warehouse',
    value: (count) => count.warehouse?.name ?? null,
    cardRole: 'subtitle',
  },
  {
    key: 'reference',
    header: 'Reference',
    value: (count) => count.reference,
  },
  {
    key: 'status',
    header: 'Status',
    value: (count) => count.status,
    cell: (count) => <StatusBadge tone={STATUS_TONE[count.status] ?? 'draft'}>{count.status}</StatusBadge>,
    filterable: true,
  },
  {
    key: 'created_at',
    header: 'Created',
    value: (count) => formatDateTime(count.created_at),
    sortable: true,
  },
  {
    key: 'posted_at',
    header: 'Posted',
    value: (count) => formatDateTime(count.posted_at),
    sortable: true,
    screenVisible: false,
  },
];
