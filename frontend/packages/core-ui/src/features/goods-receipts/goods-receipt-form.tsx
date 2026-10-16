import { useEffect, useMemo, useState } from "react";
import { SupplierCombobox } from "../../components/lookups/entity-comboboxes";
import { useItemsById, useLocationsById } from "../../lib/use-records-by-id";
import { zodResolver } from "@hookform/resolvers/zod";
import { useFieldArray, useForm, type UseFormReturn } from "react-hook-form";
import { z } from "zod";
import { History, RotateCcw } from "lucide-react";
import {
  useItemsIndex,
  useLocationsIndex,
  usePurchaseOrdersIndex,
  useSupplierAccountsIndex,
  useUomsIndex,
  type StoreGoodsReceiptRequest,
  type PurchaseOrderResource,
} from "@erp/core-api";

import { Button } from "../../components/ui/button";
import { Combobox } from "../../components/ui/combobox";
import { DateField } from "../../components/ui/date-field";
import { Input } from "../../components/ui/input";
import { clearDraft, readDraft, writeDraft } from "../../lib/session-draft";
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "../../components/ui/form";
import { DetailSection } from "../../components/detail/detail-page-layout";
import { formatLocationOption } from "../../lib/format-location";
import { lookupState } from "../../lib/api-errors";
import { decimalField } from "../../lib/decimal";
import { DecimalInput } from "../../components/ui/decimal-input";
import { usePurchasingCommercialCost } from "../../hooks/use-purchasing-commercial-cost";

const lineSchema = z.object({
  receipt_line_id: z.string().optional(),
  purchase_order_line_id: z.string().min(1, "Select an order line."),
  location_id: z.string().min(1, "Select a stock location."),
  item_packaging_id: z.string().optional(),
  // What physically arrived: exact at the four-place receipt column. The server converts it to stock units exactly or refuses (ADR 0142); an Item's
  // ordering increment does not apply to a measured delivery.
  received_qty: decimalField({ spec: "stockLedger", sign: "positive", label: "Received quantity" }),
  received_uom_id: z.string().min(1, "Select a UoM."),
  provisional_unit_cost: decimalField({ spec: "money", sign: "nonNegative", optional: true, label: "Provisional unit cost" }),
  batch_no: z.string(),
  serial_no: z.string(),
});
const schema = z.object({
  supplier_account_id: z.string().min(1, "Select a supplier."),
  physical_arrival_at: z.string().min(1, "Enter the physical arrival time."),
  posting_date: z.string().min(1, "Enter the posting date."),
  lines: z
    .array(lineSchema)
    .min(1)
    // Belt-and-suspenders: the dropdown already excludes a line picked in another
    // row, but this catches it too — two rows against the same open PO line would
    // double-allocate its outstanding quantity, and only fail confusingly later,
    // at post time (PostGoodsReceipt sums all matching lines across the receipt).
    .superRefine((lines, ctx) => {
      const firstIndexById = new Map<string, number>();
      lines.forEach((line, index) => {
        if (!line.purchase_order_line_id) return;
        const identity = `${line.purchase_order_line_id}:${line.serial_no.trim()}`;
        const firstIndex = firstIndexById.get(identity);
        if (firstIndex !== undefined) {
          ctx.addIssue({
            code: z.ZodIssueCode.custom,
            message: "This order line is already used in another row.",
            path: [index, "purchase_order_line_id"],
          });
        } else {
          firstIndexById.set(identity, index);
        }
      });
    }),
});
export type GoodsReceiptFormValues = z.infer<typeof schema>;

const DRAFT_NAME = "goods-receipt";

export function isPristine(values: GoodsReceiptFormValues): boolean {
  if (values.supplier_account_id) return false;
  if (!values.lines || values.lines.length === 0) return true;
  if (values.lines.length > 1) return false;
  const line = values.lines[0];
  if (!line) return true;
  return (
    !line.purchase_order_line_id &&
    !line.location_id &&
    !line.received_qty &&
    !line.received_uom_id &&
    !line.batch_no &&
    !line.serial_no
  );
}

