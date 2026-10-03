import type { ReactNode } from 'react';

import { cn } from '../../lib/utils';

/**
 * Stacks the sections of a form or detail page with one consistent gap (`--section-gap`, set in apps/main/src/index.css), so a
 * screen never hand-spaces its cards and a new screen gets the same rhythm by wrapping them in this. A `Collapse` inside it
 * contributes no gap while closed. To change the rhythm everywhere, change the token; to change it on one screen, override the
 * token on that screen's wrapper or pass `className`.
 */
export function SectionStack({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cn('[&>*+*]:mt-(--section-gap)', className)}>{children}</div>;
}
