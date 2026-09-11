import { SlidersHorizontal } from 'lucide-react';

import { Badge } from './badge';
import { Button } from './button';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from './dropdown-menu';

export interface AdvancedOption {
  /** Stable identity for the checkbox item — not shown, just a React key. */
  id: string;
  label: string;
  checked: boolean;
  onCheckedChange: (checked: boolean) => void;
}

/**
 * The single entry point for every optional/rarely-needed field group on a
 * create/edit screen — one "Advanced options" button in the page header,
 * opening a menu of checkboxes, each one expanding its own section further
 * down the page (see `AdvancedOptionSection`). This is the standard pattern
 * for that shape of UI across the app — see "Advanced options" in
 * docs/architecture/user-interface.md before building a bespoke inline
 * toggle for a new one. A form reports what it has via a callback prop
 * (e.g. `onAdvancedOptionsChange`) so the screen wrapping it can render this
 * menu in its own `PageHeader` — the form owns the state and reset logic,
 * the screen just decides where the trigger sits.
 */
export function AdvancedOptionsMenu({ options }: { options: AdvancedOption[] }) {
  if (options.length === 0) return null;
  const activeCount = options.filter((option) => option.checked).length;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline" size="sm">
          <SlidersHorizontal className="h-4 w-4" />
          Advanced options
          {activeCount > 0 && <Badge className="ml-1">{activeCount}</Badge>}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-64">
        <DropdownMenuLabel>Advanced options</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {options.map((option) => (
          <DropdownMenuCheckboxItem
            key={option.id}
            checked={option.checked}
            onCheckedChange={option.onCheckedChange}
            onSelect={(event) => event.preventDefault()}
          >
            {option.label}
          </DropdownMenuCheckboxItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
