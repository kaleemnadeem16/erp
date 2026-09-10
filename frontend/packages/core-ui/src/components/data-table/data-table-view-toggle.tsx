import { LayoutGrid, List } from 'lucide-react';

import { cn } from '../../lib/utils';
import type { ListView } from '../../lib/use-view-preference';

export interface DataTableViewToggleProps {
  value: ListView;
  onChange: (view: ListView) => void;
}

/** Table/cards segmented control — mobile only, next to `DataTableViewOptions` on desktop. */
export function DataTableViewToggle({ value, onChange }: DataTableViewToggleProps) {
  return (
    <div className="inline-flex items-center rounded-md border border-border p-0.5">
      <button
        type="button"
        aria-pressed={value === 'cards'}
        aria-label="Card view"
        onClick={() => onChange('cards')}
        className={cn(
          'flex h-8 w-8 items-center justify-center rounded-sm',
          value === 'cards' ? 'bg-accent text-accent-foreground' : 'text-muted-foreground'
        )}
      >
        <LayoutGrid className="h-4 w-4" />
      </button>
      <button
        type="button"
        aria-pressed={value === 'table'}
        aria-label="Table view"
        onClick={() => onChange('table')}
        className={cn(
          'flex h-8 w-8 items-center justify-center rounded-sm',
          value === 'table' ? 'bg-accent text-accent-foreground' : 'text-muted-foreground'
        )}
      >
        <List className="h-4 w-4" />
      </button>
    </div>
  );
}
