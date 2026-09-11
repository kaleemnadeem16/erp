import { forwardRef, useState, type ButtonHTMLAttributes } from 'react';
import { Check, ChevronsUpDown } from 'lucide-react';

import { cn } from '../../lib/utils';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from './command';
import { Popover, PopoverContent, PopoverTrigger } from './popover';

export interface ComboboxOption {
  value: string;
  label: string;
}

export interface ComboboxProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'value' | 'onChange'> {
  value?: string;
  onValueChange: (value: string) => void;
  options: ComboboxOption[];
  placeholder?: string;
  searchPlaceholder?: string;
  emptyText?: string;
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
  { value, onValueChange, options, placeholder = 'Select…', searchPlaceholder = 'Search…', emptyText = 'No results found.', className, disabled, ...triggerProps },
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
          disabled={disabled}
          className={cn(
            'flex h-9 min-w-0 w-full items-center justify-between whitespace-nowrap rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm ring-offset-background focus:outline-none focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
            className
          )}
          {...triggerProps}
        >
          <span className={cn('truncate text-left', !selected && 'text-muted-foreground')}>
            {selected ? selected.label : placeholder}
          </span>
          <ChevronsUpDown className="h-4 w-4 shrink-0 opacity-50" />
        </button>
      </PopoverTrigger>
      <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
        <Command>
          <CommandInput placeholder={searchPlaceholder} />
          <CommandList>
            <CommandEmpty>{emptyText}</CommandEmpty>
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
      </PopoverContent>
    </Popover>
  );
});
Combobox.displayName = 'Combobox';
