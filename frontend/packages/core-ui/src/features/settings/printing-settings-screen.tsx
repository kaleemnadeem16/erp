import { RotateCcw } from "lucide-react";
import { Link } from "react-router";

import { DetailPageLayout, DetailSection } from "../../components/detail/detail-page-layout";
import { PageHeader } from "../../components/page/page-header";
import { DetailPrintLayout } from "../../components/print/detail-print-layout";
import { PrintableTable } from "../../components/print/printable-table";
import { Button } from "../../components/ui/button";
import { Checkbox } from "../../components/ui/checkbox";
import { Input } from "../../components/ui/input";
import { Textarea } from "../../components/ui/textarea";
import { useCompanyName } from "../company/use-company-name";
import { usePrintSettingsStore } from "../../stores/print-settings-store";

const ACCENT_PRESETS = [
  { label: "Slate", value: "#0f172a" },
  { label: "Ocean", value: "#1d4ed8" },
  { label: "Amber", value: "#b45309" },
  { label: "Forest", value: "#15803d" },
  { label: "Wine", value: "#9f1239" },
];

interface PreviewRow {
  item: string;
  qty: string;
  price: string;
}
const PREVIEW_ROWS: PreviewRow[] = [
  { item: "Grey Primer Paint 20L", qty: "10 DRM", price: "1,000.00" },
  { item: "Industrial Thinner", qty: "5 DRM", price: "500.00" },
];
const PREVIEW_COLUMNS = [
  { key: "item", header: "Item", value: (row: PreviewRow) => row.item },
  { key: "qty", header: "Qty", value: (row: PreviewRow) => row.qty, align: "right" as const },
  { key: "price", header: "Amount", value: (row: PreviewRow) => row.price, align: "right" as const },
];

/**
 * Branding and layout for every Quick Print document, in one place. There is
 * no per-screen print design to visit: `PrintLayout`/`DetailPrintLayout`/
 * `PrintableTable` (the shared components all sixteen print call sites
 * render through) read these settings directly, so a change here reaches
 * every screen without touching any of them. The preview on the right is the
 * real component, not a mockup — it re-renders as the fields change.
 */
export function PrintingSettingsScreen() {
  const settings = usePrintSettingsStore();
  const companyName = useCompanyName();

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="Printing"
        description="Company details and colours used on every printed document."
        actions={
          <Button variant="outline" size="sm" onClick={settings.reset}>
            <RotateCcw className="size-4" />
            Reset to default
          </Button>
        }
      />
      <div className="grid grid-cols-1 gap-6 @container xl:grid-cols-2">
        <DetailPageLayout>
          <DetailSection title="Company">
            <div className="sm:col-span-2">
              <label className="mb-1 block text-sm font-medium">Company name</label>
              <p className="text-sm">{companyName ?? "—"}</p>
              <p className="text-xs text-muted-foreground">
                Printed documents use the company name from <Link className="underline" to="/settings/company">Settings → Company</Link>.
              </p>
            </div>
            <div className="sm:col-span-2">
              <label className="mb-1 block text-sm font-medium">Logo URL</label>
              <Input
                value={settings.companyLogoUrl}
                onChange={(event) => settings.set({ companyLogoUrl: event.target.value })}
                placeholder="https://…/logo.png"
              />
            </div>
          </DetailSection>
          <DetailSection title="Appearance" description="The accent colour used for headings, rules and table headers.">
            <div className="flex flex-wrap gap-2 sm:col-span-2">
              {ACCENT_PRESETS.map((preset) => (
                <button
                  key={preset.value}
                  type="button"
                  title={preset.label}
                  aria-label={preset.label}
                  aria-pressed={settings.accentColor === preset.value}
                  onClick={() => settings.set({ accentColor: preset.value })}
                  className="flex size-8 items-center justify-center rounded-full border-2 transition-colors"
                  style={{
                    background: preset.value,
                    borderColor: settings.accentColor === preset.value ? preset.value : "transparent",
                    outline: settings.accentColor === preset.value ? "2px solid var(--ring)" : undefined,
                    outlineOffset: 2,
                  }}
                />
              ))}
              <label className="flex size-8 cursor-pointer items-center justify-center rounded-full border border-dashed border-input text-[9px] text-muted-foreground">
                <span className="sr-only">Custom colour</span>
                Custom
                <input
                  type="color"
                  value={settings.accentColor}
                  onChange={(event) => settings.set({ accentColor: event.target.value })}
                  className="sr-only"
                />
              </label>
            </div>
          </DetailSection>
          <DetailSection title="Footer">
            <div className="sm:col-span-2">
              <label className="mb-1 block text-sm font-medium">Footer note</label>
              <Textarea
                rows={2}
                value={settings.footerNote}
                onChange={(event) => settings.set({ footerNote: event.target.value })}
                placeholder="A registration number, a standard terms line, or anything else that belongs on every document."
              />
            </div>
            <label className="flex items-center gap-2 sm:col-span-2">
              <Checkbox
                checked={settings.showSignatureLines}
                onCheckedChange={(checked) => settings.set({ showSignatureLines: checked === true })}
              />
              <span className="text-sm">Show "Prepared by / Approved by / Received by" signature lines</span>
            </label>
          </DetailSection>
        </DetailPageLayout>

        <div className="min-w-0">
          <div className="mb-2 text-sm font-medium text-muted-foreground">Preview</div>
          <div className="origin-top rounded-lg border border-border bg-muted/30 p-4 shadow-sm">
            <div className="rounded-sm bg-white p-6 shadow-md">
              <DetailPrintLayout
                title="Purchase order"
                subtitle="No. 0042 · ISSUED"
                printedAt={new Date()}
                sections={[
                  {
                    title: "Order",
                    fields: [
                      { label: "Status", value: "ISSUED" },
                      { label: "Order date", value: "2026-09-21" },
                    ],
                  },
                  {
                    title: "Supplier",
                    fields: [
                      { label: "Supplier", value: "sup-punjab — Punjab Pigments" },
                      { label: "Payment terms", value: "30 days" },
                    ],
                  },
                ]}
              >
                <PrintableTable columns={PREVIEW_COLUMNS} rows={PREVIEW_ROWS} getRowKey={(row) => row.item} />
              </DetailPrintLayout>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
