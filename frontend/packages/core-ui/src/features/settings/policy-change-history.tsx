import { DetailSection } from '../../components/detail/detail-page-layout';
import { Skeleton } from '../../components/ui/skeleton';

export interface PolicyChangeRow {
  id: number;
  policy: string;
  old_values: unknown;
  new_values: unknown;
  reason: string;
  changed_by_user?: { name: string; email: string };
  changed_at: string;
}

/** One changed field's before/after, read from the audit row's old/new_values object. */
function fieldDiff(oldValues: unknown, newValues: unknown): { field: string; from: unknown; to: unknown }[] {
  const oldObj = (oldValues && typeof oldValues === 'object' ? oldValues : {}) as Record<string, unknown>;
  const newObj = (newValues && typeof newValues === 'object' ? newValues : {}) as Record<string, unknown>;
  return Object.keys(newObj).map((field) => ({ field, from: oldObj[field], to: newObj[field] }));
}

function formatValue(value: unknown): string {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  return String(value);
}

/**
 * The audit trail ADR 0047 requires alongside a governed policy's current
 * values — shared by PurchasingSettingsScreen and InventorySettingsScreen
 * since InventoryPolicyChangeResource/PurchasingPolicyChangeResource are
 * identically shaped (see docs/decisions/0047-govern-inventory-structure-and-default-valuation-policies.md).
 *
 * Reading this history is gated by its own `*.policy-history.view`
 * permission, separate from the settings' own manage permission — a user
 * who can see and edit the settings may still not be allowed to see who
 * changed them before. `isForbidden` renders that state explicitly rather
 * than falling through to the empty-array "No changes recorded yet." case,
 * which would otherwise misreport a permissions gap as an empty history.
 */
export function PolicyChangeHistory({
  changes,
  isLoading,
  isForbidden,
}: {
  changes: PolicyChangeRow[];
  isLoading?: boolean;
  isForbidden?: boolean;
}) {
  if (isLoading) {
    return (
      <DetailSection title="Recent changes">
        <div className="sm:col-span-2 space-y-2">
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
        </div>
      </DetailSection>
    );
  }

  if (isForbidden) {
    return (
      <DetailSection title="Recent changes">
        <p className="sm:col-span-2 text-sm text-muted-foreground">
          You don't have permission to view this policy's change history.
        </p>
      </DetailSection>
    );
  }

  if (changes.length === 0) {
    return (
      <DetailSection title="Recent changes">
        <p className="sm:col-span-2 text-sm text-muted-foreground">No changes recorded yet.</p>
      </DetailSection>
    );
  }

  return (
    <DetailSection title="Recent changes">
      <div className="sm:col-span-2 space-y-3">
        {changes.map((change) => (
          <div key={change.id} className="rounded-md border border-border p-3 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="font-medium">{change.policy.replace(/_/g, ' ')}</span>
              <span className="text-xs text-muted-foreground">
                {new Date(change.changed_at).toLocaleString()}
                {change.changed_by_user ? ` — ${change.changed_by_user.name}` : ''}
              </span>
            </div>
            <dl className="mt-2 space-y-1">
              {fieldDiff(change.old_values, change.new_values).map((diff) => (
                <div key={diff.field} className="text-xs">
                  <dt className="inline text-muted-foreground">{diff.field.replace(/_/g, ' ')}: </dt>
                  <dd className="inline">
                    {formatValue(diff.from)} → {formatValue(diff.to)}
                  </dd>
                </div>
              ))}
            </dl>
            <p className="mt-2 text-xs text-muted-foreground">"{change.reason}"</p>
          </div>
        ))}
      </div>
    </DetailSection>
  );
}
