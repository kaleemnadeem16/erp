import { create } from "zustand";
import { persist } from "zustand/middleware";

export interface PrintSettings {
  /** Shown in every document's header until a company-settings endpoint exists. */
  companyName: string;
  companyLogoUrl: string;
  /** An accent colour for rules, section headings and table headers — one change here reaches every Quick Print document, because PrintLayout reads it directly rather than each screen passing its own. */
  accentColor: string;
  /** Printed at the foot of every document, e.g. a registration number or a "Goods once sold…" line. */
  footerNote: string;
  /** "Prepared by / Approved by / Received by" lines at the foot of a document. Off by default — most Quick Print documents (lists, most detail records) don't need a wet signature. */
  showSignatureLines: boolean;
}

export const DEFAULT_PRINT_SETTINGS: PrintSettings = {
  companyName: "",
  companyLogoUrl: "",
  accentColor: "#0f172a",
  footerNote: "",
  showSignatureLines: false,
};

interface PrintSettingsState extends PrintSettings {
  set: (patch: Partial<PrintSettings>) => void;
  reset: () => void;
}

/**
 * Company branding and layout choices for every Quick Print document —
 * browser-local today (persisted to `localStorage`), the same layering plan
 * as appearance (see `stores/appearance-store.ts`): app default now, a
 * company-settings endpoint later without changing how a screen calls
 * `PrintLayout`/`DetailPrintLayout`. See docs/architecture/appearance-and-printing.md.
 */
export const usePrintSettingsStore = create<PrintSettingsState>()(
  persist(
    (set) => ({
      ...DEFAULT_PRINT_SETTINGS,
      set: (patch) => set(patch),
      reset: () => set(DEFAULT_PRINT_SETTINGS),
    }),
    { name: "erp.print-settings" },
  ),
);
