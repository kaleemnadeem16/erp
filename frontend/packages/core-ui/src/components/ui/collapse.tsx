import type { ReactNode } from 'react';

import { cn } from '../../lib/utils';

/**
 * Shows or hides a block with the same easing the list's Advanced filters panel uses: it grows from nothing and fades in, and
 * shrinks away the same way. The content stays mounted — so what was typed in it is kept and nothing re-renders on opening — and
 * while closed it is invisible, out of the tab order and hidden from assistive technology.
 */
export function Collapse({ open, id, className, children }: { open: boolean; id?: string; className?: string; children: ReactNode }) {
  return (
    <div
      id={id}
      className={cn(
        'grid transition-[grid-template-rows,opacity,visibility] duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] motion-reduce:transition-none',
        open ? 'visible grid-rows-[1fr] opacity-100' : 'invisible pointer-events-none grid-rows-[0fr] opacity-0',
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
