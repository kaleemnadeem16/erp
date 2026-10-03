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
  /** Let any step be opened at any time — for editing something already complete, where there is nothing to gate. */
  freeNavigation?: boolean;
  children: ReactNode;
  /** Back/Next (or Back/Save on the last step) — always render both, even a disabled/invisible Back on the first step, so the footer's spacing stays consistent. */
  footer: ReactNode;
}

/**
 * Generic step indicator + content + footer shell for a multi-step create
 * flow. Purely presentational — the caller owns step state, validation
 * gating, and what content each step shows.
 */
export function WizardShell({ steps, activeIndex, onStepClick, freeNavigation, children, footer }: WizardShellProps) {
  return (
    <div className="space-y-6">
      <ol className="flex items-center gap-1 pb-1 sm:gap-2">
        {steps.map((step, index) => {
          const isActive = index === activeIndex;
          const isComplete = index < activeIndex;
          const isClickable = (isComplete || !!freeNavigation) && !isActive && !!onStepClick;
          return (
            <li key={step.id} className="flex items-center gap-2">
              {index > 0 && <span aria-hidden="true" className="h-px w-3 shrink-0 bg-border sm:w-10" />}
              <button
                type="button"
                disabled={!isClickable}
                onClick={() => onStepClick?.(index)}
                aria-current={isActive ? 'step' : undefined}
                className={cn(
                  'flex items-center gap-2 whitespace-nowrap rounded-md px-1 py-1 text-sm font-medium sm:px-2',
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
                {/* Only the current step keeps its name on a phone — the others are their numbers — so three steps fit. */}
                <span className={cn(!isActive && 'sr-only sm:not-sr-only')}>{step.label}</span>
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
