import type { ReactNode } from 'react';

export interface AdvancedOptionSectionProps {
  expanded: boolean;
  /** The section itself — title, description and all, not just its fields. Rendered only when `expanded`. */
  children: ReactNode;
}

/**
 * Wraps one entry in an `AdvancedOptionsMenu` (see that component) —
 * "Keep the UI minimum, features maximum": a section whose value already has
 * a sensible default is entirely absent from the page — not a visible title
 * with a one-line placeholder — until its checkbox, in the page's "Advanced
 * options" menu, opts into it. This component owns no state and renders no
 * chrome of its own; it's a plain conditional around the section (typically
 * a whole `DetailSection`, header and all). See PurchaseOrderForm's
 * exchange-rate section for the reference usage, and "Advanced options" in
 * docs/architecture/user-interface.md for the pattern.
 */
export function AdvancedOptionSection({ expanded, children }: AdvancedOptionSectionProps) {
  return expanded ? children : null;
}
