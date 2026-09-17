import type { ReactNode } from 'react';
import { Check } from 'lucide-react';

import { cn } from '../../lib/utils';

export interface WizardStep {
  id: string;
  label: string;
}

export interface WizardShellProps {
  steps: WizardStep[];
  activeIndex: number;
  /** Jump back to an already-completed step by clicking its label. A later step isn't clickable — its validity depends on the current one, which the caller gates via its own "Next" handler. */
  onStepClick?: (index: number) => void;
  children: ReactNode;
  /** Back/Next (or Back/Save on the last step) — always render both, even a disabled/invisible Back on the first step, so the footer's spacing stays consistent. */
  footer: ReactNode;
}

/**
 * Generic step indicator + content + footer shell for a multi-step create
 * flow. Purely presentational — the caller owns step state, validation
 * gating, and what content each step shows.
 */
export function WizardShell({ steps, activeIndex, onStepClick, children, footer }: WizardShellProps) {
  return (
    <div className="space-y-6">
      <ol className="flex items-center gap-2 overflow-x-auto pb-1">
        {steps.map((step, index) => {
          const isActive = index === activeIndex;
          const isComplete = index < activeIndex;
          const isClickable = isComplete && !!onStepClick;
          return (
            <li key={step.id} className="flex items-center gap-2">
              {index > 0 && <span aria-hidden="true" className="h-px w-6 shrink-0 bg-border sm:w-10" />}
              <button
                type="button"
                disabled={!isClickable}
                onClick={() => onStepClick?.(index)}
                aria-current={isActive ? 'step' : undefined}
                className={cn(
                  'flex items-center gap-2 whitespace-nowrap rounded-md px-2 py-1 text-sm font-medium',
                  isActive ? 'text-foreground' : 'text-muted-foreground',
                  isClickable && 'hover:bg-accent hover:text-accent-foreground'
                )}
              >
                <span
                  className={cn(
                    'flex size-6 shrink-0 items-center justify-center rounded-full border text-xs',
                    isActive && 'border-primary bg-primary text-primary-foreground',
                    isComplete && !isActive && 'border-primary text-primary',
                    !isActive && !isComplete && 'border-border text-muted-foreground'
                  )}
                >
                  {isComplete ? <Check className="size-3.5" /> : index + 1}
                </span>
                {step.label}
              </button>
            </li>
          );
        })}
      </ol>
      <div>{children}</div>
      <div className="sticky bottom-0 flex items-center justify-between gap-2 border-t border-border bg-background py-4">
        {footer}
      </div>
    </div>
  );
}
