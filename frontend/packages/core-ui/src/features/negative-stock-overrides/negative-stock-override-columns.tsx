import type { NegativeStockOverrideRequestResource } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import type { DataColumn } from '../../components/data-table/types';
import { formatDateTime } from '../../lib/format-date';

type BadgeVariant = 'secondary' | 'default' | 'success' | 'destructive';

/**
 * There is no status column on the request itself — status is derived from
 * authorisation_mode plus whether approval/review exists, since a
 * PRIOR_APPROVAL request only ever gets an approval and an
 * IMMEDIATE_WITH_REVIEW request only ever gets a review (never both).
 */
export function overrideStatus(override: NegativeStockOverrideRequestResource): { label: string; variant: BadgeVariant } {
  if (override.authorisation_mode === 'PRIOR_APPROVAL') {
    if (!override.approval) return { label: 'Pending approval', variant: 'secondary' };
    return override.approval.decision === 'APPROVED'
      ? { label: 'Approved', variant: 'success' }
      : { label: 'Rejected', variant: 'destructive' };
  }
  if (!override.review) {
    const overdue = override.review_due_at ? new Date(override.review_due_at).getTime() < Date.now() : false;
    return { label: overdue ? 'Pending review (overdue)' : 'Pending review', variant: overdue ? 'destructive' : 'secondary' };
  }
  return override.review.outcome === 'JUSTIFIED'
    ? { label: 'Justified', variant: 'success' }
    : { label: 'Flagged', variant: 'destructive' };
}

export const negativeStockOverrideColumns: DataColumn<NegativeStockOverrideRequestResource>[] = [
  {
    key: 'id',
    header: 'Request',
    value: (override) => `#${override.id}`,
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
    value: (override) => override.movement_class,
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
    value: (override) => override.authorisation_mode,
    filterable: true,
  },
  {
    key: 'status',
    header: 'Status',
    value: (override) => overrideStatus(override).label,
    cell: (override) => {
      const status = overrideStatus(override);
      return <Badge variant={status.variant}>{status.label}</Badge>;
    },
  },
  {
    key: 'requested_at',
    header: 'Requested',
    value: (override) => formatDateTime(override.requested_at),
    sortable: true,
  },
];
