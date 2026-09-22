import type { ReactNode } from 'react';

import { cn } from '../../lib/utils';
import { PrintLayout, type PrintLayoutProps } from './print-layout';

export interface DetailPrintField {
  label: string;
  value: string;
}

export interface DetailPrintSection {
  title: string;
  fields: DetailPrintField[];
  /** Take the full row instead of sharing it with the next section — for a section with one long field (notes, an address) that two-per-row would squeeze. */
  fullWidth?: boolean;
}

export interface DetailPrintLayoutProps extends Omit<PrintLayoutProps, 'recordCount' | 'children'> {
  /** Mirrors the on-screen page's `DetailSection`s — same titles, same grouping, same field order. */
  sections: DetailPrintSection[];
  /** Rendered after the field sections — e.g. a `PrintableTable` for the record's own line items. */
  children?: ReactNode;
}

/**
 * Quick Print for one record's detail page — label/value grids grouped under
 * the same section headings the screen itself uses (`DetailSection`), so the
 * printout reads as the same document rather than every field flattened into
 * one undifferentiated grid. `children`, if given, renders after the field
 * sections — e.g. a `PrintableTable` for a record's own line items (a PO's
 * or goods receipt's lines), which don't fit the label/value shape.
 */
export function DetailPrintLayout({ sections, children, ...printLayoutProps }: DetailPrintLayoutProps) {
  return (
    <PrintLayout {...printLayoutProps}>
      <div className="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
        {sections.map((section) => (
          <section
            key={section.title}
            className={cn('min-w-0 [break-inside:avoid]', section.fullWidth && 'sm:col-span-2')}
          >
            <h2 className="mb-1.5 border-b-2 border-[var(--print-accent)] pb-1 text-[10px] font-bold uppercase tracking-wide text-[var(--print-accent)]">
              {section.title}
            </h2>
            <dl className={cn('grid gap-x-4 gap-y-0 text-[10px]', section.fullWidth ? 'grid-cols-1' : 'grid-cols-2')}>
              {section.fields.map((field) => (
                <div key={field.label} className="flex flex-col border-b border-black/10 py-1.5 [break-inside:avoid]">
                  <dt className="mb-0.5 font-semibold uppercase tracking-wide text-black/60">{field.label}</dt>
                  <dd className="text-[11px] leading-4">{field.value || '—'}</dd>
                </div>
              ))}
            </dl>
          </section>
        ))}
      </div>
      {children}
    </PrintLayout>
  );
}
