import { useEffect, useRef, useState } from "react";
import {
  useItemPackagingsIndex,
  useSupplierOffersIndex,
  useUomsIndex,
  usePurchaseOrderLinesPreview,
  type PurchaseOrderLineResource,
} from "@erp/core-api";
import type { UseFormReturn, FieldPath } from "react-hook-form";
import {
  type PurchaseOrderFormValues,
  toPurchaseOrderPayload,
} from "./purchase-order-form";
import { getApiErrorMessage } from "../../lib/api-errors";
import { compareDecimal, describeSubmitError, formatDecimal } from "../../lib/decimal";
import { DecimalInput } from "../../components/ui/decimal-input";
import { Button } from "../../components/ui/button";
import { Boxes, Info } from "lucide-react";
import { FieldHint } from "../../components/ui/field-hint";

type LineKey =
  | "item_packaging_id"
  | "supplier_offer_id"
  | "purchase_uom_id"
  | "price_uom_id"
  | "price_quantity"
  | "unit_price"
  | "ordering_exception_reason"
  | "priced_qty";

/**
 * Everything a purchase-order line needs to know about how it is bought and priced: the packages and supplier offers that
 * exist for its item, the server's own working-out of the line (stock quantity, conversion, price basis, total), and the
 * defaulting rules (a preferred supplier offer is applied when the item and supplier are first chosen).
 *
 * It runs for every line whether or not its advanced options are open, because the estimate on screen and the saved amounts
 * depend on what it resolves (`priced_qty`). The panels below only render what it returns.
 */
