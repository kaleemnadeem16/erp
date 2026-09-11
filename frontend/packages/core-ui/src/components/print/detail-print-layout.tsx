import type { ReactNode } from 'react';

import { PrintLayout, type PrintLayoutProps } from './print-layout';

export interface DetailPrintField {
  label: string;
  value: string;
}

export interface DetailPrintSection {
  title: string;
  fields: DetailPrintField[];
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
      {sections.map((section) => (
        <section key={section.title} className="mb-4 last:mb-0 [break-inside:avoid]">
          <h2 className="mb-1 border-b border-black/40 pb-1 text-[10px] font-bold uppercase tracking-wide text-black/70">
            {section.title}
          </h2>
          <dl className="grid grid-cols-2 gap-x-6 gap-y-0 text-[10px]">
            {section.fields.map((field) => (
              <div key={field.label} className="flex flex-col border-b border-black/15 py-2 [break-inside:avoid]">
                <dt className="mb-0.5 font-semibold uppercase tracking-wide text-black/50">{field.label}</dt>
                <dd className="text-[11px] leading-4">{field.value || '—'}</dd>
              </div>
            ))}
          </dl>
        </section>
      ))}
      {children}
    </PrintLayout>
  );
}
