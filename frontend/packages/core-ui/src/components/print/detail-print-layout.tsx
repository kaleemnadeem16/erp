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
      <dl className="grid grid-cols-2 gap-x-8 gap-y-2 text-xs">
        {fields.map((field) => (
          <div key={field.label} className="flex flex-col border-b border-black/10 pb-1">
            <dt className="font-medium text-black/60">{field.label}</dt>
            <dd>{field.value || '—'}</dd>
          </div>
        ))}
      </dl>
    </PrintLayout>
  );
}