export function usePurchaseLinePricing(
  form: UseFormReturn<PurchaseOrderFormValues>,
  index: number,
  stockUomId?: number,
) {
  const values = form.watch();
  const line = values.lines[index]!;
  const itemId = Number(line.item_id) || 0;
  const supplierId = Number(values.supplier_account_id) || 0;
  const [packagePage, setPackagePage] = useState(1);
  const [offerPage, setOfferPage] = useState(1);
  const packages = useItemPackagingsIndex(
    {
      "filter[item_id]": itemId,
      "filter[active]": "true",
      per_page: 100,
      page: packagePage,
    },
    { query: { enabled: itemId > 0 } },
  );
  const offers = useSupplierOffersIndex(
    {
      "filter[item_id]": itemId,
      "filter[supplier_account_id]": supplierId,
      "filter[active]": "true",
      per_page: 100,
      page: offerPage,
    },
    { query: { enabled: itemId > 0 && supplierId > 0 } },
  );
  const units = useUomsIndex({ per_page: 100 });
  const packageRows =
    packages.data?.status === 200 ? packages.data.data.data : [];
  const offerRows = offers.data?.status === 200 ? offers.data.data.data : [];
  const unitRows = units.data?.status === 200 ? units.data.data.data : [];
  const preview = usePurchaseOrderLinesPreview();
  const [resolved, setResolved] = useState<PurchaseOrderLineResource | null>(
    null,
  );
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const set = (key: LineKey, value: string) =>
    form.setValue(
      ("lines." + index + "." + key) as FieldPath<PurchaseOrderFormValues>,
      value,
      { shouldDirty: true },
    );
  const applyOffer = (id: string) => {
    set("supplier_offer_id", id);
    const o = offerRows.find((o) => o.id === Number(id));
    if (o) {
      set(
        "item_packaging_id",
        o.item_packaging_id ? String(o.item_packaging_id) : "",
      );
      set("purchase_uom_id", String(o.purchase_uom_id));
      set("price_uom_id", o.price_uom_id ? String(o.price_uom_id) : "");
      set("price_quantity", o.price_quantity ?? "1");
      set("unit_price", o.unit_price ?? "0");
    }
  };
  const choosePackage = (v: string) => {
    set("item_packaging_id", v);
    set("supplier_offer_id", "");
    if (v) {
      const p = packageRows.find((p) => p.id === Number(v));
      if (p) {
        const offer = offerRows.find((o) => o.item_packaging_id === p.id);
        if (offer) set("purchase_uom_id", String(offer.purchase_uom_id));
        else {
          const stock = stockUomId ?? resolved?.stock_uom_id;
          if (stock) set("purchase_uom_id", String(stock));
        }
      }
    }
  };
  const defaultAttempted = useRef("");
  useEffect(() => {
    const key = itemId + ":" + supplierId;
    if (
      offers.data?.status !== 200 ||
      !itemId ||
      !supplierId ||
      defaultAttempted.current === key
    )
      return;
    defaultAttempted.current = key;
    if (line.supplier_offer_id) return;
    const preferred = offerRows.find(
      (o) =>
        o.preferred &&
        o.currency_id === Number(values.currency_id) &&
        (!o.valid_from || o.valid_from <= values.order_date) &&
        (!o.valid_to || o.valid_to >= values.order_date),
    );
    if (preferred) applyOffer(String(preferred.id));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [offers.data, itemId, supplierId, values.currency_id]);
  const previous = useRef({ itemId, supplierId });
  useEffect(() => {
    if (
      previous.current.itemId !== itemId ||
      previous.current.supplierId !== supplierId
    ) {
      set("supplier_offer_id", "");
      if (previous.current.itemId !== itemId) {
        set("item_packaging_id", "");
        set("price_uom_id", "");
        setPackagePage(1);
      }
      setOfferPage(1);
      previous.current = { itemId, supplierId };
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [itemId, supplierId]);
  const payload = toPurchaseOrderPayload({ ...values, lines: [line] });
  const payloadText = JSON.stringify(payload);
  const request = preview.mutateAsync;
  useEffect(() => {
    let current = true;
    if (!itemId || !supplierId || !values.currency_id || !line.purchase_uom_id) {
      setResolved(null);
      setError(null);
      setPending(false);
      set("priced_qty", "");
      return;
    }
    // The last answer stays on screen while the next is fetched, so nothing blinks as a person types. A plain line (no package,
    // no separate price unit) is priced from its own quantity, so a stale server figure is dropped for it at once.
    if (!line.item_packaging_id && !line.price_uom_id) set("priced_qty", "");
    setPending(true);
    const timer = setTimeout(() => {
      request({ data: JSON.parse(payloadText) })
        .then((result) => {
          if (current && result.status === 200) {
            setResolved(result.data.data);
            setError(null);
            set("priced_qty", result.data.data.priced_qty ?? "");
          }
        })
        .catch((e) => {
          if (current) {
            setResolved(null);
            setError(describeSubmitError(e));
          }
        })
        .finally(() => {
          if (current) setPending(false);
        });
    }, 250);
    return () => {
      current = false;
      clearTimeout(timer);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [payloadText, request]);

  return {
    packages,
    offers,
    packageRows,
    offerRows,
    unitRows,
    packagePage,
    offerPage,
    setPackagePage,
    setOfferPage,
    resolved,
    error,
    pending,
    applyOffer,
    choosePackage,
    set,
  };
}

export type PurchaseLinePricing = ReturnType<typeof usePurchaseLinePricing>;

function LabelledSelect({
  label,
  hint,
  value,
  onChange,
  options,
  empty,
}: {
  label: string;
  hint: string;
  value: string | undefined;
  onChange: (value: string) => void;
  options: { value: string; label: string }[];
  empty: string;
}) {
  return (
    <div className="space-y-1 text-sm">
      <div className="flex items-center gap-1.5">
        <span className="font-medium">{label}</span>
        <FieldHint label={label}>{hint}</FieldHint>
      </div>
      <select
        aria-label={label}
        className="h-10 w-full rounded-md border bg-background px-3 text-sm"
        value={value ?? ""}
        onChange={(e) => onChange(e.target.value)}
      >
        <option value="">{empty}</option>
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </div>
  );
}

/** "Package or supplier offer" — shown only for what actually exists for this item and supplier. */
export function PackageAndOfferFields({
  form,
  index,
  pricing,
}: {
  form: UseFormReturn<PurchaseOrderFormValues>;
  index: number;
  pricing: PurchaseLinePricing;
}) {
  const values = form.watch();
  const line = values.lines[index]!;
  const { packageRows, offerRows } = pricing;
  const offersInCurrency = offerRows.filter(
    (o) => o.currency_id === Number(values.currency_id),
  );
  const nothing = packageRows.length === 0 && offersInCurrency.length === 0;
  return (
    <div className="space-y-2">
      {nothing ? (
        <p className="flex items-start gap-2 text-sm text-muted-foreground">
          <Info className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
          Ordered loose, and this supplier has no standing offer for the item in this currency — the price is entered by hand.
        </p>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          {packageRows.length > 0 && (
            <LabelledSelect
              label="Package or loose"
              hint="Order in a named package (a drum, a box of 20) instead of loose units. The quantity then counts packages, and the stock quantity is worked out from what the package holds."
              value={line.item_packaging_id}
              onChange={pricing.choosePackage}
              options={packageRows.map((p) => ({
                value: String(p.id),
                label: p.name + " — " + p.stock_quantity + " stock units",
              }))}
              empty="Loose / alternate unit"
            />
          )}
          {offersInCurrency.length > 0 && (
            <LabelledSelect
              label="Supplier offer"
              hint="A standing price and terms this supplier has agreed for the item (price, minimum order, order multiple). Choosing one fills the unit, package and price; 'Manual agreed terms' means you enter them yourself."
              value={line.supplier_offer_id}
              onChange={pricing.applyOffer}
              options={offersInCurrency.map((o) => ({
                value: String(o.id),
                label:
                  (o.supplier_item_code ?? "Offer " + o.id) +
                  " · " +
                  (pricing.packageRows.find((p) => p.id === o.item_packaging_id)
                    ?.name ?? "Loose") +
                  " · " +
                  o.unit_price,
              }))}
              empty="Manual agreed terms"
            />
          )}
        </div>
      )}
      {pricing.packages.isError && (
        <p role="alert" className="text-sm text-destructive">
          {getApiErrorMessage(pricing.packages.error)}
        </p>
      )}
      {pricing.offers.isError && (
        <p role="alert" className="text-sm text-destructive">
          {getApiErrorMessage(pricing.offers.error)}
        </p>
      )}
      <div className="flex flex-wrap gap-2">
        {pricing.packagePage > 1 && (
          <Button
            variant="outline"
            size="sm"
            type="button"
            onClick={() => pricing.setPackagePage(pricing.packagePage - 1)}
          >
            Previous packages
          </Button>
        )}
        {packageRows.length === 100 && (
          <Button
            variant="outline"
            size="sm"
            type="button"
            onClick={() => pricing.setPackagePage(pricing.packagePage + 1)}
          >
            More packages
          </Button>
        )}
        {pricing.offerPage > 1 && (
          <Button
            variant="outline"
            size="sm"
            type="button"
            onClick={() => pricing.setOfferPage(pricing.offerPage - 1)}
          >
            Previous offers
          </Button>
        )}
        {offerRows.length === 100 && (
          <Button
            variant="outline"
            size="sm"
            type="button"
            onClick={() => pricing.setOfferPage(pricing.offerPage + 1)}
          >
            More offers
          </Button>
        )}
      </div>
    </div>
  );
}

/** "Price basis": the price is for N of some unit — the second half of "unit price per …". */
export function PriceBasisFields({
  form,
  index,
  pricing,
}: {
  form: UseFormReturn<PurchaseOrderFormValues>;
  index: number;
  pricing: PurchaseLinePricing;
}) {
  const line = form.watch().lines[index]!;
  return (
    <div className="space-y-1 text-sm">
      <div className="flex items-center gap-1.5">
        <span className="font-medium">The price is quoted per</span>
        <FieldHint label="the price basis">
          Normally the unit price is for one unit of what you order. If the
          supplier quotes differently — per 100 pieces, or per kilogram while you
          order drums — say so here, and the unit price is read as the price for
          that quantity of that unit.
        </FieldHint>
      </div>
      <div className="grid grid-cols-[8rem_1fr] gap-3">
        <DecimalInput
          aria-label="Price per quantity"
          rule={{ spec: "stockLedger", sign: "positive" }}
          value={line.price_quantity ?? "1"}
          onChange={(value) => pricing.set("price_quantity", value)}
        />
        <select
          aria-label="Price basis"
          className="h-10 w-full rounded-md border bg-background px-3 text-sm"
          value={line.price_uom_id ?? ""}
          onChange={(e) => pricing.set("price_uom_id", e.target.value)}
        >
          <option value="">Same as the order unit</option>
          {pricing.unitRows.map((u) => (
            <option key={u.id} value={String(u.id)}>
              {u.code}
            </option>
          ))}
        </select>
      </div>
    </div>
  );
}

/**
 * One quiet line, shown only when the server's working-out says something the fields do not already: the quantity in stock units
 * when the order unit or a package converts to it, and the package used. A plain line (loose, in the stock unit) shows nothing —
 * everything about it is already in the fields above. It dims while a new answer is being fetched instead of disappearing.
 */
export function LineStockNote({
  pricing,
  stockUnit,
}: {
  pricing: PurchaseLinePricing;
  stockUnit?: string;
}) {
  const { resolved, error, pending } = pricing;
  if (error)
    return (
      <p role="alert" className="text-sm text-destructive">
        {error}
      </p>
    );
  if (!resolved) return null;
  const converts = compareDecimal(resolved.ordered_stock_qty, resolved.ordered_qty) !== 0;
  if (!converts && !resolved.package_name) return null;
  return (
    <p
      aria-live="polite"
      className={
        "flex items-center gap-2 text-sm text-muted-foreground transition-opacity " +
        (pending ? "opacity-50" : "opacity-100")
      }
    >
      <Boxes className="size-4 shrink-0" aria-hidden="true" />
      <span>
        = <strong className="font-medium text-foreground tabular-nums">{formatDecimal(resolved.ordered_stock_qty)}</strong> {stockUnit ?? "stock units"} in stock
        {resolved.package_name ? ` · ${resolved.package_name}` : ""}
        {" "}(1 {resolved.order_unit_label} = {formatDecimal(resolved.uom_factor)} {stockUnit ?? ""})
      </span>
    </p>
  );
}
