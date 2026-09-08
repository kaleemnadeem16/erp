import { useRef } from 'react';
import { useReactToPrint } from 'react-to-print';

const quickPrintPageStyle = `
  @page {
    size: auto;
    margin: 8mm;
  }

  @media print {
    html,
    body {
      margin: 0 !important;
      padding: 0 !important;
    }

    body {
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
  }
`;

/**
 * Frontend Quick Print vs backend formal documents — the two-track model this
 * whole `print/` folder implements:
 *
 * - **Quick Print** (this hook): the data a screen already has loaded —
 *   the current list page, a detail record, selected rows. Browser-driven,
 *   no server round-trip, renders a dedicated print layout (never the live
 *   screen). Use it for Items and every other normal master-data list/detail
 *   screen.
 * - **Backend formal documents** (not built yet): official documents
 *   (POs, GRNs, invoices, stock sheets) and anything needing data beyond
 *   what's on screen — full filtered result sets, aggregation, stable
 *   generated PDFs with company templates/headers/page numbering. Those
 *   render server-side; this hook is the wrong tool for them.
 *
 * `contentRef` must be attached to a DOM node holding the print-only layout
 * (PrintLayout/PrintableTable/DetailPrintLayout) — react-to-print clones it
 * into a hidden iframe, so the source node can stay visually hidden
 * (`className="hidden"`) in the normal page without affecting the printout.
 */
export function useQuickPrint(options?: { documentTitle?: string }) {
  const contentRef = useRef<HTMLDivElement>(null);
  const print = useReactToPrint({
    contentRef,
    documentTitle: options?.documentTitle,
    pageStyle: quickPrintPageStyle,
  });

  return { contentRef, print };
}
