import { useEffect, useRef } from 'react';

/**
 * Moves focus to a just-opened confirmation panel's heading. Without this, a
 * panel that renders away from its trigger (e.g. a per-row action opening a
 * panel above a table the row can be scrolled well past) leaves keyboard/
 * screen-reader focus on the button just pressed — Tab then continues into
 * the rest of the page instead of reaching the panel's own Confirm/Cancel
 * buttons. Attach the returned ref, plus `tabIndex={-1}`, to the panel's own
 * heading.
 */
export function useFocusOnOpen<T extends HTMLElement>(open: boolean) {
  const ref = useRef<T>(null);
  useEffect(() => {
    if (open) ref.current?.focus();
  }, [open]);
  return ref;
}
