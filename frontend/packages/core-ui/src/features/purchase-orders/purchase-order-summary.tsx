import { useEffect, useMemo, useState, type ReactNode } from "react";
import { useWatch, type Control } from "react-hook-form";
import {
  usePurchaseOrderLinesPreview,
  type PurchaseOrderLineResource,
} from "@erp/core-api";

import {
  toPurchaseOrderPayload,
  type PurchaseOrderFormValues,
} from "./purchase-order-form";
import { formatScaled, lineAmounts } from "./purchase-order-amounts";
import { DataTableViewToggle } from "../../components/data-table/data-table-view-toggle";
import { useLinesViewPreference } from "../../lib/use-view-preference";

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
function AsideSummary({
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

interface SummaryProps {
  control: Control<PurchaseOrderFormValues>;
  supplierOptions: Option[];
  siteOptions: Option[];
  currencyOptions: Option[];
  itemOptions: Option[];
  uomOptions: Option[];
  paymentTermsDays?: number;
  leadTimeDays?: number | null;
}

/**
 * The server's own working-out of every line (stock quantity, conversion, package, price basis, total), fetched once the
 * review is showing. The estimate in the side column cannot know these — they depend on the item's conversions and the
 * supplier's offer — and the review is where they matter.
 */
function useResolvedLines(values: unknown) {
  const [resolved, setResolved] = useState<(PurchaseOrderLineResource | null)[]>([]);
  const [loading, setLoading] = useState(true);
  const preview = usePurchaseOrderLinesPreview();
  const request = preview.mutateAsync;
  const key = JSON.stringify(values);
  useEffect(() => {
    let current = true;
    const all = values as PurchaseOrderFormValues;
    setLoading(true);
    Promise.all(
      (all.lines ?? []).map((line) =>
        request({ data: JSON.parse(JSON.stringify(toPurchaseOrderPayload({ ...all, lines: [line] }))) })
          .then((result) => (result.status === 200 ? result.data.data : null))
          .catch(() => null),
      ),
    ).then((rows) => {
      if (!current) return;
      setResolved(rows);
      setLoading(false);
    });
    return () => {
      current = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, request]);
  return { resolved, loading };
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0 space-y-0.5">
      <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="text-sm">{children}</dd>
    </div>
  );
}

/**
 * The full review: every header fact, every line with what the server made of it, the totals, and anything that will stop
 * the order being saved. It is the same information as the side column, in full — the side column steps aside while this
 * is showing, so there is one summary, not two.
 */
function ReviewSummary({
  control,
  supplierOptions,
  siteOptions,
  currencyOptions,
  itemOptions,
  uomOptions,
  paymentTermsDays,
  leadTimeDays,
}: SummaryProps) {
  const values = useWatch({ control });
  const { resolved, loading } = useResolvedLines(values);
  const { view, setView } = useLinesViewPreference("po-review-lines");
  const label = (options: Option[], value?: string) =>
    value ? options.find((option) => option.value === value)?.label : undefined;
  const currencyLabel = label(currencyOptions, values.currency_id);
  const currencyCode = currencyLabel?.split(" — ")[0] ?? "";
  const lines = useMemo(() => values.lines ?? [], [values.lines]);
  const amounts = lines.map((line) => lineAmounts(line ?? {}));
  const complete = amounts.filter((amount): amount is NonNullable<typeof amount> => amount !== null);
  const sum = (pick: (amount: (typeof complete)[number]) => bigint) =>
    complete.reduce((total, amount) => total + pick(amount), 0n);
  const money = (value: bigint) => `${currencyCode ? `${currencyCode} ` : ""}${formatScaled(value)}`;
  const uomCode = (value?: string) => label(uomOptions, value)?.split(" — ")[0];

  const problems: string[] = [];
  lines.forEach((line, index) => {
    const n = index + 1;
    if (!line?.item_id) problems.push(`Line ${n} has no item.`);
    const row = resolved[index];
    if (row?.ordering_terms_violated && !line?.ordering_exception_reason?.trim())
      problems.push(`Line ${n} is outside the supplier's minimum or multiple and needs a reason.`);
    if (amounts[index] === null && line?.item_id) problems.push(`Line ${n} is not fully priced yet.`);
  });

  return (
    <div className="col-span-full space-y-5">
      {problems.length > 0 && (
        <div role="alert" className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
          <p className="font-medium">Check before saving</p>
          <ul className="ml-4 list-disc">
            {problems.map((problem) => (
              <li key={problem}>{problem}</li>
            ))}
          </ul>
        </div>
      )}

      <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
        <Fact label="Supplier">{label(supplierOptions, values.supplier_account_id) ?? NOT_SET}</Fact>
        <Fact label="Supplier site">{label(siteOptions, values.supplier_site_id) ?? "The supplier's default"}</Fact>
        <Fact label="Currency">{currencyLabel ?? NOT_SET}</Fact>
        <Fact label="Order date">{values.order_date || NOT_SET}</Fact>
        <Fact label="Expected delivery">{values.expected_delivery_date || NOT_SET}</Fact>
        <Fact label="Exchange rate">
          {values.fx_rate}
          <span className="block text-xs text-muted-foreground">
            {[values.fx_policy, values.fx_rate_date, values.fx_rate_type, values.fx_rate_source].filter(Boolean).join(" · ")}
          </span>
        </Fact>
        {paymentTermsDays !== undefined && <Fact label="Payment terms">{paymentTermsDays} days</Fact>}
        {leadTimeDays !== undefined && leadTimeDays !== null && <Fact label="Supplier lead time">{leadTimeDays} days</Fact>}
        {values.replaces_purchase_order_id && <Fact label="Replaces order">#{values.replaces_purchase_order_id}</Fact>}
        {values.notes?.trim() && (
          <div className="sm:col-span-2 lg:col-span-3">
            <Fact label="Notes">
              <span className="whitespace-pre-line">{values.notes}</span>
            </Fact>
          </div>
        )}
      </dl>

      <div className="space-y-2">
        <div className="flex items-center justify-between gap-3">
          <h3 className="text-sm font-semibold">Lines ({lines.length})</h3>
          <DataTableViewToggle value={view} onChange={setView} />
        </div>
        {view === "cards" ? (
          <ol className="grid gap-3 @lg:grid-cols-2">
            {lines.map((line, index) => {
              const row = resolved[index];
              const amount = amounts[index];
              const stat = (name: string, value: ReactNode) => (
                <div className="min-w-0">
                  <dt className="text-xs text-muted-foreground">{name}</dt>
                  <dd className="text-sm tabular-nums">{value}</dd>
                </div>
              );
              return (
                <li key={index} className="space-y-3 rounded-lg border border-border bg-card p-3">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="text-xs text-muted-foreground">Line {index + 1}</p>
                      <p className="font-medium">
                        {label(itemOptions, line?.item_id) ?? <span className="font-normal text-muted-foreground">Item not chosen</span>}
                      </p>
                      {line?.description && label(itemOptions, line?.item_id)?.indexOf(line.description) === -1 && (
                        <p className="text-xs text-muted-foreground">{line.description}</p>
                      )}
                    </div>
                    <p className="shrink-0 text-right text-sm font-semibold tabular-nums">{amount ? formatScaled(amount.total) : "—"}</p>
                  </div>
                  <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
                    {stat("Ordered", `${line?.ordered_qty} ${row?.order_unit_label ?? uomCode(line?.purchase_uom_id) ?? ""}`)}
                    {stat("Stock quantity", row ? row.ordered_stock_qty : loading ? "…" : "—")}
                    {stat("Price", `${line?.unit_price} per ${row?.price_quantity ?? line?.price_quantity ?? "1"} ${row?.price_unit_label ?? uomCode(line?.purchase_uom_id) ?? ""}`)}
                    {(parseFloat(line?.discount_amount ?? "0") || 0) > 0 && stat("Discount", `−${line?.discount_amount}`)}
                    {(parseFloat(line?.tax_amount ?? "0") || 0) > 0 && stat("Tax", line?.tax_amount)}
                    {line?.expected_delivery_date && stat("Due", line.expected_delivery_date)}
                  </dl>
                  {(row?.package_name || line?.qa_required || line?.ordering_exception_reason?.trim()) && (
                    <p className="text-xs text-muted-foreground">
                      {[
                        row?.package_name ? `Package: ${row.package_name}` : null,
                        line?.qa_required ? "QA check on receipt" : null,
                        line?.ordering_exception_reason?.trim() ? `Quantity exception: ${line.ordering_exception_reason}` : null,
                      ]
                        .filter(Boolean)
                        .join(" · ")}
                    </p>
                  )}
                </li>
              );
            })}
          </ol>
        ) : (
        <div className="overflow-x-auto rounded-md border border-border bg-card">
          <table className="w-full min-w-[56rem] text-sm">
            <thead className="border-b border-border text-left text-xs text-muted-foreground">
              <tr>
                <th className="px-3 py-2 font-medium">Item</th>
                <th className="px-3 py-2 font-medium">Ordered</th>
                <th className="px-3 py-2 font-medium">Stock quantity</th>
                <th className="px-3 py-2 font-medium">Price</th>
                <th className="px-3 py-2 text-right font-medium">Discount</th>
                <th className="px-3 py-2 text-right font-medium">Tax</th>
                <th className="px-3 py-2 text-right font-medium">Line total</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {lines.map((line, index) => {
                const row = resolved[index];
                const amount = amounts[index];
                return (
                  <tr key={index} className="align-top">
                    <td className="px-3 py-2">
                      <div className="font-medium">
                        {label(itemOptions, line?.item_id) ?? <span className="font-normal text-muted-foreground">Item not chosen</span>}
                      </div>
                      {line?.description && label(itemOptions, line?.item_id)?.indexOf(line.description) === -1 && (
                        <div className="text-xs text-muted-foreground">{line.description}</div>
                      )}
                      <div className="mt-0.5 flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                        {row?.package_name && <span>Package: {row.package_name}</span>}
                        {row?.supplier_item_code && <span>Supplier code {row.supplier_item_code}</span>}
                        {line?.expected_delivery_date && <span>Due {line.expected_delivery_date}</span>}
                        {line?.qa_required && <span>QA check on receipt</span>}
                      </div>
                      {line?.ordering_exception_reason?.trim() && (
                        <div className="text-xs text-muted-foreground">Quantity exception: {line.ordering_exception_reason}</div>
                      )}
                    </td>
                    <td className="px-3 py-2 tabular-nums">
                      {line?.ordered_qty} {row?.order_unit_label ?? uomCode(line?.purchase_uom_id)}
                    </td>
                    <td className="px-3 py-2 tabular-nums">
                      {row ? (
                        <>
                          {row.ordered_stock_qty}
                          <span className="block text-xs text-muted-foreground">factor {row.uom_factor}</span>
                        </>
                      ) : loading ? (
                        <span className="text-muted-foreground">…</span>
                      ) : (
                        "—"
                      )}
                    </td>
                    <td className="px-3 py-2 tabular-nums">
                      {line?.unit_price}
                      <span className="block text-xs text-muted-foreground">
                        per {row?.price_quantity ?? line?.price_quantity ?? "1"} {row?.price_unit_label ?? uomCode(line?.purchase_uom_id)}
                      </span>
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums">
                      {(parseFloat(line?.discount_amount ?? "0") || 0) > 0 ? `−${line?.discount_amount}` : "—"}
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums">
                      {(parseFloat(line?.tax_amount ?? "0") || 0) > 0 ? line?.tax_amount : "—"}
                    </td>
                    <td className="px-3 py-2 text-right font-medium tabular-nums">{amount ? formatScaled(amount.total) : "—"}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        )}
      </div>

      {complete.length > 0 && (
        <div className="ml-auto max-w-sm space-y-2">
          <dl className="space-y-1 text-sm">
            <TotalRow label="Subtotal" value={money(sum((amount) => amount.gross))} />
            {sum((amount) => amount.discount) > 0n && (
              <TotalRow label="Discounts" value={`−${money(sum((amount) => amount.discount))}`} />
            )}
            {sum((amount) => amount.tax) > 0n && <TotalRow label="Tax" value={money(sum((amount) => amount.tax))} />}
            <TotalRow label="Estimated total" value={money(sum((amount) => amount.total))} strong />
          </dl>
          <p className="text-xs text-muted-foreground">
            An estimate from what is typed. The saved amounts are worked out by the server.
          </p>
        </div>
      )}
    </div>
  );
}

/** One summary of the purchase order being entered: compact in the side column while typing, in full at the review step. */
export function PurchaseOrderSummary({ variant, ...props }: SummaryProps & { variant: "aside" | "review" }) {
  return variant === "review" ? <ReviewSummary {...props} /> : <AsideSummary {...props} />;
}
