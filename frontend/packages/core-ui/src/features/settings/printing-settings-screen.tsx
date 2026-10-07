import {
  PurchaseOrderPrintTemplate,
  resolvePurchaseOrderPrintTemplate,
  PURCHASE_ORDER_PRINT_TEMPLATES,
} from "../purchase-orders/purchase-order-print";
import { RotateCcw } from "lucide-react";
import { Link } from "react-router";

import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
import { PageHeader } from "../../components/page/page-header";
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
              <label className="mb-1 block text-sm font-medium">
                Company name
              </label>
              <p className="text-sm">{companyName ?? "—"}</p>
              <p className="text-xs text-muted-foreground">
                Printed documents use the company name from{" "}
                <Link className="underline" to="/settings/company">
                  Settings → Company
                </Link>
                .
              </p>
            </div>
            <div className="sm:col-span-2">
              <label className="mb-1 block text-sm font-medium">Logo URL</label>
              <Input
                value={settings.companyLogoUrl}
                onChange={(event) =>
                  settings.set({ companyLogoUrl: event.target.value })
                }
                placeholder="https://…/logo.png"
              />
            </div>
          </DetailSection>
          <DetailSection title="Purchase order template">
            <div className="sm:col-span-2">
              <label
                htmlFor="po-template"
                className="mb-1 block text-sm font-medium"
              >
                Default template
              </label>
              <select
                id="po-template"
                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                value={
                  resolvePurchaseOrderPrintTemplate(
                    settings.purchaseOrderTemplateId,
                  ).id
                }
                onChange={(event) =>
                  settings.set({ purchaseOrderTemplateId: event.target.value })
                }
              >
                {PURCHASE_ORDER_PRINT_TEMPLATES.map((template) => (
                  <option key={template.id} value={template.id}>
                    {template.label}
                  </option>
                ))}
              </select>
              <p className="mt-2 text-xs text-muted-foreground">
                Used when printing purchase orders. Your choice is saved in this
                browser.
              </p>
              <label className="mt-3 flex items-center gap-2">
                <Checkbox
                  checked={settings.purchaseOrderShowSignatureLines}
                  onCheckedChange={(checked) =>
                    settings.set({
                      purchaseOrderShowSignatureLines: checked === true,
                    })
                  }
                />
                <span className="text-sm">
                  Show purchase order signature lines
                </span>
              </label>
            </div>
          </DetailSection>
          <DetailSection
            title="Appearance"
            description="The accent colour used for headings, rules and table headers."
          >
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
                    borderColor:
                      settings.accentColor === preset.value
                        ? preset.value
                        : "transparent",
                    outline:
                      settings.accentColor === preset.value
                        ? "2px solid var(--ring)"
                        : undefined,
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
                  onChange={(event) =>
                    settings.set({ accentColor: event.target.value })
                  }
                  className="sr-only"
                />
              </label>
            </div>
          </DetailSection>
          <DetailSection title="Footer">
            <div className="sm:col-span-2">
              <label className="mb-1 block text-sm font-medium">
                Footer note
              </label>
              <Textarea
                rows={2}
                value={settings.footerNote}
                onChange={(event) =>
                  settings.set({ footerNote: event.target.value })
                }
                placeholder="A registration number, a standard terms line, or anything else that belongs on every document."
              />
            </div>
            <label className="flex items-center gap-2 sm:col-span-2">
              <Checkbox
                checked={settings.showSignatureLines}
                onCheckedChange={(checked) =>
                  settings.set({ showSignatureLines: checked === true })
                }
              />
              <span className="text-sm">
                Show "Prepared by / Approved by / Received by" signature lines
              </span>
            </label>
          </DetailSection>
        </DetailPageLayout>

        <div className="min-w-0">
          <div className="mb-2 text-sm font-medium text-muted-foreground">
            Purchase order preview · demo data
          </div>
          <div className="overflow-auto rounded-lg border border-border bg-muted/30 p-4 shadow-sm">
            <div
              className="w-fit rounded-sm bg-white shadow-md"
              style={{ zoom: 0.6 }}
            >
              <PurchaseOrderPrintTemplate
                template={resolvePurchaseOrderPrintTemplate(
                  settings.purchaseOrderTemplateId,
                )}
                accentColor={settings.accentColor}
                document={{
                  version: 1,
                  number: "DEMO-0042",
                  status: "DRAFT",
                  draft: true,
                  company: {
                    name: companyName || "Demo Company",
                    address: ["12 Industrial Estate Road", "Lahore, Pakistan"],
                    logoUrl: settings.companyLogoUrl || undefined,
                    fields: [
                      { label: "Phone", value: "+92 42 111 000 111" },
                      { label: "Email", value: "info@example.com" },
                    ],
                  },
                  supplier: {
                    name: "Example supplier",
                    address: ["18 Supplier Road", "Lahore, Pakistan"],
                    fields: [
                      { label: "Supplier code", value: "DEMO-SUPPLIER" },
                    ],
                  },
                  metadata: [
                    { label: "Order date", value: "2026-10-07" },
                    { label: "Currency", value: "PKR" },
                  ],
                  currency: "PKR",
                  commercial: true,
                  lines: [
                    {
                      number: 1,
                      item: "DEMO-ITEM",
                      description: "Industrial coating",
                      details: [],
                      quantity: "10.0000",
                      unit: "drum",
                      price: "1000.0000",
                      priceBasis: "1 drum",
                      amount: "10000.0000",
                    },
                  ],
                  total: "10000.0000",
                  notes: "Demo document for previewing the selected template.",
                  signatures: settings.purchaseOrderShowSignatureLines
                    ? [
                        "Prepared by",
                        "Authorised signature",
                        "Supplier acknowledgement",
                      ]
                    : [],
                  footerNote: settings.footerNote,
                  printedAt: "Preview",
                }}
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
