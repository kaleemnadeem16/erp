import type { ReactNode } from 'react';

import { cn } from '../../lib/utils';

export type StatCardTone = 'default' | 'success' | 'warning' | 'danger' | 'info';

/**
 * A single KPI tile — the "value cards above the table" pattern used by
 * inventory/warehouse dashboards generally (total value, low-stock count,
 * etc.). Deliberately generic: any screen with a filtered list and a
 * meaningful rollup over it can reuse this instead of hand-rolling its own.
 *
 * `tone` reaches for the --status-* tokens (see index.css), never a raw
 * Tailwind color — same "one-place edit" stand-in as --nav-active ahead of
 * Phase 4 company theming. Applied as a left accent bar plus matching value
 * text, not a background fill: several of these usually sit side by side, and
 * a filled tile per card reads as noisier than a shared neutral surface with
 * one colored edge each. `zeroIsNeutral` is for tones that only mean
 * something once the count is nonzero (a shortage, a QA hold) — 0 stays
 * neutral instead of announcing "no problem" in the same loud color a real
 * one would use.
 */
export function StatCard({
  label,
  value,
  hint,
  tone = 'default',
  zeroIsNeutral,
  isLoading,
}: {
  label: string;
  value: ReactNode;
  hint?: string;
  tone?: StatCardTone;
  /** Renders as `default` when `value` is exactly 0/'0'/'—' — see the tone doc above. */
  zeroIsNeutral?: boolean;
  isLoading?: boolean;
}) {
  const isZero = value === 0 || value === '0' || value === '—';
  const effectiveTone: StatCardTone = zeroIsNeutral && isZero ? 'default' : tone;

  return (
    <div
      className={cn(
        'min-w-0 flex-1 rounded-lg border border-border border-l-4 p-3',
        effectiveTone === 'success' && 'border-l-status-success',
        effectiveTone === 'warning' && 'border-l-status-warning',
        effectiveTone === 'danger' && 'border-l-status-danger',
        effectiveTone === 'info' && 'border-l-status-info'
      )}
    >
      <div className="text-xs text-muted-foreground">{label}</div>
      {isLoading ? (
        <div className="mt-1.5 h-7 w-20 animate-pulse rounded bg-muted" />
      ) : (
        <div
          className={cn(
            'mt-0.5 truncate text-xl font-semibold',
            effectiveTone === 'success' && 'text-status-success',
            effectiveTone === 'warning' && 'text-status-warning',
            effectiveTone === 'danger' && 'text-status-danger',
            effectiveTone === 'info' && 'text-status-info'
          )}
        >
          {value}
        </div>
      )}
      {hint && <div className="mt-0.5 truncate text-xs text-muted-foreground">{hint}</div>}
    </div>
  );
}