export function readUnfinishedGoodsReceipt(): GoodsReceiptFormValues | null {
  const stored = readDraft<GoodsReceiptFormValues>(DRAFT_NAME)?.value;
  if (!stored || !Array.isArray(stored.lines)) return null;
  const values: GoodsReceiptFormValues = {
    ...stored,
    supplier_account_id: stored.supplier_account_id ?? "",
    physical_arrival_at: stored.physical_arrival_at ?? "",
    posting_date: stored.posting_date ?? "",
    lines: stored.lines.length
      ? stored.lines.map((line) => ({
          purchase_order_line_id: line?.purchase_order_line_id ?? "",
          location_id: line?.location_id ?? "",
          received_qty: line?.received_qty ?? "",
          received_uom_id: line?.received_uom_id ?? "",
          provisional_unit_cost: line?.provisional_unit_cost ?? "",
          batch_no: line?.batch_no ?? "",
          serial_no: line?.serial_no ?? "",
          receipt_line_id: line?.receipt_line_id,
          item_packaging_id: line?.item_packaging_id,
        }))
      : [],
  };
  if (isPristine(values)) return null;
  return values;
}

export const hasUnfinishedGoodsReceipt = () =>
  readUnfinishedGoodsReceipt() !== null;
export const clearUnfinishedGoodsReceipt = () => clearDraft(DRAFT_NAME);

const emptyLine = {
  purchase_order_line_id: "",
  location_id: "",
  item_packaging_id: "",
  received_qty: "",
  received_uom_id: "",
  provisional_unit_cost: "",
  batch_no: "",
  serial_no: "",
};

function packageQuantity(stock: string, factor: string): string {
  const scaled = (text: string, places: number) => {
    const [w = "0", f = ""] = text.split(".");
    return BigInt(w) * 10n ** BigInt(places) + BigInt(f.padEnd(places, "0"));
  };
  const content = scaled(factor, 9);
  if (content === 0n) return "";
  const q = (scaled(stock, 4) * 1000000000n + content / 2n) / content;
  return String(q / 10000n) + "." + String(q % 10000n).padStart(4, "0");
}

