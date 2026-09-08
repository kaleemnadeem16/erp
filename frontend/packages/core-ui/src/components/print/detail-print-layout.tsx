import { PrintLayout, type PrintLayoutProps } from './print-layout';

export interface DetailPrintField {
  label: string;
  value: string;
}

export interface DetailPrintLayoutProps extends Omit<PrintLayoutProps, 'children' | 'recordCount'> {
  fields: DetailPrintField[];
}

/** Quick Print for one record's detail page — a label/value grid instead of a table. */
export function DetailPrintLayout({ fields, ...printLayoutProps }: DetailPrintLayoutProps) {
  return (
    <PrintLayout {...printLayoutProps}>
      <dl className="grid grid-cols-2 gap-x-6 gap-y-0 border-t border-black/20 text-[10px]">
        {fields.map((field) => (
          <div key={field.label} className="flex flex-col border-b border-black/15 py-2 [break-inside:avoid]">
            <dt className="mb-0.5 font-semibold uppercase tracking-wide text-black/50">{field.label}</dt>
            <dd className="text-[11px] leading-4">{field.value || '—'}</dd>
          </div>
        ))}
      </dl>
    </PrintLayout>
  );
}
