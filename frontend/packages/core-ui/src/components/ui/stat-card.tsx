import type { ReactNode } from 'react';

import { cn } from '../../lib/utils';

/**
 * A single KPI tile — the "value cards above the table" pattern used by
 * inventory/warehouse dashboards generally (total value, low-stock count,
 * etc.). Deliberately generic: any screen with a filtered list and a
 * meaningful rollup over it can reuse this instead of hand-rolling its own.
 */
export function StatCard({
  label,
  value,
  hint,
  tone = 'default',
  isLoading,
}: {
  label: string;
  value: ReactNode;
  hint?: string;
  tone?: 'default' | 'warning';
  isLoading?: boolean;
}) {
  return (
    <div className="min-w-0 flex-1 rounded-lg border border-border p-3">
      <div className="text-xs text-muted-foreground">{label}</div>
      {isLoading ? (
        <div className="mt-1.5 h-7 w-20 animate-pulse rounded bg-muted" />
      ) : (
        <div
          className={cn(
            'mt-0.5 truncate text-xl font-semibold',
            tone === 'warning' && value !== 0 && value !== '0' && value !== '—' && 'text-destructive'
          )}
        >
          {value}
        </div>
      )}
      {hint && <div className="mt-0.5 truncate text-xs text-muted-foreground">{hint}</div>}
    </div>
  );
}
