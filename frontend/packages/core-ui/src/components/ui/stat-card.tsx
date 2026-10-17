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
 * Phase 4 company theming. Applied as a subtle tinted background surface plus
 * matching value text, with clean borders all around. `zeroIsNeutral` is for
 * tones that only mean something once the count is nonzero (a shortage, a QA
 * hold) — 0 stays neutral card surface instead of announcing "no problem" in
 * the same color an active alert would use.
 */
const TONE_BASE_BG: Record<StatCardTone, string> = {
  default: 'bg-card',
  success: 'bg-status-success-soft/25',
  warning: 'bg-status-warning-soft/25',
  danger: 'bg-status-danger-soft/25',
  info: 'bg-status-info-soft/25',
};

const TONE_ACTIVE_BG: Record<StatCardTone, string> = {
  default: 'bg-accent/70 shadow-xs font-medium',
  success: 'bg-status-success-soft/60 shadow-xs font-medium',
  warning: 'bg-status-warning-soft/60 shadow-xs font-medium',
  danger: 'bg-status-danger-soft/60 shadow-xs font-medium',
  info: 'bg-status-info-soft/60 shadow-xs font-medium',
};

const TONE_HOVER_BG: Record<StatCardTone, string> = {
  default: 'hover:bg-muted/50 hover:shadow-xs',
  success: 'hover:bg-status-success-soft/45 hover:shadow-xs',
  warning: 'hover:bg-status-warning-soft/45 hover:shadow-xs',
  danger: 'hover:bg-status-danger-soft/45 hover:shadow-xs',
  info: 'hover:bg-status-info-soft/45 hover:shadow-xs',
};

export function StatCard({
  label,
  value,
  hint,
  tone = 'default',
  zeroIsNeutral,
  isLoading,
  onClick,
  active,
  className,
}: {
  label: string;
  value: ReactNode;
  hint?: string;
  tone?: StatCardTone;
  /** Renders as `default` when `value` is exactly 0/'0'/'—' — see the tone doc above. */
  zeroIsNeutral?: boolean;
  isLoading?: boolean;
  onClick?: () => void;
  active?: boolean;
  className?: string;
}) {
  const isZero = value === 0 || value === '0' || value === '—';
  const effectiveTone: StatCardTone = zeroIsNeutral && isZero ? 'default' : tone;

  return (
    <div
      role={onClick ? 'button' : undefined}
      tabIndex={onClick ? 0 : undefined}
      onClick={onClick}
      onKeyDown={
        onClick
          ? (event) => {
              if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                onClick();
              }
            }
          : undefined
      }
      className={cn(
        'min-w-0 flex-1 rounded-lg border border-border p-3 transition-all outline-none focus:outline-none focus-visible:ring-1 focus-visible:ring-ring/40',
        active ? TONE_ACTIVE_BG[effectiveTone] : TONE_BASE_BG[effectiveTone],
        onClick && !active && TONE_HOVER_BG[effectiveTone],
        onClick && 'cursor-pointer select-none',
        className
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
