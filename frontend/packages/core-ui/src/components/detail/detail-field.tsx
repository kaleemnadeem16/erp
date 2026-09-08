import type { ReactNode } from 'react';

import { cn } from '../../lib/utils';

export interface DetailFieldProps {
  label: string;
  children: ReactNode;
  className?: string;
  /** Span both columns of the enclosing DetailSection grid — for long text like a description. */
  fullWidth?: boolean;
}

/** One label/value row inside a DetailSection. */
export function DetailField({ label, children, className, fullWidth }: DetailFieldProps) {
  return (
    <div className={cn('space-y-0.5', fullWidth && 'sm:col-span-2', className)}>
      <div className="text-xs font-medium text-muted-foreground">{label}</div>
      <div className="text-sm">{children ?? <span className="text-muted-foreground">—</span>}</div>
    </div>
  );
}
