import type { ReactNode } from "react";
import { useWatch, type Control } from "react-hook-form";

import type { PurchaseOrderFormValues } from "./purchase-order-form";

interface Option {
  value: string;
  label: string;
}

const NOT_SET = <span className="text-muted-foreground">Not set yet</span>;

// Its own single-group `<dl>`, not a `<dt>`/`<dd>` pair dropped into a shared
// one: a `<dl>` may only directly contain one duly-ordered group per wrapping
// `<div>`, and the two-up date row below puts two groups side by side inside
// one such `<div>` — sharing one `<dl>` across all of them would violate that.
function SummaryGroup({ title, children }: { title: string; children: ReactNode }) {
  return (
    <dl className="space-y-1">
      <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{title}</dt>
      <dd className="text-sm">{children}</dd>
    </dl>
  );
}

/**
 * A live read-out of the purchase order being entered, for the side column of
 * the create sheet: what is chosen so far, and what is still missing. It only
 * repeats what was typed — no totals here, because money is worked out by the
 * server when the order is saved and a second, client-side sum could disagree
 * with it.
 */
export function PurchaseOrderSummary({
  control,
  supplierOptions,
  siteOptions,
  currencyOptions,
  itemOptions,
  uomOptions,
}: {
  control: Control<PurchaseOrderFormValues>;
  supplierOptions: Option[];
  siteOptions: Option[];
  currencyOptions: Option[];
  itemOptions: Option[];
  uomOptions: Option[];
}) {
  const values = useWatch({ control });
  const label = (options: Option[], value?: string) =>
    value ? options.find((option) => option.value === value)?.label : undefined;
  // Unit options read "CODE — Name"; a line only has room for the code.
  const uomCode = (value?: string) => label(uomOptions, value)?.split(" — ")[0];
  const lines = values.lines ?? [];

  return (
    <>
      <h3 className="text-sm font-semibold">Order summary</h3>
      <div className="space-y-4">
        <SummaryGroup title="Supplier">{label(supplierOptions, values.supplier_account_id) ?? NOT_SET}</SummaryGroup>
        {values.supplier_site_id && (
          <SummaryGroup title="Site">{label(siteOptions, values.supplier_site_id)}</SummaryGroup>
        )}
        <div className="grid grid-cols-2 gap-4">
          <SummaryGroup title="Order date">{values.order_date || NOT_SET}</SummaryGroup>
          <SummaryGroup title="Expected">{values.expected_delivery_date || NOT_SET}</SummaryGroup>
        </div>
        <SummaryGroup title="Currency">{label(currencyOptions, values.currency_id) ?? NOT_SET}</SummaryGroup>
        {values.notes?.trim() && (
          <SummaryGroup title="Notes">
            <span className="line-clamp-4 whitespace-pre-line">{values.notes}</span>
          </SummaryGroup>
        )}
        <SummaryGroup title={`Lines (${lines.length})`}>
          <ol className="divide-y divide-border rounded-md border border-border bg-card">
            {lines.map((line, index) => (
              <li key={index} className="flex items-baseline justify-between gap-3 px-3 py-2">
                <span className="min-w-0 truncate">
                  {label(itemOptions, line?.item_id) ?? <span className="text-muted-foreground">Item not chosen</span>}
                </span>
                <span className="shrink-0 text-xs tabular-nums text-muted-foreground">
                  {line?.ordered_qty} {uomCode(line?.purchase_uom_id)}
                </span>
              </li>
            ))}
          </ol>
        </SummaryGroup>
      </div>
    </>
  );
}
