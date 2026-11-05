import { useState, type ChangeEvent, type KeyboardEvent, type ReactNode } from 'react';
import { ChevronUp, Search, SlidersHorizontal, X } from 'lucide-react';

import { cn } from '../../lib/utils';
import { Badge } from '../ui/badge';
import { Button } from '../ui/button';
import { Collapse } from '../ui/collapse';
import { Input } from '../ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../ui/select';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '../ui/sheet';

/**
 * The repeated furniture of a list screen, as small shared pieces: the search box, the active-filter chips, the bulk-action bar, the
 * mobile Filters sheet and the animated advanced-filters panel. They hold no data and no rules — the screen owns its query, its
 * eligibility and its handlers — so a reusable behaviour is fixed here once instead of being copied into each list
 * (docs/architecture/erp-blue-design-standard.md §List). Roles `ui-chip-active`, `ui-bulk-bar`, `ui-filter-panel` and
 * `ui-btn-filters-active` come from the shared screen scope.
 */

export interface ListSearchProps {
  /** What the box shows right now (from `useDebouncedSearch`). */
  input: string;
  searchProps: {
    value: string;
    onChange: (event: ChangeEvent<HTMLInputElement>) => void;
    onKeyDown: (event: KeyboardEvent<HTMLInputElement>) => void;
  };
  /** Empties the committed search (the screen's `query.setFilter('search', undefined)`). */
  onClear: () => void;
  placeholder: string;
  /** Names the field for assistive technology; say what is searched, e.g. "Search customers". */
  ariaLabel: string;
  className?: string;
}

/** Search box with a leading icon and a named clear control. 44 px tall on a phone, 40 px from `md`. */
export function ListSearchInput({ input, searchProps, onClear, placeholder, ariaLabel, className }: ListSearchProps) {
  return (
    <div className={cn('relative flex-1 md:w-72 md:flex-none', className)}>
      <Search
        aria-hidden="true"
        className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
      />
      <Input
        type="search"
        className="h-11 pl-9 pr-11 md:h-10 md:pr-9 [&::-webkit-search-cancel-button]:hidden"
        placeholder={placeholder}
        aria-label={ariaLabel}
        {...searchProps}
      />
      {input && (
        <button
          type="button"
          onClick={onClear}
          aria-label="Clear search"
          className="absolute right-1 top-1/2 inline-flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground hover:text-foreground md:right-1.5 md:h-7 md:w-7"
        >
          <X className="h-4 w-4" />
        </button>
      )}
    </div>
  );
}

export interface FilterChip {
  id: string;
  /** Visible label and value, e.g. `Status: Active`. It also names the remove control. */
  label: string;
  onRemove: () => void;
}

/** The filters currently applied, each removable by name, plus Clear all. Renders nothing when no filter is applied. */
export function ActiveFilterChips({ chips, onClearAll }: { chips: FilterChip[]; onClearAll: () => void }) {
  if (chips.length === 0) return null;

  return (
    <div className="flex flex-wrap items-center gap-2" role="region" aria-label="Active filters" data-testid="active-chips">
      <span className="hidden text-xs font-medium text-muted-foreground md:inline">Filters:</span>
      {chips.map((chip) => (
        <Badge
          key={chip.id}
          variant="secondary"
          className="ui-chip-active inline-flex min-h-7 items-center gap-1 py-0.5 pl-2.5 pr-1 text-xs font-normal"
        >
          <span>{chip.label}</span>
          <button
            type="button"
            onClick={chip.onRemove}
            aria-label={`Remove filter: ${chip.label}`}
            className="inline-flex h-6 min-w-6 items-center justify-center rounded-full hover:bg-muted"
          >
            <X className="h-3 w-3" />
          </button>
        </Badge>
      ))}
      <button type="button" className="ui-link-action ml-1 inline-flex min-h-6 shrink-0 cursor-pointer items-center text-xs" onClick={onClearAll}>
        Clear all
      </button>
    </div>
  );
}

/**
 * The bar that appears when rows are ticked: a count, the screen's safe bulk actions (children) and Clear selection. The screen decides
 * which rows are eligible, whether the person may act and what is confirmed; this only gives the actions one consistent place. It stays in
 * normal flow above the table, so it never covers the last row or the phone's bottom navigation.
 */
export function BulkActionBar({
  count,
  noun,
  onClear,
  children,
}: {
  count: number;
  /** Singular noun, e.g. "customer" → "3 customers selected". */
  noun: string;
  onClear: () => void;
  children?: ReactNode;
}) {
  if (count === 0) return null;

  return (
    <div
      role="region"
      aria-label="Bulk actions"
      data-testid="bulk-bar"
      className="ui-bulk-bar flex flex-wrap items-center gap-2 rounded-md border px-3 py-2 text-sm"
    >
      <span className="font-medium" data-testid="bulk-count">
        {count} {count === 1 ? noun : `${noun}s`} selected
      </span>
      <div className="flex flex-wrap items-center gap-2">{children}</div>
      <Button type="button" variant="ghost" size="sm" className="ml-auto h-9 md:h-8" onClick={onClear}>
        Clear selection
      </Button>
    </div>
  );
}

