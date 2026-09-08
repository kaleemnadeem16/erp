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
export function PrintLayout({
  title,
  companyName,
  companyLogoUrl,
  meta,
  printedAt,
  recordCount,
  children,
}: PrintLayoutProps) {
  return (
    <div className="w-full bg-white text-left text-black">
      <header className="mb-4 flex items-start justify-between gap-6 border-b-2 border-black/70 pb-3 [break-after:avoid]">
        <div className="flex items-center gap-3">
          {companyLogoUrl && <img src={companyLogoUrl} alt="" className="h-9 w-9 object-contain" />}
          <div>
            {companyName && (
              <div className="mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-black/60">
                {companyName}
              </div>
            )}
            <h1 className="text-xl font-bold leading-tight tracking-wide">{title}</h1>
          </div>
        </div>
        {printedAt && (
          <div className="shrink-0 pt-1 text-right text-[10px] leading-4 text-black/60">
            <div className="font-semibold uppercase tracking-wide text-black/45">Printed</div>
            <div>{formatPrintedAt(printedAt)}</div>
          </div>
        )}
      </header>

      {meta && meta.length > 0 && (
        <div className="mb-3 flex flex-wrap gap-x-6 gap-y-1 border border-black/15 bg-black/[0.025] px-3 py-2 text-[10px] text-black/70 [break-after:avoid]">
          {meta.map((line) => (
            <div key={line.label}>
              <span className="font-semibold text-black/85">{line.label}:</span> {line.value}
            </div>
          ))}
        </div>
      )}

      <div>{children}</div>

      {recordCount !== undefined && (
        <footer className="mt-4 flex items-center justify-between border-t border-black/25 pt-2 text-[10px] text-black/60 [break-inside:avoid]">
          <span>Records printed</span>
          <span className="font-semibold text-black/80">{recordCount}</span>
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
