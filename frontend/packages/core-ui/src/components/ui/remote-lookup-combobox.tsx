import { useState } from 'react';
import { Check, ChevronsUpDown } from 'lucide-react';

import { describeLoadError, type LookupState } from '../../lib/api-errors';
import { cn } from '../../lib/utils';
import { Button } from './button';
import { Command, CommandGroup, CommandInput, CommandItem, CommandList } from './command';
import { Popover, PopoverContent, PopoverTrigger } from './popover';
import type { ComboboxOption } from './combobox';

interface RemoteLookupComboboxProps extends LookupState {
  label: string;
  allLabel?: string;
  value: string;
  selectedLabel?: string;
  onValueChange: (value: string) => void;
  search: string;
  onSearchChange: (search: string) => void;
  options: ComboboxOption[];
  className?: string;
}

/** A server-filtered single-select lookup. The selected label is independent of the current results page. */
export function RemoteLookupCombobox({
  label,
  allLabel,
  value,
  selectedLabel,
  onValueChange,
  search,
  onSearchChange,
  options,
  className,
  loading,
  loadError,
}: RemoteLookupComboboxProps) {
  const [open, setOpen] = useState(false);
  const displayLabel =
    value === 'all' && allLabel ? allLabel : (options.find((option) => option.value === value)?.label ?? selectedLabel);

  return (
    <Popover
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (!next) onSearchChange('');
      }}
    >
      <PopoverTrigger asChild>
        <button
          type="button"
          role="combobox"
          aria-label={label}
          aria-expanded={open}
          aria-invalid={loadError ? true : undefined}
          className={cn(
            'flex h-9 min-w-0 items-center justify-between rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring',
            className,
          )}
        >
          <span className={cn('truncate text-left', !displayLabel && 'text-muted-foreground')}>
            {displayLabel ?? (loading ? 'Loading…' : label)}
          </span>
          <ChevronsUpDown className="h-4 w-4 shrink-0 opacity-50" />
        </button>
      </PopoverTrigger>
      <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
        {loadError ? (
          <div role="alert" className="flex flex-col items-center gap-2 p-4 text-center">
            <p className="text-sm font-medium text-destructive">Couldn't load {loadError.noun}.</p>
            <p className="text-sm text-muted-foreground">{describeLoadError(loadError.error)}</p>
            <Button type="button" variant="outline" size="sm" onClick={loadError.onRetry}>
              Try again
            </Button>
          </div>
        ) : (
          <Command shouldFilter={false}>
            <CommandInput
              placeholder={`Search ${label.toLowerCase()}…`}
              value={search}
              onValueChange={onSearchChange}
            />
            <CommandList>
              <CommandGroup>
                {allLabel && (
                  <CommandItem
                    value="all"
                    onSelect={() => {
                      onValueChange('all');
                      setOpen(false);
                      onSearchChange('');
                    }}
                  >
                    <Check className={cn('h-4 w-4 shrink-0', value === 'all' ? 'opacity-100' : 'opacity-0')} />
                    {allLabel}
                  </CommandItem>
                )}
                {options.length === 0 && (
                  <p className="px-2 py-4 text-center text-sm text-muted-foreground">
                    {loading ? 'Loading…' : 'No matching results.'}
                  </p>
                )}
                {options.map((option) => (
                  <CommandItem
                    key={option.value}
                    value={option.value}
                    onSelect={() => {
                      onValueChange(option.value);
                      setOpen(false);
                      onSearchChange('');
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
}