/** Toggle button for the advanced panel (desktop) with an active-count badge. */
export function AdvancedFiltersToggle({
  open,
  activeCount,
  onToggle,
  controlsId,
}: {
  open: boolean;
  activeCount: number;
  onToggle: () => void;
  controlsId: string;
}) {
  const highlighted = open || activeCount > 0;

  return (
    <Button
      type="button"
      variant={highlighted ? 'secondary' : 'outline'}
      size="sm"
      className={cn('h-10 gap-1.5 px-3', highlighted && 'ui-btn-filters-active')}
      onClick={onToggle}
      aria-expanded={open}
      aria-controls={controlsId}
    >
      <SlidersHorizontal aria-hidden="true" className="h-4 w-4" />
      More filters
      {activeCount > 0 && <Badge className="ui-filter-badge ml-1 px-1.5 text-xs">{activeCount}</Badge>}
    </Button>
  );
}

/** The desktop advanced-filters panel: animated through the shared `Collapse`, titled, with Reset and Hide. */
export function AdvancedFiltersPanel({
  id,
  open,
  onClose,
  activeCount,
  onReset,
  children,
}: {
  id: string;
  open: boolean;
  onClose: () => void;
  activeCount: number;
  onReset: () => void;
  children: ReactNode;
}) {
  return (
    <Collapse open={open} id={id} className="hidden md:grid">
      <div role="region" aria-label="Advanced filters" className="ui-filter-panel rounded-lg border border-border bg-card p-4 text-card-foreground shadow-xs">
        <div className="mb-4 flex items-center justify-between">
          <span className="ui-filter-panel-title flex items-center gap-2 text-sm font-semibold">
            <SlidersHorizontal aria-hidden="true" className="h-4 w-4" />
            Advanced filters
          </span>
          <div className="flex items-center gap-2">
            {activeCount > 0 && (
              <Button type="button" variant="ghost" size="sm" className="h-7 text-xs text-muted-foreground hover:text-foreground" onClick={onReset}>
                Reset filters
              </Button>
            )}
            <Button type="button" variant="ghost" size="sm" className="h-7 gap-1 px-2 text-xs" onClick={onClose} aria-label="Hide advanced filters">
              Hide filters
              <ChevronUp aria-hidden="true" className="h-4 w-4" />
            </Button>
          </div>
        </div>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{children}</div>
      </div>
    </Collapse>
  );
}

/**
 * Phone filters: a "Filters" button (44 px, active-count badge) that opens a bottom sheet holding every filter; filters stay live while it is open
 * (no staged Apply), the footer offers Clear filters and Done. Hidden from `md` up, where the inline controls take over.
 */
export function ListFiltersSheet({
  activeCount,
  onClear,
  children,
  title = 'Filters',
}: {
  activeCount: number;
  onClear: () => void;
  children: ReactNode;
  title?: string;
}) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <Button
        type="button"
        variant="outline"
        size="sm"
        className={cn('h-11 shrink-0 gap-1.5 px-3 md:hidden', activeCount > 0 && 'ui-btn-filters-active')}
        onClick={() => setOpen(true)}
        aria-label={activeCount > 0 ? `Open filters, ${activeCount} active` : 'Open filters'}
      >
        <SlidersHorizontal aria-hidden="true" className="h-4 w-4" />
        Filters
        {activeCount > 0 && <Badge className="ui-filter-badge ml-1 px-1.5 text-xs">{activeCount}</Badge>}
      </Button>
      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent side="bottom" className="max-h-[85dvh] overflow-y-auto md:hidden">
          <SheetHeader>
            <SheetTitle>{title}</SheetTitle>
          </SheetHeader>
          <div className="flex flex-col gap-4 py-4">{children}</div>
          <SheetFooter className="flex-row justify-between gap-2 border-t pt-4">
            <Button type="button" variant="ghost" className="h-11" onClick={onClear}>
              Clear filters
            </Button>
            <Button type="button" className="h-11" onClick={() => setOpen(false)}>
              Done
            </Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>
    </>
  );
}

/** A labelled filter field for the advanced panel or the phone sheet. */
export function FilterField({ label, htmlFor, children }: { label: string; htmlFor?: string; children: ReactNode }) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={htmlFor} className="text-sm font-medium">
        {label}
      </label>
      {children}
    </div>
  );
}

/** `MATERIAL_CONSUMPTION` → `Material consumption`: an enum code in the operator's words, for a filter option or a chip (the value stays the code). */
export function humanizeCode(code: string): string {
  const words = code.toLowerCase().replace(/_/g, ' ').trim();

  return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * A bounded enum as a filter: "All …" first, then the fixed values, 44 px tall on a phone and 40 px from `md`. For growing references use a server-searched
 * combobox instead (ui-building-blocks.md §Lookups that survive large datasets).
 */
export function FilterSelect({
  label,
  allLabel,
  value,
  options,
  onChange,
  className,
}: {
  /** The control's accessible name, e.g. "Status". */
  label: string;
  allLabel: string;
  /** The chosen value, or undefined/'' for none. */
  value: string | undefined;
  options: ReadonlyArray<string | { value: string; label: string }>;
  onChange: (value: string | undefined) => void;
  className?: string;
}) {
  return (
    <Select value={value || 'all'} onValueChange={(next) => onChange(next === 'all' ? undefined : next)}>
      <SelectTrigger className={cn('h-11 w-full md:h-10 md:w-48', className)} aria-label={label}>
        <SelectValue placeholder={label} />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="all">{allLabel}</SelectItem>
        {options.map((option) => {
          const item = typeof option === 'string' ? { value: option, label: humanizeCode(option) } : option;

          return (
            <SelectItem key={item.value} value={item.value}>
              {item.label}
            </SelectItem>
          );
        })}
      </SelectContent>
    </Select>
  );
}
