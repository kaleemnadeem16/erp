import type { InventoryCutoverResource } from '@erp/core-api';

import { StatusBadge, type StatusTone } from '../../components/ui/status-badge';
import { RecordLink } from '../../components/ui/record-link';
import { humanizeCode } from '../../components/data-table/list-controls';
import type { DataColumn } from '../../components/data-table/types';
import { formatDateTime } from '../../lib/format-date';

const STATUS_TONE: Record<string, StatusTone> = {
  DRAFT: 'draft',
  VALIDATED: 'progress',
  APPROVED: 'active',
  FINALISED: 'success',
  FAILED: 'danger',
};

export const inventoryCutoverColumns: DataColumn<InventoryCutoverResource>[] = [
  {
    key: 'id',
    header: 'Cutover',
    value: (cutover) => `#${cutover.id}`,
    cell: (cutover) => <RecordLink>#{cutover.id}</RecordLink>,
    cardRole: 'title',
  },
  {
    key: 'source_system',
    header: 'Source system',
    value: (cutover) => cutover.source_system,
    cardRole: 'subtitle',
  },
  {
    key: 'mode',
    header: 'Mode',
    value: (cutover) => humanizeCode(cutover.mode),
  },
  {
    key: 'status',
    header: 'Status',
    value: (cutover) => cutover.status,
    cell: (cutover) => <StatusBadge tone={STATUS_TONE[cutover.status] ?? 'draft'}>{cutover.status}</StatusBadge>,
    filterable: true,
  },
  {
    key: 'cutover_at',
    header: 'Cutover at',
    value: (cutover) => formatDateTime(cutover.cutover_at),
  },
  {
    key: 'created_at',
    header: 'Created',
    value: (cutover) => formatDateTime(cutover.created_at),
    sortable: true,
  },
  {
    key: 'validated_at',
    header: 'Validated',
    value: (cutover) => formatDateTime(cutover.validated_at),
    sortable: true,
    screenVisible: false,
  },
  {
    key: 'approved_at',
    header: 'Approved',
    value: (cutover) => formatDateTime(cutover.approved_at),
    sortable: true,
    screenVisible: false,
  },
  {
    key: 'finalised_at',
    header: 'Finalised',
    value: (cutover) => formatDateTime(cutover.finalised_at),
    sortable: true,
    screenVisible: false,
  },
];
