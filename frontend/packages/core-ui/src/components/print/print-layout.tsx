import type { ReactNode } from 'react';

export interface PrintMetaLine {
  label: string;
  value: string;
}

export interface PrintLayoutProps {
  /** Document title, e.g. "ITEM LIST". */
  title: string;
  /** Company name for the header — omitted gracefully until a company-settings endpoint exists (see docs/domain). */
  companyName?: string;
  companyLogoUrl?: string;
  /** "Filters: …", "Sort: …" and similar context lines, in display order. */
  meta?: PrintMetaLine[];
  printedAt?: Date;
  /** Shown in the footer, e.g. "Records printed: 12". Omit for a detail (single-record) print. */
  recordCount?: number;
  children: ReactNode;
}

/**
 * The shared paper-oriented shell every Quick Print document renders through
 * — a dedicated print-only layout, never a screenshot of the live screen.
 * Deliberately excludes navigation, the app shell, action buttons and form
 * controls: it only ever receives the data and context the calling screen
 * passes in, per docs/architecture (frontend Quick Print vs backend formal
 * documents — see PrintTrigger's docblock for the split).
 */
export function PrintLayout({ title, companyName, companyLogoUrl, meta, printedAt, recordCount, children }: PrintLayoutProps) {
  return (
    <div className="bg-white p-8 text-black">
      {/* @page only applies when actually printing — harmless while this sits in a hidden div on screen. */}
      <style>{'@page { size: auto; margin: 16mm; }'}</style>

      <header className="mb-6 flex items-start justify-between border-b border-black/20 pb-4">
        <div className="flex items-center gap-3">
          {companyLogoUrl && <img src={companyLogoUrl} alt="" className="h-10 w-10 object-contain" />}
          <div>
            {companyName && <div className="text-sm font-medium">{companyName}</div>}
            <h1 className="text-lg font-bold tracking-wide">{title}</h1>
          </div>
        </div>
        {printedAt && (
          <div className="text-right text-xs text-black/60">
            <div>Printed: {formatPrintedAt(printedAt)}</div>
          </div>
        )}
      </header>

      {meta && meta.length > 0 && (
        <div className="mb-4 space-y-0.5 text-xs text-black/70">
          {meta.map((line) => (
            <div key={line.label}>
              <span className="font-medium">{line.label}:</span> {line.value}
            </div>
          ))}
        </div>
      )}

      <div>{children}</div>

      {recordCount !== undefined && (
        <footer className="mt-6 border-t border-black/20 pt-2 text-xs text-black/60">
          Records printed: {recordCount}
        </footer>
      )}
    </div>
  );
}

function formatPrintedAt(date: Date): string {
  return new Intl.DateTimeFormat(undefined, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
}
