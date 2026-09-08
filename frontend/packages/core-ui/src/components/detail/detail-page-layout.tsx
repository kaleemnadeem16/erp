import type { ReactNode } from 'react';

import { cn } from '../../lib/utils';

export interface DetailSectionProps {
  title?: string;
  description?: string;
  children: ReactNode;
  className?: string;
}

/** One card-like section of a detail page — group related fields under an optional title. */
export function DetailSection({ title, description, children, className }: DetailSectionProps) {
  return (
    <section className={cn('rounded-lg border border-border p-4', className)}>
      {title && (
        <div className="mb-3">
          <h2 className="text-sm font-semibold">{title}</h2>
          {description && <p className="text-xs text-muted-foreground">{description}</p>}
        </div>
      )}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{children}</div>
    </section>
  );
}

/** The shared shell for a detail (show) screen: PageHeader above, one or more DetailSections below. */
export function DetailPageLayout({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cn('space-y-4', className)}>{children}</div>;
}
