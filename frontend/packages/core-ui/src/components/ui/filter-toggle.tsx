import { Check } from 'lucide-react';

import { cn } from '../../lib/utils';

/**
 * An on/off filter that sits in a list's toolbar beside the dropdowns ("Mine", "Needs attention"). It reads as one of the toolbar's controls — same height and border as a
 * Select — and, when on, takes the active-filter look the chips use, with a tick so the state is never only a colour. Use it instead of a bare checkbox for a filter;
 * a checkbox is for choosing records in a table, and for agreeing to something in a form.
 */
export function FilterToggle({ pressed, onPressedChange, children, className }: { pressed: boolean; onPressedChange: (pressed: boolean) => void; children: string; className?: string }) {
  return (
    <button
      type="button"
      aria-pressed={pressed}
      onClick={() => onPressedChange(!pressed)}
      className={cn(
        'inline-flex h-10 items-center gap-1.5 rounded-md border border-input bg-background px-3 text-sm font-medium transition-colors hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring motion-reduce:transition-none',
        pressed && 'ui-chip-active border-primary/40 bg-primary/10 text-primary hover:bg-primary/15',
        className,
      )}
    >
      {pressed && <Check className="h-4 w-4" aria-hidden="true" />}
      {children}
    </button>
  );
}
