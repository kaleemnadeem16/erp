import type { ReactNode } from "react";
import { useWatch, type Control } from "react-hook-form";

import type { PurchaseOrderFormValues } from "./purchase-order-form";
import { formatScaled, lineAmounts } from "./purchase-order-amounts";

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

function TotalRow({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className={strong ? "flex items-baseline justify-between gap-3 border-t border-border pt-2 font-semibold" : "flex items-baseline justify-between gap-3"}>
      <dt className={strong ? undefined : "text-muted-foreground"}>{label}</dt>
      <dd className="tabular-nums">{value}</dd>
    </div>
  );
}

/**
 * A live read-out of the purchase order being entered, for the side column of
 * the create and edit sheets: everything chosen so far — supplier and site,
 * dates, currency and exchange rate, notes — and, per line, what is being
 * bought at what price with its own discount and tax, plus an estimated total.
 *
 * Amounts here are an estimate from what is typed, worked out with the same
 * rule the server uses (quantity × unit price, less the line's fixed discount,
 * plus its fixed tax) in exact whole-number arithmetic; the server's own
 * calculation when the order is saved is the one that counts, and the screen
 * says so.
 */
export function PurchaseOrderSummary({
  control,
  supplierOptions,
  siteOptions,
  currencyOptions,
  itemOptions,
  uomOptions,
  paymentTermsDays,
  leadTimeDays,
}: {
  control: Control<PurchaseOrderFormValues>;
  supplierOptions: Option[];
  siteOptions: Option[];
  currencyOptions: Option[];
  itemOptions: Option[];
  uomOptions: Option[];
  /** From the chosen supplier's record, when known. */
  paymentTermsDays?: number;
  leadTimeDays?: number | null;
}) {
  const values = useWatch({ control });
  const label = (options: Option[], value?: string) =>
    value ? options.find((option) => option.value === value)?.label : undefined;
  // Unit options read "CODE — Name"; a line only has room for the code.
  const uomCode = (value?: string) => label(uomOptions, value)?.split(" — ")[0];
  const lines = values.lines ?? [];
  const currencyLabel = label(currencyOptions, values.currency_id);
  const currencyCode = currencyLabel?.split(" — ")[0] ?? "";

  const amounts = lines.map((line) => lineAmounts(line ?? {}));
  const complete = amounts.filter((amount): amount is NonNullable<typeof amount> => amount !== null);
  const sum = (pick: (amount: (typeof complete)[number]) => bigint) =>
    complete.reduce((total, amount) => total + pick(amount), 0n);
  const money = (value: bigint) => `${currencyCode ? `${currencyCode} ` : ""}${formatScaled(value)}`;

  return (
    <>
      <h3 className="text-sm font-semibold">Order summary</h3>
      <div className="space-y-4">
        <SummaryGroup title="Supplier">{label(supplierOptions, values.supplier_account_id) ?? NOT_SET}</SummaryGroup>
        {values.supplier_site_id && (
          <SummaryGroup title="Site">{label(siteOptions, values.supplier_site_id)}</SummaryGroup>
        )}
        {(paymentTermsDays !== undefined || (leadTimeDays !== undefined && leadTimeDays !== null)) && (
          <div className="grid grid-cols-2 gap-4">
            {paymentTermsDays !== undefined && <SummaryGroup title="Payment terms">{paymentTermsDays} days</SummaryGroup>}
            {leadTimeDays !== undefined && leadTimeDays !== null && (
              <SummaryGroup title="Lead time">{leadTimeDays} days</SummaryGroup>
            )}
          </div>
        )}
        <div className="grid grid-cols-2 gap-4">
          <SummaryGroup title="Order date">{values.order_date || NOT_SET}</SummaryGroup>
          <SummaryGroup title="Expected">{values.expected_delivery_date || NOT_SET}</SummaryGroup>
        </div>
        <SummaryGroup title="Currency">{currencyLabel ?? NOT_SET}</SummaryGroup>
        {values.fx_rate && (
          <SummaryGroup title="Exchange rate">
            {values.fx_rate}
            <span className="block text-xs text-muted-foreground">
              {[values.fx_policy, values.fx_rate_date, values.fx_rate_source].filter(Boolean).join(" · ")}
            </span>
          </SummaryGroup>
        )}
        {values.notes?.trim() && (
          <SummaryGroup title="Notes">
            <span className="line-clamp-4 whitespace-pre-line">{values.notes}</span>
          </SummaryGroup>
        )}
        <SummaryGroup title={`Lines (${lines.length})`}>
          <ol className="divide-y divide-border rounded-md border border-border bg-card">
            {lines.map((line, index) => {
              const amount = amounts[index];
              const hasDiscount = (parseFloat(line?.discount_amount ?? "0") || 0) > 0;
              const hasTax = (parseFloat(line?.tax_amount ?? "0") || 0) > 0;
              return (
                <li key={index} className="space-y-1 px-3 py-2">
                  <div className="flex items-baseline justify-between gap-3">
                    <span className="min-w-0 truncate font-medium">
                      {label(itemOptions, line?.item_id) ?? (
                        <span className="font-normal text-muted-foreground">Item not chosen</span>
                      )}
                    </span>
                    <span className="shrink-0 text-xs font-medium tabular-nums">
                      {amount ? formatScaled(amount.total) : "—"}
                    </span>
                  </div>
                  <div className="text-xs tabular-nums text-muted-foreground">
                    {line?.ordered_qty || "0"} {uomCode(line?.purchase_uom_id)} × {line?.unit_price || "0"}
                    {currencyCode ? ` ${currencyCode}` : ""}
                  </div>
                  {(hasDiscount || hasTax) && (
                    <div className="text-xs tabular-nums text-muted-foreground">
                      {hasDiscount && <span>Line discount −{line?.discount_amount}</span>}
                      {hasDiscount && hasTax && <span> · </span>}
                      {hasTax && <span>Line tax +{line?.tax_amount}</span>}
                    </div>
                  )}
                  {(line?.expected_delivery_date || line?.qa_required) && (
                    <div className="text-xs text-muted-foreground">
                      {line?.expected_delivery_date && <span>Due {line.expected_delivery_date}</span>}
                      {line?.expected_delivery_date && line?.qa_required && <span> · </span>}
                      {line?.qa_required && <span>QA check on receipt</span>}
                    </div>
                  )}
                </li>
              );
            })}
          </ol>
        </SummaryGroup>
        {complete.length > 0 && (
          <div className="space-y-2">
            <dl className="space-y-1 text-sm">
              <TotalRow label="Subtotal" value={money(sum((amount) => amount.gross))} />
              {sum((amount) => amount.discount) > 0n && (
                <TotalRow label="Discounts" value={`−${money(sum((amount) => amount.discount))}`} />
              )}
              {sum((amount) => amount.tax) > 0n && (
                <TotalRow label="Tax" value={money(sum((amount) => amount.tax))} />
              )}
              <TotalRow label="Estimated total" value={money(sum((amount) => amount.total))} strong />
            </dl>
            <p className="text-xs text-muted-foreground">
              An estimate from what is typed
              {complete.length < lines.length ? ", leaving out lines that are not filled in yet" : ""}. The saved
              amounts are worked out by the server.
            </p>
          </div>
        )}
      </div>
    </>
  );
}
