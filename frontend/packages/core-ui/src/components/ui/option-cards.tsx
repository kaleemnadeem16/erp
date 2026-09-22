import { useId, type ReactNode } from 'react';
import { Check } from 'lucide-react';

import { cn } from '../../lib/utils';

export interface OptionCard<T extends string> {
  value: T;
  label: string;
  description?: string;
  /** A small visual — a swatch, a miniature — shown above the label. */
  preview?: ReactNode;
}

export interface OptionCardsProps<T extends string> {
  /** Names the group for assistive technology, e.g. "Colour mode". */
  label: string;
  value: T;
  onChange: (value: T) => void;
  options: OptionCard<T>[];
  className?: string;
}

/**
 * Pick one of a few options that are easier to choose by looking at than by
 * reading (a theme, a document design, a colour mode). Native radio inputs
 * underneath, visually hidden, so arrow-key navigation, grouping and screen
 * reader announcements are the browser's own rather than re-implemented.
 */
export function OptionCards<T extends string>({ label, value, onChange, options, className }: OptionCardsProps<T>) {
  const name = useId();
  return (
    <div role="radiogroup" aria-label={label} className={cn('grid gap-3 sm:grid-cols-2 lg:grid-cols-3', className)}>
      {options.map((option) => {
        const checked = option.value === value;
        return (
          <label
            key={option.value}
            className={cn(
              'relative flex cursor-pointer flex-col gap-2 rounded-lg border bg-card p-3 text-sm text-card-foreground shadow-sm transition-colors hover:bg-accent has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring',
              checked && 'border-primary bg-primary-soft text-primary-soft-foreground hover:bg-primary-soft'
            )}
          >
            <input
              type="radio"
              name={name}
              value={option.value}
              checked={checked}
              onChange={() => onChange(option.value)}
              className="sr-only"
            />
            {option.preview}
            <span className="font-medium">{option.label}</span>
            {option.description && <span className="text-xs text-muted-foreground">{option.description}</span>}
            {checked && <Check aria-hidden="true" className="absolute right-2.5 top-2.5 size-4 text-primary" />}
          </label>
        );
      })}
    </div>
  );
}
