import type { NegativeStockOverrideRequestResource } from '@erp/core-api';

import { StatusBadge, type StatusTone } from '../../components/ui/status-badge';
import { RecordLink } from '../../components/ui/record-link';
import { humanizeCode } from '../../components/data-table/list-controls';
import type { DataColumn } from '../../components/data-table/types';
import { formatDateTime } from '../../lib/format-date';

/**
 * There is no status column on the request itself — status is derived from
 * authorisation_mode plus whether approval/review exists, since a
 * PRIOR_APPROVAL request only ever gets an approval and an
 * IMMEDIATE_WITH_REVIEW request only ever gets a review (never both).
 */
export function overrideStatus(override: NegativeStockOverrideRequestResource): { label: string; tone: StatusTone } {
  if (override.authorisation_mode === 'PRIOR_APPROVAL') {
    if (!override.approval) return { label: 'Pending approval', tone: 'pending' };
    return override.approval.decision === 'APPROVED'
      ? { label: 'Approved', tone: 'success' }
      : { label: 'Rejected', tone: 'danger' };
  }
  if (!override.review) {
    const overdue = override.review_due_at ? new Date(override.review_due_at).getTime() < Date.now() : false;
    return { label: overdue ? 'Pending review (overdue)' : 'Pending review', tone: overdue ? 'danger' : 'pending' };
  }
  return override.review.outcome === 'JUSTIFIED'
    ? { label: 'Justified', tone: 'success' }
    : { label: 'Flagged', tone: 'danger' };
}

export const negativeStockOverrideColumns: DataColumn<NegativeStockOverrideRequestResource>[] = [
  {
    key: 'id',
    header: 'Request',
    value: (override) => `#${override.id}`,
    cell: (override) => <RecordLink>#{override.id}</RecordLink>,
    cardRole: 'title',
  },
  {
    key: 'item',
    header: 'Item',
    value: (override) => (override.item ? `${override.item.code} — ${override.item.name}` : override.item_id),
    cardRole: 'subtitle',
  },
  {
    key: 'movement_class',
    header: 'Movement class',
    value: (override) => humanizeCode(override.movement_class),
    filterable: true,
  },
  {
    key: 'shortage_qty',
    header: 'Shortage',
    value: (override) => override.shortage_qty,
  },
  {
    key: 'authorisation_mode',
    header: 'Mode',
    value: (override) => humanizeCode(override.authorisation_mode),
    filterable: true,
  },
  {
    key: 'status',
    header: 'Status',
    value: (override) => overrideStatus(override).label,
    cell: (override) => {
      const status = overrideStatus(override);
      return <StatusBadge tone={status.tone}>{status.label}</StatusBadge>;
    },
  },
  {
    key: 'requested_at',
    header: 'Requested',
    value: (override) => formatDateTime(override.requested_at),
    sortable: true,
  },
];
