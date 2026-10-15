import { cn } from '../../lib/utils';

/**
 * An on/off filter that sits in a list's toolbar beside the dropdowns ("Mine", "Unassigned", "Needs attention"). It has the same height, padding, radius and border as a
 * Select in both states and never changes size when switched: the "on" state is colour only (the brand tint and edge, set in `screen-templates.css` for
 * `[aria-pressed="true"]`) with `aria-pressed` carrying it for assistive technology. Use it for filters; a checkbox is for choosing table rows or agreeing in a form.
 */
export function FilterToggle({ pressed, onPressedChange, children, className }: { pressed: boolean; onPressedChange: (pressed: boolean) => void; children: string; className?: string }) {
  return (
    <button
      type="button"
      aria-pressed={pressed}
      onClick={() => onPressedChange(!pressed)}
      className={cn(
        'ui-filter-toggle inline-flex h-10 items-center justify-center rounded-md border border-input bg-background px-3 text-sm font-medium transition-colors hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring motion-reduce:transition-none',
        className,
      )}
    >
      {children}
    </button>
  );
}
