import type { ReactNode } from 'react';
import { Info } from 'lucide-react';

import { Tooltip, TooltipContent, TooltipTrigger } from './tooltip';
import { FormLabel } from './form';

/**
 * A small (i) beside a field's label that says, in a sentence or two, what the field does and what a value means.
 * Opens on hover, keyboard focus or tap; the text is also what a screen reader announces for the button.
 *
 * Use it for the "what does this do?" question a field can raise — not for errors (those sit under the field) and not
 * for anything a person must read before they can act (that belongs in visible text). This follows how the large ERPs
 * do field help: SAP's quick-info on a field, Odoo's hover help on a label, NetSuite's field-help pop-up.
 */
export function FieldHint({ label, children }: { label: string; children: ReactNode }) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <button
          type="button"
          aria-label={`About ${label}`}
          className="inline-flex size-4 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <Info className="size-3.5" aria-hidden="true" />
        </button>
      </TooltipTrigger>
      <TooltipContent side="top" align="start" className="max-w-xs whitespace-normal py-1.5 text-left leading-snug">
        {children}
      </TooltipContent>
    </Tooltip>
  );
}

/** A `FormLabel` with an optional (i) hint beside it. Must sit inside a `FormItem`. */
export function FormLabelWithHint({ children, hint, hintLabel }: { children: string; hint?: ReactNode; hintLabel?: string }) {
  if (!hint) return <FormLabel>{children}</FormLabel>;
  return (
    <div className="flex items-center gap-1.5">
      <FormLabel>{children}</FormLabel>
      <FieldHint label={hintLabel ?? children}>{hint}</FieldHint>
    </div>
  );
}
