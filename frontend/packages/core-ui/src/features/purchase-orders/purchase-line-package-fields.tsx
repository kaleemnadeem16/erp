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
import { Input } from "../../components/ui/input";
import { Button } from "../../components/ui/button";

export function PurchaseLinePackageFields({
  form,
  index,
  stockUomId,
}: {
  form: UseFormReturn<PurchaseOrderFormValues>;
  index: number;
  stockUomId?: number;
}) {
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
  const set = (
    key:
      | "item_packaging_id"
      | "supplier_offer_id"
      | "purchase_uom_id"
      | "price_uom_id"
      | "price_quantity"
      | "unit_price"
      | "ordering_exception_reason"
      | "priced_qty",
    value: string,
  ) =>
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
  }, [itemId, supplierId]);
  const payload = toPurchaseOrderPayload({ ...values, lines: [line] });
  const payloadText = JSON.stringify(payload);
  const request = preview.mutateAsync;
  useEffect(() => {
    let current = true;
    setResolved(null);
    setError(null);
    set("priced_qty", "");
    if (!itemId || !supplierId || !values.currency_id || !line.purchase_uom_id)
      return;
    const timer = setTimeout(() => {
      request({ data: JSON.parse(payloadText) })
        .then((result) => {
          if (current && result.status === 200) {
            setResolved(result.data.data);
            set("priced_qty", result.data.data.priced_qty ?? "");
          }
        })
        .catch((e) => {
          if (current) setError(getApiErrorMessage(e));
        });
    }, 250);
    return () => {
      current = false;
      clearTimeout(timer);
    };
  }, [payloadText, request]);
  const select = (
    label: string,
    value: string | undefined,
    change: (s: string) => void,
    options: { value: string; label: string }[],
    empty: string,
  ) => (
    <label className="space-y-1 text-sm">
      {label}
      <select
        aria-label={label}
        className="h-10 w-full rounded-md border bg-background px-3"
        value={value ?? ""}
        onChange={(e) => change(e.target.value)}
      >
        <option value="">{empty}</option>
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </label>
  );
  return (
    <div className="space-y-3 rounded-md border p-3">
      <div className="grid gap-3 sm:grid-cols-3">
        {select(
          "Package or loose",
          line.item_packaging_id,
          (v) => {
            set("item_packaging_id", v);
            set("supplier_offer_id", "");
            if (v) {
              const p = packageRows.find((p) => p.id === Number(v));
              if (p) {
                const offer = offerRows.find(
                  (o) => o.item_packaging_id === p.id,
                );
                if (offer)
                  set("purchase_uom_id", String(offer.purchase_uom_id));
                else {
                  const stock = stockUomId ?? resolved?.stock_uom_id;
                  if (stock) set("purchase_uom_id", String(stock));
                }
              }
            }
          },
          packageRows.map((p) => ({
            value: String(p.id),
            label: p.name + " — " + p.stock_quantity + " stock units",
          })),
          "Loose / alternate unit",
        )}
        {select(
          "Supplier offer",
          line.supplier_offer_id,
          applyOffer,
          offerRows
            .filter((o) => o.currency_id === Number(values.currency_id))
            .map((o) => ({
              value: String(o.id),
              label:
                (o.supplier_item_code ?? "Offer " + o.id) +
                " · " +
                (packageRows.find((p) => p.id === o.item_packaging_id)?.name ??
                  "Loose") +
                " · " +
                o.unit_price,
            })),
          "Manual agreed terms",
        )}
        {select(
          "Price basis",
          line.price_uom_id,
          (v) => set("price_uom_id", v),
          unitRows.map((u) => ({ value: String(u.id), label: u.code })),
          "Per selected package / order unit",
        )}
        <label className="space-y-1 text-sm">
          Price per quantity
          <Input
            value={line.price_quantity ?? "1"}
            onChange={(e) => set("price_quantity", e.target.value)}
          />
        </label>
        <label className="space-y-1 text-sm sm:col-span-2">
          Reason for supplier quantity exception
          <Input
            value={line.ordering_exception_reason ?? ""}
            onChange={(e) => set("ordering_exception_reason", e.target.value)}
          />
        </label>
      </div>
      {packages.isError && (
        <p role="alert">{getApiErrorMessage(packages.error)}</p>
      )}
      {offers.isError && <p role="alert">{getApiErrorMessage(offers.error)}</p>}
      <div className="flex flex-wrap gap-2">
        {packagePage > 1 && (
          <Button
            variant="outline"
            type="button"
            onClick={() => setPackagePage(packagePage - 1)}
          >
            Previous packages
          </Button>
        )}
        {packageRows.length === 100 && (
          <Button
            variant="outline"
            type="button"
            onClick={() => setPackagePage(packagePage + 1)}
          >
            More packages
          </Button>
        )}
        {offerPage > 1 && (
          <Button
            variant="outline"
            type="button"
            onClick={() => setOfferPage(offerPage - 1)}
          >
            Previous offers
          </Button>
        )}
        {offerRows.length === 100 && (
          <Button
            variant="outline"
            type="button"
            onClick={() => setOfferPage(offerPage + 1)}
          >
            More offers
          </Button>
        )}
      </div>
      {resolved ? (
        <p className="text-sm">
          Order: {resolved.ordered_qty} {resolved.order_unit_label} · Factor:{" "}
          {resolved.uom_factor} · Stock quantity: {resolved.ordered_stock_qty} ·
          Price: {resolved.unit_price} per {resolved.price_quantity}{" "}
          {resolved.price_unit_label} · Line total: {resolved.line_amount}
          {resolved.minimum_order_qty &&
            " · Minimum: " + resolved.minimum_order_qty}
          {resolved.order_multiple && " · Multiple: " + resolved.order_multiple}
        </p>
      ) : error ? (
        <p role="alert" className="text-destructive">
          {error}
        </p>
      ) : (
        <p className="text-sm text-muted-foreground">
          Complete supplier, item, unit and price to preview stock quantity and
          amount.
        </p>
      )}
      {resolved?.ordering_terms_violated && (
        <p role="alert" className="text-amber-700">
          Quantity does not meet the supplier minimum or multiple. A reason is
          required to save.
        </p>
      )}
      <p className="text-xs text-muted-foreground">
        If the quantity is outside the supplier minimum/multiple, enter a reason
        to continue. Price and stock quantities are calculated by the server.
      </p>
    </div>
  );
}
