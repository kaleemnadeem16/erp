import { ChevronDown } from 'lucide-react';

import { cn } from '../../lib/utils';

/**
 * One show/hide control for a block of optional fields that already have sensible defaults — "More options" / "Hide options".
 * The fields are *hidden, not off*: whatever they hold is still saved, so the toggle says in a line what is currently set
 * differently from the default ("Package, discount") rather than leaving a person to open it to find out.
 *
 * Use this when a form has several rarely-changed fields; use `AdvancedOptionsMenu` (a checkbox per group) only when the groups
 * are genuinely independent and a person turns on exactly one.
 */
export function MoreOptionsToggle({
  open,
  onOpenChange,
  controls,
  label = 'More options',
  openLabel = 'Hide options',
  summary,
  className,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The id of the region this button shows and hides. */
  controls: string;
  label?: string;
  openLabel?: string;
  /** What is currently set away from its default, shown beside the button while it is closed. */
  summary?: string;
  className?: string;
}) {
  return (
    <div className={cn('flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1', className)}>
      <button
        type="button"
        aria-expanded={open}
        aria-controls={controls}
        onClick={() => onOpenChange(!open)}
        className="inline-flex items-center gap-1.5 rounded-md px-1 py-1 text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        <ChevronDown className={cn('size-4 transition-transform', open && 'rotate-180')} aria-hidden="true" />
        {open ? openLabel : label}
      </button>
      {!open && summary && <span className="min-w-0 truncate text-xs text-muted-foreground">Set: {summary}</span>}
    </div>
  );
}
