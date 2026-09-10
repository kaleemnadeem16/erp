import type { InventoryCutoverResource } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import type { DataColumn } from '../../components/data-table/types';

const STATUS_VARIANT: Record<string, 'secondary' | 'default' | 'success' | 'destructive'> = {
  DRAFT: 'secondary',
  VALIDATED: 'default',
  APPROVED: 'default',
  FINALISED: 'success',
  FAILED: 'destructive',
};

export const inventoryCutoverColumns: DataColumn<InventoryCutoverResource>[] = [
  {
    key: 'id',
    header: 'Cutover',
    value: (cutover) => `#${cutover.id}`,
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
    value: (cutover) => cutover.mode,
  },
  {
    key: 'status',
    header: 'Status',
    value: (cutover) => cutover.status,
    cell: (cutover) => <Badge variant={STATUS_VARIANT[cutover.status] ?? 'secondary'}>{cutover.status}</Badge>,
    filterable: true,
  },
  {
    key: 'cutover_at',
    header: 'Cutover at',
    value: (cutover) => (cutover.cutover_at ? new Date(cutover.cutover_at).toLocaleString() : null),
  },
  {
    key: 'created_at',
    header: 'Created',
    value: (cutover) => (cutover.created_at ? new Date(cutover.created_at).toLocaleString() : null),
    sortable: true,
  },
  {
    key: 'validated_at',
    header: 'Validated',
    value: (cutover) => (cutover.validated_at ? new Date(cutover.validated_at).toLocaleString() : null),
    sortable: true,
    screenVisible: false,
  },
  {
    key: 'approved_at',
    header: 'Approved',
    value: (cutover) => (cutover.approved_at ? new Date(cutover.approved_at).toLocaleString() : null),
    sortable: true,
    screenVisible: false,
  },
  {
    key: 'finalised_at',
    header: 'Finalised',
    value: (cutover) => (cutover.finalised_at ? new Date(cutover.finalised_at).toLocaleString() : null),
    sortable: true,
    screenVisible: false,
  },
];
