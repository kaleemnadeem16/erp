import { forwardRef, useState, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { Check, ChevronsUpDown } from 'lucide-react';

import { describeLoadError } from '../../lib/api-errors';
import { cn } from '../../lib/utils';
import { Button } from './button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from './command';
import { Popover, PopoverContent, PopoverTrigger } from './popover';

export interface ComboboxOption {
  value: string;
  label: string;
}

/** Why the options could not be loaded, and how to ask again. Built by `lookupState` from the query that feeds the options. */
export interface ComboboxLoadError {
  error: unknown;
  onRetry: () => void;
  /** What was being loaded, in plain words: "customers". */
  noun: string;
}

export interface ComboboxProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'value' | 'onChange'> {
  value?: string;
  onValueChange: (value: string) => void;
  options: ComboboxOption[];
  placeholder?: string;
  searchPlaceholder?: string;
  emptyText?: string;
  /** Set when the request that feeds `options` failed: the list says why instead of reading as "no results". */
  loadError?: ComboboxLoadError;
  /** Set while that request is still running, so an empty list is not mistaken for "no results". */
  loading?: boolean;
  /** Optional class name to attach to the portaled PopoverContent */
  popoverClassName?: string;
  /** Optional decorative icon shown at the start of the trigger. */
  icon?: ReactNode;
}

/**
 * A searchable, single-select dropdown — the `Select` component's plain list
 * stops scaling once there are more than a handful of options (every
 * supplier picker is the recurring case). Trigger styling matches
 * `SelectTrigger` so the two are visually interchangeable; reach for this one
 * once a list is long enough that scrolling to find an entry beats typing
 * a few letters of it. Forwards `ref` and spreads unrecognised props onto the
 * trigger button — same contract `SelectTrigger` has, so it works the same
 * way inside `<FormControl>` (Radix `Slot` clones `id`/`aria-*`/`ref` from
 * `FormControl` onto whatever single child it wraps).
 */
export const Combobox = forwardRef<HTMLButtonElement, ComboboxProps>(function Combobox(
  { value, onValueChange, options, placeholder = 'Select…', searchPlaceholder = 'Search…', emptyText = 'No results found.', loadError, loading, popoverClassName, icon, className, disabled, ...triggerProps },
  ref
) {
  const [open, setOpen] = useState(false);
  const selected = options.find((option) => option.value === value);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          ref={ref}
          type="button"
          role="combobox"
          aria-expanded={open}
          aria-invalid={loadError ? true : undefined}
          disabled={disabled}
          className={cn(
            'flex h-9 min-w-0 w-full items-center justify-between whitespace-nowrap rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm ring-offset-background focus:outline-none focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
            className
          )}
          {...triggerProps}
        >
          <span className="flex min-w-0 items-center">
            {icon && <span aria-hidden="true" className="mr-2 flex shrink-0 items-center">{icon}</span>}
            <span className={cn('truncate text-left', !selected && 'text-muted-foreground')}>
              {selected ? selected.label : loadError ? `Couldn't load ${loadError.noun}` : loading ? 'Loading…' : placeholder}
            </span>
          </span>
          <ChevronsUpDown className="h-4 w-4 shrink-0 opacity-50" />
        </button>
      </PopoverTrigger>
      <PopoverContent className={cn("w-[--radix-popover-trigger-width] p-0", popoverClassName)} align="start">
        {loadError ? (
          <div role="alert" className="flex flex-col items-center gap-2 p-4 text-center">
            <p className="text-sm font-medium text-destructive">Couldn't load {loadError.noun}.</p>
            <p className="text-sm text-muted-foreground">{describeLoadError(loadError.error)}</p>
            <Button type="button" variant="outline" size="sm" onClick={loadError.onRetry}>
              Try again
            </Button>
          </div>
        ) : (
        <Command>
          <CommandInput placeholder={searchPlaceholder} />
          <CommandList>
            <CommandEmpty>{loading ? 'Loading…' : emptyText}</CommandEmpty>
            <CommandGroup>
              {options.map((option) => (
                <CommandItem
                  key={option.value}
                  value={option.value}
                  keywords={[option.label]}
                  onSelect={(nextValue) => {
                    onValueChange(nextValue);
                    setOpen(false);
                  }}
                >
                  <Check className={cn('h-4 w-4 shrink-0', value === option.value ? 'opacity-100' : 'opacity-0')} />
                  <span className="truncate">{option.label}</span>
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
        )}
      </PopoverContent>
    </Popover>
  );
});
Combobox.displayName = 'Combobox';
