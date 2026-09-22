import type { CSSProperties, ReactNode } from 'react';

import { usePrintSettingsStore } from '../../stores/print-settings-store';

export interface PrintMetaLine {
  label: string;
  value: string;
}

export interface PrintLayoutProps {
  /** Document title, e.g. "ITEM LIST". */
  title: string;
  /** A short line under the title, e.g. the document number and its status. */
  subtitle?: string;
  /** Overrides the company name from Settings → Printing, for the rare document that needs a different one. Leave unset to use the global setting. */
  companyName?: string;
  companyLogoUrl?: string;
  /** "Filters: …", "Sort: …" and similar context lines, in display order. */
  meta?: PrintMetaLine[];
  printedAt?: Date;
  /** Shown in the footer, e.g. "Records printed: 12". Omit for a detail (single-record) print. */
  recordCount?: number;
  /** "Prepared by / Approved by / Received by" lines at the foot of the document. Defaults to the global Settings → Printing choice. */
  showSignatureLines?: boolean;
  children: ReactNode;
}

const SIGNATURE_LINES = ['Prepared by', 'Approved by', 'Received by'];

/**
 * The shared paper-oriented shell every Quick Print document renders through
 * — a dedicated print-only layout, never a screenshot of the live screen.
 * Deliberately excludes navigation, the app shell, action buttons and form
 * controls: it only ever receives the data and context the calling screen
 * passes in, per docs/architecture (frontend Quick Print vs backend formal
 * documents — see PrintTrigger's docblock for the split).
 *
 * Branding — company name/logo, the accent colour, the footer note, whether
 * signature lines appear — comes from Settings → Printing
 * (`stores/print-settings-store.ts`) unless a screen passes its own. That is
 * what makes this one "global handler": change a colour once and every
 * screen that prints through this component picks it up without being
 * touched, instead of each of the (currently sixteen) print call sites
 * carrying its own layout.
 */
export function PrintLayout({
  title,
  subtitle,
  companyName,
  companyLogoUrl,
  meta,
  printedAt,
  recordCount,
  showSignatureLines,
  children,
}: PrintLayoutProps) {
  const settings = usePrintSettingsStore();
  const resolvedCompanyName = companyName ?? settings.companyName;
  const resolvedLogoUrl = companyLogoUrl ?? settings.companyLogoUrl;
  const resolvedShowSignatures = showSignatureLines ?? settings.showSignatureLines;
  const style = { '--print-accent': settings.accentColor } as CSSProperties;

  return (
    <div className="w-full bg-white text-left text-black" style={style}>
      {/* A letterhead, not a web card: one clean rule under the header, no
          boxed panels or colour blocks — those read as screen UI, not paper.
          The accent colour still shows through, just on text/rules, the way
          a printed letterhead uses a house colour sparingly. */}
      <header className="mb-4 flex items-start justify-between gap-6 border-b-2 border-black pb-3 [break-after:avoid]">
        <div className="flex items-center gap-3">
          {resolvedLogoUrl && <img src={resolvedLogoUrl} alt="" className="h-10 w-10 object-contain" />}
          <div>
            {resolvedCompanyName && (
              <div className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-black/65">
                {resolvedCompanyName}
              </div>
            )}
            <h1 className="text-xl font-bold leading-tight tracking-wide text-[var(--print-accent)]">{title}</h1>
            {subtitle && <div className="mt-0.5 text-[11px] text-black/60">{subtitle}</div>}
          </div>
        </div>
        {printedAt && (
          <div className="shrink-0 pt-1 text-right text-[10px] leading-4 text-black/60">
            <div className="font-semibold uppercase tracking-wide text-black/60">Printed</div>
            <div>{formatPrintedAt(printedAt)}</div>
          </div>
        )}
      </header>

      {meta && meta.length > 0 && (
        <div className="mb-3 flex flex-wrap gap-x-6 gap-y-1.5 border-b border-black/15 pb-3 text-[10px] text-black/70 [break-after:avoid]">
          {meta.map((line) => (
            <div key={line.label}>
              <span className="font-semibold text-black/85">{line.label}:</span> {line.value}
            </div>
          ))}
        </div>
      )}

      <div>{children}</div>

      {resolvedShowSignatures && (
        <div className="mt-10 grid grid-cols-3 gap-6 [break-inside:avoid]">
          {SIGNATURE_LINES.map((label) => (
            <div key={label} className="pt-6 text-[10px] text-black/60">
              <div className="border-t border-black/40 pt-1">{label}</div>
            </div>
          ))}
        </div>
      )}

      {(recordCount !== undefined || settings.footerNote) && (
        <footer className="mt-4 flex items-center justify-between gap-4 border-t border-black/15 pt-2 text-[10px] text-black/60 [break-inside:avoid]">
          <span>{settings.footerNote}</span>
          {recordCount !== undefined && (
            <span className="shrink-0">
              Records printed <span className="font-semibold text-black/75">{recordCount}</span>
            </span>
          )}
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