export function GoodsReceiptForm({
  onSubmit,
  isSubmitting,
  formError,
  defaultValues,
  preserveCommercialSnapshot = false,
  pinnedOrders = [],
}: {
  onSubmit: (
    payload: StoreGoodsReceiptRequest,
    form: UseFormReturn<GoodsReceiptFormValues>,
  ) => void;
  isSubmitting: boolean;
  formError?: string | null;
  defaultValues?: Partial<GoodsReceiptFormValues>;
  preserveCommercialSnapshot?: boolean;
  pinnedOrders?: PurchaseOrderResource[];
}) {
  const mayViewCommercialCost = usePurchasingCommercialCost();
  const isCreate = !defaultValues?.lines?.some((l) =>
    Boolean(l.receipt_line_id),
  );
  const [unfinished] = useState(() =>
    isCreate ? readUnfinishedGoodsReceipt() : null,
  );
  const [resumed, setResumed] = useState(unfinished !== null);
  const [confirmingStartOver, setConfirmingStartOver] = useState(false);

  const now = new Date();
  const initialValues: GoodsReceiptFormValues = useMemo(() => {
    const base: GoodsReceiptFormValues = {
      supplier_account_id: "",
      physical_arrival_at: new Date(
        now.getTime() - now.getTimezoneOffset() * 60000,
      )
        .toISOString()
        .slice(0, 16),
      posting_date: new Date(now.getTime() - now.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 10),
      lines: [{ ...emptyLine }],
    };
    if (defaultValues) {
      return {
        ...base,
        ...defaultValues,
        lines: defaultValues.lines?.length
          ? defaultValues.lines.map((line) => ({ ...emptyLine, ...line }))
          : base.lines,
      };
    }
    if (unfinished) {
      return {
        ...base,
        ...unfinished,
        lines: unfinished.lines?.length
          ? unfinished.lines.map((line) => ({ ...emptyLine, ...line }))
          : base.lines,
      };
    }
    return base;
  }, []);

  const form = useForm<GoodsReceiptFormValues>({
    resolver: zodResolver(schema),
    defaultValues: initialValues,
  });

  useEffect(() => {
    if (!isCreate) return;
    const persist = () => {
      const values = form.getValues();
      if (isPristine(values)) {
        clearDraft(DRAFT_NAME);
      } else {
        writeDraft<GoodsReceiptFormValues>(DRAFT_NAME, values);
      }
    };
    persist();
    const subscription = form.watch(persist);
    return () => subscription.unsubscribe();
  }, [form, isCreate]);

  function startOver() {
    clearDraft(DRAFT_NAME);
    const resetValues: GoodsReceiptFormValues = {
      supplier_account_id: "",
      physical_arrival_at: new Date(
        now.getTime() - now.getTimezoneOffset() * 60000,
      )
        .toISOString()
        .slice(0, 16),
      posting_date: new Date(now.getTime() - now.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 10),
      lines: [{ ...emptyLine }],
    };
    form.reset(resetValues);
    setResumed(false);
    setConfirmingStartOver(false);
  }
  const { fields, append, remove } = useFieldArray({
    control: form.control,
    name: "lines",
  });
  const supplierId = Number(form.watch("supplier_account_id"));
  const ordersQuery = usePurchaseOrdersIndex(
    {
      "filter[supplier_account_id]": supplierId || undefined,
      "filter[status]": "ISSUED",
      include: "lines",
      per_page: 100,
      sort: "order_date",
    },
    { query: { enabled: supplierId > 0 } },
  );
  const listedOrders =
    ordersQuery.data?.status === 200 ? ordersQuery.data.data.data : [];
  const orders = [
    ...listedOrders,
    ...pinnedOrders.filter(
      (order) =>
        order.supplier_account_id === supplierId &&
        !listedOrders.some((listed) => listed.id === order.id),
    ),
  ];
  const eligibleLines = useMemo(
    () =>
      orders.flatMap((order) =>
        (order.lines ?? [])
          .filter(
            (line) =>
              line.outstanding_stock_qty !== "0.0000" ||
              defaultValues?.lines?.some(
                (saved) => Number(saved.purchase_order_line_id) === line.id,
              ),
          )
          .map((line) => ({ order, line })),
      ),
    [orders],
  );
  const locationsQuery = useLocationsIndex({ per_page: 100, sort: "code" });
  // Put-away targets are stock locations; a chosen one is always resolvable by its own id even if it is past the first page.
  const locations = useLocationsById(
    (form.watch("lines") ?? []).map((line) => Number(line?.location_id) || 0).filter((id) => id > 0),
    locationsQuery.data?.status === 200
      ? locationsQuery.data.data.data.filter(
          (location) => location.active && location.allows_stock,
        )
      : [],
  );
  const uomsQuery = useUomsIndex({ per_page: 100, sort: "name" });
  const uoms = uomsQuery.data?.status === 200 ? uomsQuery.data.data.data : [];
  const itemsQuery = useItemsIndex({
    per_page: 100,
    sort: "name",
  });
  // The chosen lines' items are resolved by id; a first page of items is not a lookup table (lib/use-records-by-id.ts).
  const items = useItemsById(
    eligibleLines.map(({ line }) => line.item_id),
    itemsQuery.data?.status === 200 ? itemsQuery.data.data.data : [],
  );

  function chooseLine(index: number, value: string) {
    form.setValue(`lines.${index}.purchase_order_line_id`, value, {
      shouldValidate: true,
    });
    const selected = eligibleLines.find(
      ({ line }) => line.id === Number(value),
    );
    if (!selected) return;
    form.setValue(
      ("lines." + index + ".item_packaging_id") as "lines.0.item_packaging_id",
      selected.line.item_packaging_id
        ? String(selected.line.item_packaging_id)
        : "",
    );
    form.setValue(
      `lines.${index}.received_qty`,
      packageQuantity(
        selected.line.outstanding_stock_qty,
        selected.line.uom_factor,
      ),
    );
    form.setValue(
      `lines.${index}.received_uom_id`,
      String(selected.line.purchase_uom_id),
    );
    form.setValue(`lines.${index}.provisional_unit_cost`, "");
  }

  return (
    <Form {...form}>
      <form
        className="space-y-6"
        onSubmit={form.handleSubmit((values) => {
          const lines = values.lines.map((line, index) => {
            const selected = eligibleLines.find(
              ({ line: candidate }) =>
                candidate.id === Number(line.purchase_order_line_id),
            );
            if (!selected) {
              form.setError(`lines.${index}.purchase_order_line_id`, {
                type: "manual",
                message:
                  "This order line is unavailable. Select an issued purchase-order line.",
              });
              return null;
            }
            return {
              ...(line.receipt_line_id
                ? { id: Number(line.receipt_line_id) }
                : {}),
              purchase_order_line_id: selected.line.id,
              item_id: selected.line.item_id,
              location_id: Number(line.location_id),
              received_qty: line.received_qty,
              received_uom_id: Number(line.received_uom_id),
              item_packaging_id: line.item_packaging_id
                ? Number(line.item_packaging_id)
                : null,
              ...(mayViewCommercialCost
                ? {
                    ...(line.provisional_unit_cost
                      ? { provisional_unit_cost: line.provisional_unit_cost }
                      : {}),
                    ...(!preserveCommercialSnapshot
                      ? {
                          currency_id: selected.order.currency_id,
                          ...(selected.order.fx_rate
                            ? { fx_rate: selected.order.fx_rate }
                            : {}),
                          ...(selected.order.fx_rate_date
                            ? { fx_rate_date: selected.order.fx_rate_date }
                            : {}),
                          ...(selected.order.fx_rate_type
                            ? { fx_rate_type: selected.order.fx_rate_type }
                            : {}),
                          ...(selected.order.fx_rate_source
                            ? { fx_rate_source: selected.order.fx_rate_source }
                            : {}),
                        }
                      : {}),
                  }
                : {}),
              batch_no: line.batch_no || null,
              serial_no: line.serial_no || null,
            };
          });
          if (lines.some((line) => line === null)) return;
          onSubmit(
            {
              supplier_account_id: Number(values.supplier_account_id),
              physical_arrival_at: new Date(
                values.physical_arrival_at,
              ).toISOString(),
              posting_date: values.posting_date,
              lines: lines.filter((line) => line !== null),
            },
            form,
          );
        })}
      >
        {isCreate && resumed && (
          <div
            role="status"
            className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary/20 bg-primary-soft px-4 py-3 text-primary-soft-foreground"
          >
            <div className="flex min-w-0 items-start gap-3">
              <History aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
              <div className="min-w-0 text-sm">
                <p className="font-medium">
                  {confirmingStartOver
                    ? "Discard everything you've entered?"
                    : "Continuing your unfinished goods receipt"}
                </p>
                <p className="text-xs opacity-80">
                  {confirmingStartOver
                    ? "What you entered will be cleared and cannot be brought back."
                    : "What you entered last time is still here."}
                </p>
              </div>
            </div>
            {confirmingStartOver ? (
              <div className="flex items-center gap-2">
                <Button
                  type="button"
                  size="sm"
                  variant="destructive"
                  onClick={startOver}
                >
                  <RotateCcw className="mr-1 h-3.5 w-3.5" />
                  Yes, start over
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => setConfirmingStartOver(false)}
                >
                  Keep it
                </Button>
              </div>
            ) : (
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() => setConfirmingStartOver(true)}
              >
                <RotateCcw className="mr-1 h-3.5 w-3.5" />
                Start over
              </Button>
            )}
          </div>
        )}

        {formError && (
          <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
            {formError}
          </div>
        )}
        <DetailSection title="Arrival">
          <FormField
            control={form.control}
            name="supplier_account_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Supplier</FormLabel>
                <FormControl>
                  <SupplierCombobox
                    label="Supplier"
                    placeholder="Select supplier"
                    value={field.value}
                    onValueChange={(value) => {
                      field.onChange(value);
                      form.setValue("lines", [{ ...emptyLine }]);
                    }}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="physical_arrival_at"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Physical arrival</FormLabel>
                <FormControl>
                  <Input type="datetime-local" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="posting_date"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Posting date</FormLabel>
                <FormControl>
                  <DateField
                    value={field.value}
                    onChange={field.onChange}
                    onBlur={field.onBlur}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>
        <section className="space-y-4">
          <div>
            <h2 className="text-lg font-semibold">Received lines</h2>
            <p className="text-sm text-muted-foreground">
              Choose open issued-order lines. Costs and FX evidence default from
              each issued snapshot.
            </p>
          </div>
          {fields.map((field, index) => {
            const selected = eligibleLines.find(
              ({ line }) =>
                line.id ===
                Number(form.watch(`lines.${index}.purchase_order_line_id`)),
            );
            const item = items.find(
              (candidate) => candidate.id === selected?.line.item_id,
            );
            // A PO line already chosen on another row can't be chosen again here —
            // each row draws down the same "outstanding" quantity, so reusing one
            // would double-allocate it (only caught otherwise at post time).
            const usedByOtherRows = new Set(
              form
                .watch("lines")
                .filter((_, otherIndex) => otherIndex !== index)
                .map((line) => line.purchase_order_line_id)
                .filter(Boolean),
            );
            const availableLines = eligibleLines.filter(
              ({ line }) =>
                line.id === selected?.line.id ||
                !usedByOtherRows.has(String(line.id)),
            );
            return (
              <div
                key={field.id}
                className="grid gap-4 rounded-md border p-4 md:grid-cols-3"
              >
                <FormField
                  control={form.control}
                  name={`lines.${index}.purchase_order_line_id`}
                  render={({ field }) => (
                    <FormItem className="md:col-span-2">
                      <FormLabel>Purchase-order line</FormLabel>
                      <FormControl>
                        <Combobox
                          {...lookupState(ordersQuery, "purchase orders")}
                          ref={field.ref}
                          value={field.value}
                          onValueChange={(value) => chooseLine(index, value)}
                          options={availableLines.map(({ order, line }) => ({
                            value: String(line.id),
                            label: `${order.number} / ${line.line_number} — ${line.item_code ?? line.description} (${line.outstanding_stock_qty} open)`,
                          }))}
                          placeholder={
                            supplierId
                              ? "Select open order line"
                              : "Select supplier first"
                          }
                          searchPlaceholder="Search order lines…"
                          emptyText="No open order lines found."
                        />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name={`lines.${index}.location_id`}
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Put-away location</FormLabel>
                      <FormControl>
                        <Combobox
                          {...lookupState(locationsQuery, "locations")}
                          ref={field.ref}
                          value={field.value}
                          onValueChange={field.onChange}
                          options={locations.map((location) => ({
                            value: String(location.id),
                            label: formatLocationOption(location),
                          }))}
                          placeholder="Select location"
                          searchPlaceholder="Search locations…"
                          emptyText="No locations found."
                        />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name={`lines.${index}.received_qty`}
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>
                        {form.watch(
                          ("lines." +
                            index +
                            ".item_packaging_id") as "lines.0.item_packaging_id",
                        )
                          ? "Received packages"
                          : "Received quantity"}
                      </FormLabel>
                      <FormControl>
                        <DecimalInput rule={{ spec: "stockLedger", sign: "positive" }} {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name={`lines.${index}.received_uom_id`}
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Received UoM</FormLabel>
                      <FormControl>
                        <Combobox
                          {...lookupState(uomsQuery, "units")}
                          disabled={
                            !!form.watch(
                              ("lines." +
                                index +
                                ".item_packaging_id") as "lines.0.item_packaging_id",
                            )
                          }
                          ref={field.ref}
                          value={field.value}
                          onValueChange={field.onChange}
                          options={uoms.map((uom) => ({
                            value: String(uom.id),
                            label: `${uom.code} — ${uom.name}`,
                          }))}
                          placeholder="Select UoM"
                          searchPlaceholder="Search units…"
                          emptyText="No units found."
                        />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                {mayViewCommercialCost && (
                  <FormField
                    control={form.control}
                    name={`lines.${index}.provisional_unit_cost`}
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Provisional unit cost</FormLabel>
                        <FormControl>
                          <DecimalInput rule={{ spec: "money", sign: "nonNegative" }} {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                )}
                {!mayViewCommercialCost && (
                  <p className="text-sm text-muted-foreground">
                    Provisional valuation follows the issued purchase order;
                    commercial amounts are not shown in this role.
                  </p>
                )}
                {item?.tracking === "BATCH" && (
                  <FormField
                    control={form.control}
                    name={`lines.${index}.batch_no`}
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Batch number</FormLabel>
                        <FormControl>
                          <Input {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                )}
                {item?.tracking === "SERIAL" && (
                  <FormField
                    control={form.control}
                    name={`lines.${index}.serial_no`}
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Serial number</FormLabel>
                        <FormControl>
                          <Input {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                )}
                {fields.length > 1 && (
                  <div className="flex items-end">
                    <Button
                      type="button"
                      variant="outline"
                      onClick={() => remove(index)}
                    >
                      Remove line
                    </Button>
                  </div>
                )}
              </div>
            );
          })}
          <Button
            type="button"
            variant="outline"
            disabled={!supplierId}
            onClick={() => append({ ...emptyLine })}
          >
            Add another line
          </Button>
        </section>
        <div className="flex justify-end">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? "Saving…" : "Save draft receipt"}
          </Button>
        </div>
      </form>
    </Form>
  );
}
