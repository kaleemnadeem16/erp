import type { RecalculationRequestResource, RecalculationRequestResourceImpactVersionsItem } from '@erp/core-api';

import { Badge } from '../../components/ui/badge';
import type { DataColumn } from '../../components/data-table/types';
import { formatDateTime } from '../../lib/format-date';

type BadgeVariant = 'secondary' | 'default' | 'success' | 'destructive';

/** Highest version_no overall — the request's most recent computed state, stale or not. */
export function latestVersion(
  request: RecalculationRequestResource
): RecalculationRequestResourceImpactVersionsItem | undefined {
  const versions = request.impact_versions ?? [];
  return versions.reduce<RecalculationRequestResourceImpactVersionsItem | undefined>(
    (latest, version) => (!latest || version.version_no > latest.version_no ? version : latest),
    undefined
  );
}

/** The still-current (non-stale) version, if one exists — undefined means regeneration is paused. */
export function currentVersion(
  request: RecalculationRequestResource
): RecalculationRequestResourceImpactVersionsItem | undefined {
  const versions = (request.impact_versions ?? []).filter((version) => !version.is_stale);
  return versions.reduce<RecalculationRequestResourceImpactVersionsItem | undefined>(
    (latest, version) => (!latest || version.version_no > latest.version_no ? version : latest),
    undefined
  );
}

/**
 * No status column exists on the request — a request can accumulate several
 * impact versions over its life (staleness regenerates a replacement), each
 * with its own multi-approver state, so status is derived from the latest/
 * current version rather than one flag. See docs/domain/stock-recalculation.md.
 */
export function recalculationStatus(request: RecalculationRequestResource): { label: string; variant: BadgeVariant } {
  const latest = latestVersion(request);
  if (!latest) return { label: 'Computing…', variant: 'secondary' };
  if (latest.execution) return { label: 'Executed', variant: 'success' };
  if (latest.is_rejected) return { label: 'Rejected', variant: 'destructive' };

  const current = currentVersion(request);
  if (!current) return { label: 'Paused for review', variant: 'destructive' };
  if (current.has_unexpired_full_approval) return { label: 'Approved — ready to execute', variant: 'default' };

  const overdue = new Date(request.review_due_at).getTime() < Date.now();
  return { label: overdue ? 'Pending review (overdue)' : 'Pending review', variant: overdue ? 'destructive' : 'secondary' };
}

export const recalculationRequestColumns: DataColumn<RecalculationRequestResource>[] = [
  {
    key: 'id',
    header: 'Request',
    value: (request) => `#${request.id}`,
    cardRole: 'title',
  },
  {
    key: 'item',
    header: 'Item',
    value: (request) => (request.item ? `${request.item.code} — ${request.item.name}` : request.item_id),
    cardRole: 'subtitle',
  },
  {
    key: 'movement_kind',
    header: 'Movement kind',
    value: (request) => request.movement_kind,
    filterable: true,
  },
  {
    key: 'valuation_adjustment',
    header: 'Valuation adjustment',
    value: (request) => latestVersion(request)?.valuation_adjustment ?? null,
  },
  {
    key: 'status',
    header: 'Status',
    value: (request) => recalculationStatus(request).label,
    cell: (request) => {
      const status = recalculationStatus(request);
      return <Badge variant={status.variant}>{status.label}</Badge>;
    },
  },
  {
    key: 'requested_at',
    header: 'Requested',
    value: (request) => formatDateTime(request.requested_at),
    sortable: true,
  },
  {
    key: 'review_due_at',
    header: 'Review due',
    value: (request) => formatDateTime(request.review_due_at),
    sortable: true,
    screenVisible: false,
  },
];
