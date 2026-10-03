import { useEffect, useMemo, useState } from "react";
import type { FieldPath, UseFormReturn } from "react-hook-form";
import { Trash2 } from "lucide-react";
import {
  useItemQuantityUnitsIndex,
  type ItemResource,
  type UomResource,
} from "@erp/core-api";

import {
  AdvancedOptionsMenu,
  type AdvancedOption,
} from "../../components/ui/advanced-options-menu";
import { Button } from "../../components/ui/button";
import { Checkbox } from "../../components/ui/checkbox";
import { Combobox } from "../../components/ui/combobox";
import { DateField } from "../../components/ui/date-field";
import { FormLabelWithHint } from "../../components/ui/field-hint";
import {
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "../../components/ui/form";
import { Input } from "../../components/ui/input";
import type { LookupState } from "../../lib/api-errors";
import {
  LineReadout,
  PackageAndOfferFields,
  PriceBasisFields,
  usePurchaseLinePricing,
} from "./purchase-line-package-fields";
import type { PurchaseOrderFormValues } from "./purchase-order-form";

interface Option {
  value: string;
  label: string;
}

type Line = PurchaseOrderFormValues["lines"][number];
type OptionId = "pack" | "basis" | "adjust" | "date" | "qa";

const ZERO = "0.0000";

/** Which advanced groups a line already uses — they open by themselves so nothing set is ever hidden. */
function usedGroups(line: Line): OptionId[] {
  const used: OptionId[] = [];
  if (line.item_packaging_id || line.supplier_offer_id) used.push("pack");
  if (line.price_uom_id || (line.price_quantity && line.price_quantity !== "1" && line.price_quantity !== "1.0000"))
    used.push("basis");
  if (Number(line.discount_amount) > 0 || Number(line.tax_amount) > 0) used.push("adjust");
  if (line.expected_delivery_date) used.push("date");
  if (line.qa_required) used.push("qa");
  return used;
}

/**
 * One line of a purchase order. The everyday fields — item, description, quantity, unit and price — are always shown; what
 * has a sensible default (package or supplier offer, price basis, discount and tax, a line's own delivery date, a quality
 * check) sits behind the line's own "Advanced options" menu and opens by itself when the line already uses it. The server's
 * working-out of the line (stock quantity, conversion, total) is shown under the fields whether or not anything is open.
 */
export function PurchaseOrderLineFields({
  form,
  index,
  items,
  itemOptions,
  itemLookup,
  uoms,
  uomLookup,
  purchaseUomIdByItem,
  canRemove,
  onRemove,
}: {
  form: UseFormReturn<PurchaseOrderFormValues>;
  index: number;
  items: ItemResource[];
  itemOptions: Option[];
  itemLookup: LookupState;
  uoms: UomResource[];
  uomLookup: LookupState;
  purchaseUomIdByItem: Map<number, number>;
  canRemove: boolean;
  onRemove: () => void;
}) {
  const line = form.watch(`lines.${index}` as FieldPath<PurchaseOrderFormValues>) as unknown as Line;
  const itemId = Number(line.item_id) || 0;
  const item = items.find((candidate) => candidate.id === itemId);
  const pricing = usePurchaseLinePricing(form, index, item?.stock_uom_id);
  const [opened, setOpened] = useState<Set<OptionId>>(() => new Set(usedGroups(line)));

  // A group opens by itself when something fills it (a preferred offer, a saved line) — never leave a set value hidden.
  const used = usedGroups(line).join(",");
  useEffect(() => {
    const missing = usedGroups(line).filter((id) => !opened.has(id));
    if (missing.length > 0) setOpened((current) => new Set([...current, ...missing]));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [used]);
  // …and one with a problem the server reported must be showing.
  const lineErrors = form.formState.errors.lines?.[index];
  useEffect(() => {
    const need: OptionId[] = [];
    if (lineErrors?.item_packaging_id || lineErrors?.supplier_offer_id || lineErrors?.ordering_exception_reason) need.push("pack");
    if (lineErrors?.price_quantity || lineErrors?.price_uom_id) need.push("basis");
    if (lineErrors?.discount_amount || lineErrors?.tax_amount) need.push("adjust");
    if (lineErrors?.expected_delivery_date) need.push("date");
    if (need.some((id) => !opened.has(id))) setOpened((current) => new Set([...current, ...need]));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lineErrors]);

  const setLine = (key: keyof Line, value: string | boolean) =>
    form.setValue(`lines.${index}.${key}` as FieldPath<PurchaseOrderFormValues>, value as never, {
      shouldDirty: true,
    });

  const toggle = (id: OptionId, on: boolean, reset: () => void) => {
    if (!on) reset();
    setOpened((current) => {
      const next = new Set(current);
      if (on) next.add(id);
      else next.delete(id);
      return next;
    });
  };
  const options: AdvancedOption[] = [
    {
      id: "pack",
      label: "Package or supplier offer",
      checked: opened.has("pack"),
      onCheckedChange: (on) =>
        toggle("pack", on, () => {
          setLine("item_packaging_id", "");
          setLine("supplier_offer_id", "");
        }),
    },
    {
      id: "basis",
      label: "Price basis (price per 100, per kg…)",
      checked: opened.has("basis"),
      onCheckedChange: (on) =>
        toggle("basis", on, () => {
          setLine("price_uom_id", "");
          setLine("price_quantity", "1");
        }),
    },
    {
      id: "adjust",
      label: "Line discount and tax",
      checked: opened.has("adjust"),
      onCheckedChange: (on) =>
        toggle("adjust", on, () => {
          setLine("discount_amount", ZERO);
          setLine("tax_amount", ZERO);
        }),
    },
    {
      id: "date",
      label: "A delivery date for this line",
      checked: opened.has("date"),
      onCheckedChange: (on) => toggle("date", on, () => setLine("expected_delivery_date", "")),
    },
    {
      id: "qa",
      label: "Quality check on receipt",
      checked: opened.has("qa"),
      onCheckedChange: (on) => toggle("qa", on, () => setLine("qa_required", false)),
    },
  ];

  // The units this item can really be bought in: its stock unit's dimension, plus units given a conversion for this item
  // (a drum, a box). Anything else could not be converted, and the server would refuse it.
  const quantityUnits = useItemQuantityUnitsIndex(
    { "filter[item_id]": itemId, per_page: 100 },
    { query: { enabled: itemId > 0 } },
  );
  const itemUnitIds = useMemo(
    () => (quantityUnits.data?.status === 200 ? quantityUnits.data.data.data.map((row) => row.uom_id) : []),
    [quantityUnits.data],
  );
  const stockUom = uoms.find((uom) => uom.id === item?.stock_uom_id);
  const unitOptions = useMemo(() => {
    const allowed = (uom: UomResource) =>
      !item || !stockUom || uom.dimension === stockUom.dimension || itemUnitIds.includes(uom.id) || String(uom.id) === line.purchase_uom_id;
    return uoms.filter(allowed).map((uom) => ({ value: String(uom.id), label: `${uom.code} — ${uom.name}` }));
  }, [uoms, item, stockUom, itemUnitIds, line.purchase_uom_id]);

  // "per 1 kg": what the unit price is for, spelled out beside it.
  const unitCode = (id?: string) => uoms.find((uom) => String(uom.id) === id)?.code;
  const packageName = pricing.packageRows.find((p) => String(p.id) === line.item_packaging_id)?.name;
  const priceUnit =
    pricing.resolved?.price_unit_label ?? unitCode(line.price_uom_id) ?? packageName ?? unitCode(line.purchase_uom_id) ?? "";
  const priceQuantity = Number(line.price_quantity) > 0 ? String(Number(line.price_quantity)) : "1";

  const text = (name: "description" | "ordered_qty" | "unit_price", label: string, hint: string, className?: string) => (
    <FormField
      control={form.control}
      name={`lines.${index}.${name}` as FieldPath<PurchaseOrderFormValues>}
      render={({ field }) => (
        <FormItem className={className}>
          <FormLabelWithHint hint={hint}>{label}</FormLabelWithHint>
          <FormControl>
            <Input
              type="text"
              inputMode={name === "description" ? undefined : "decimal"}
              {...field}
              value={String(field.value ?? "")}
            />
          </FormControl>
          {name === "unit_price" && priceUnit && (
            <FormDescription>
              per {priceQuantity} {priceUnit}
            </FormDescription>
          )}
          <FormMessage />
        </FormItem>
      )}
    />
  );

  const money = (name: "discount_amount" | "tax_amount", label: string, description: string) => (
    <FormField
      control={form.control}
      name={`lines.${index}.${name}` as FieldPath<PurchaseOrderFormValues>}
      render={({ field }) => (
        <FormItem>
          <FormLabel>{label}</FormLabel>
          <FormControl>
            <Input type="text" inputMode="decimal" {...field} value={String(field.value ?? "")} />
          </FormControl>
          <FormDescription>{description}</FormDescription>
          <FormMessage />
        </FormItem>
      )}
    />
  );

  return (
    <fieldset
      aria-label={`Line ${index + 1}`}
      // `[&>*]:min-w-0`: a grid item's default `min-width: auto` sizes its track to its content's intrinsic width, so a long
      // selected item name in the Combobox would widen this whole row instead of truncating.
      className="grid min-w-0 gap-3 rounded-lg border bg-panel/60 p-4 @lg:grid-cols-2 @3xl:grid-cols-4 [&>*]:min-w-0"
    >
      <div className="col-span-full flex items-center justify-between gap-2">
        <h4 className="text-sm font-medium">Line {index + 1}</h4>
        <div className="flex items-center gap-2">
          <AdvancedOptionsMenu options={options} label="Line options" ariaLabel={`Options for line ${index + 1}`} />
          <Button type="button" variant="outline" size="sm" onClick={onRemove} disabled={!canRemove}>
            <Trash2 className="size-4" />
            Remove
          </Button>
        </div>
      </div>

      <FormField
        control={form.control}
        name={`lines.${index}.item_id`}
        render={({ field }) => (
          <FormItem>
            <FormLabel>Item</FormLabel>
            <FormControl>
              <Combobox
                {...itemLookup}
                ref={field.ref}
                value={field.value}
                onValueChange={(value) => {
                  field.onChange(value);
                  const chosen = items.find((candidate) => candidate.id === Number(value));
                  if (chosen) {
                    form.setValue(`lines.${index}.description`, chosen.name);
                    form.setValue(
                      `lines.${index}.purchase_uom_id`,
                      String(purchaseUomIdByItem.get(chosen.id) ?? chosen.stock_uom_id),
                    );
                  }
                }}
                options={itemOptions}
                placeholder="Select item"
                searchPlaceholder="Search items…"
                emptyText="No items found."
              />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />
      {text(
        "description",
        "Description",
        "What is printed on the order for this line. It starts as the item's name — change it to add the supplier's own wording.",
        "@3xl:col-span-3",
      )}
      {text("ordered_qty", "Quantity", "How many of the unit shown to the right you are ordering. With a package chosen, this counts packages.")}
      <FormField
        control={form.control}
        name={`lines.${index}.purchase_uom_id`}
        render={({ field }) => (
          <FormItem>
            <FormLabelWithHint
              hint="The unit you order in. It starts as the unit this supplier sells the item in, or the item's stock unit. Only units that can be converted for this item are listed; the stock quantity is worked out from the one you pick. It is fixed while a package is chosen."
              hintLabel="Purchase UoM"
            >
              Purchase UoM
            </FormLabelWithHint>
            <FormControl>
              <Combobox
                {...uomLookup}
                ref={field.ref}
                value={field.value}
                onValueChange={field.onChange}
                disabled={!!line.item_packaging_id}
                options={unitOptions}
                placeholder="Select UoM"
                searchPlaceholder="Search units…"
                emptyText="No units found."
              />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />
      {text(
        "unit_price",
        "Unit price",
        "What the supplier charges for one pricing unit — shown under the field as 'per 1 kg'. The pricing unit is the unit you order in, unless the price basis in Advanced options says otherwise (for example per 100 pieces).",
      )}

      {opened.has("pack") && (
        <div className="col-span-full rounded-md border border-border bg-card p-3">
          <PackageAndOfferFields form={form} index={index} pricing={pricing} />
          {(pricing.resolved?.ordering_terms_violated || line.ordering_exception_reason || lineErrors?.ordering_exception_reason) && (
            <FormField
              control={form.control}
              name={`lines.${index}.ordering_exception_reason`}
              render={({ field }) => (
                <FormItem className="mt-3">
                  <FormLabelWithHint hint="The supplier's offer sets a minimum quantity or an order multiple, and this quantity is outside it. Say why you are ordering it anyway — the reason is kept with the order.">
                    Reason for supplier quantity exception
                  </FormLabelWithHint>
                  {pricing.resolved?.ordering_terms_violated && (
                    <p role="alert" className="text-sm text-amber-700">
                      The quantity is outside the supplier's minimum or multiple (
                      {[
                        pricing.resolved.minimum_order_qty ? `minimum ${pricing.resolved.minimum_order_qty}` : null,
                        pricing.resolved.order_multiple ? `multiple ${pricing.resolved.order_multiple}` : null,
                      ]
                        .filter(Boolean)
                        .join(", ")}
                      ). A reason is required to save.
                    </p>
                  )}
                  <FormControl>
                    <Input {...field} value={field.value ?? ""} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          )}
        </div>
      )}
      {opened.has("basis") && (
        <div className="col-span-full rounded-md border border-border bg-card p-3">
          <PriceBasisFields form={form} index={index} pricing={pricing} />
        </div>
      )}
      {opened.has("adjust") && (
        <>
          {money("discount_amount", "Line discount (amount)", "A fixed amount off this whole line — not a percentage, not per unit.")}
          {money("tax_amount", "Line tax (amount)", "A fixed tax amount for this whole line — not a percentage, not per unit.")}
        </>
      )}
      {opened.has("date") && (
        <FormField
          control={form.control}
          name={`lines.${index}.expected_delivery_date`}
          render={({ field }) => (
            <FormItem>
              <FormLabelWithHint hint="Only when this line is due on a different date from the order. Leave it off and the line follows the order's expected delivery.">
                Line expected date (optional)
              </FormLabelWithHint>
              <FormControl>
                <DateField value={String(field.value)} onChange={field.onChange} onBlur={field.onBlur} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
      )}
      {opened.has("qa") && (
        <FormField
          control={form.control}
          name={`lines.${index}.qa_required`}
          render={({ field }) => (
            <FormItem className="flex items-center gap-2 @lg:pt-6">
              <FormControl>
                <Checkbox checked={field.value} onCheckedChange={(checked) => field.onChange(checked === true)} />
              </FormControl>
              <FormLabelWithHint hint="Goods on this line are held for a quality check when they are received, before they count as usable stock.">
                QA required
              </FormLabelWithHint>
              <FormMessage />
            </FormItem>
          )}
        />
      )}

      <div className="col-span-full">
        <LineReadout pricing={pricing} />
      </div>
    </fieldset>
  );
}
