import type { ReactNode } from 'react';

import { cn } from '../../lib/utils';

/**
 * Shows or hides a block with the same easing the list's Advanced filters panel uses: it grows from nothing and fades in, and
 * shrinks away the same way. The content stays mounted — so what was typed in it is kept and nothing re-renders on opening — and
 * while closed it is invisible, out of the tab order and hidden from assistive technology. Inside a `SectionStack` its gap
 * to the section above animates with it, so nothing is left behind when it is closed.
 */
export function Collapse({ open, id, className, children }: { open: boolean; id?: string; className?: string; children: ReactNode }) {
  return (
    <div
      id={id}
      className={cn(
        // Timing and easing come from the --collapse-* tokens (apps/main/src/index.css): change them once there for every
        // screen, or set them on one screen's wrapper to override just there. Opening decelerates into place; closing eases in
        // and out, because a decelerating curve looks like a snap when something is going away.
        'grid transition-[grid-template-rows,opacity,visibility,margin-top]',
        open
          ? 'visible grid-rows-[1fr] opacity-100 duration-(--collapse-open-duration) ease-(--collapse-open-ease)'
          : 'invisible pointer-events-none mt-0! grid-rows-[0fr] opacity-0 duration-(--collapse-close-duration) ease-(--collapse-close-ease)',
        'motion-reduce:transition-none',
        className
      )}
      aria-hidden={!open}
      inert={!open}
    >
      {/* The padding and matching negative margin leave room for focus rings, which overflow-hidden would clip. */}
      <div className="-m-1 min-h-0 overflow-hidden p-1">{children}</div>
    </div>
  );
}
