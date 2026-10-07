import type { ReactNode } from "react";

import { cn } from "../../lib/utils";

export interface DetailSectionProps {
  title?: string;
  description?: string;
  children: ReactNode;
  className?: string;
  /**
   * Take the whole row instead of sharing it with the next section — for a
   * section built around a table, or one with many fields that two-or-three
   * up would squeeze. Short sections (a handful of label/value pairs) are
   * better left to share a row; see `DetailPageLayout`.
   */
  fullWidth?: boolean;
}

/**
 * One card-like section of a detail page — group related fields under an
 * optional title. Its own field grid stays viewport-based (`sm:grid-cols-2`,
 * not a container query) on purpose: this component is shared by every
 * create/edit form too (via plain `DetailSection`, standalone, always
 * effectively full width), and a container query here would also need every
 * `sm:col-span-2` inside those forms rewritten. `fullWidth` below only
 * controls this section's own width *within* `DetailPageLayout`'s grid.
 */
export function DetailSection({
  title,
  description,
  children,
  className,
  fullWidth,
}: DetailSectionProps) {
  return (
    <section
      className={cn(
        "detail-section min-w-0 rounded-lg border border-border bg-card p-4 text-card-foreground",
        fullWidth && "col-span-full",
        className,
      )}
    >
      {title && (
        <div className="mb-3">
          <h2 className="text-sm font-semibold">{title}</h2>
          {description && (
            <p className="text-xs text-muted-foreground">{description}</p>
          )}
        </div>
      )}
      <div className="detail-section-fields grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2 [&>*]:min-w-0">
        {children}
      </div>
    </section>
  );
}

/**
 * The shared shell for a detail (show) screen: PageHeader above, one or more
 * `DetailSection`s below, laid out as a grid so short sections (Order,
 * Supplier, Commercial, …) share a row on a wide screen instead of each
 * taking the full width and stacking one under another — mark a section
 * `fullWidth` (a table, a long-text-heavy section) to keep it on its own row.
 * Caps at two columns, not three: `DetailSection`'s own field grid is
 * viewport- not container-based (see its docblock), so a third column would
 * make an already-two-column field grid uncomfortably narrow on a typical
 * desktop width.
 */
export function DetailPageLayout({
  children,
  className,
  equalHeight,
}: {
  children: ReactNode;
  className?: string;
  /** Opt-in: sections sharing a row stretch to the same height instead of each hugging its own content. */
  equalHeight?: boolean;
}) {
  return (
    <div
      className={cn(
        "@container grid min-w-0 grid-cols-1 gap-4 @2xl:grid-cols-2",
        equalHeight ? "items-stretch" : "items-start",
        className,
      )}
    >
      {children}
    </div>
  );
}
