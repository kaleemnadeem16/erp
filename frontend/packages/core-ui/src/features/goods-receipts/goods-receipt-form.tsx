import { useMemo } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useFieldArray, useForm, type UseFormReturn } from "react-hook-form";
import { z } from "zod";
import {
  useItemsIndex,
  useLocationsIndex,
  usePurchaseOrdersIndex,
  useSupplierAccountsIndex,
  useUomsIndex,
  type StoreGoodsReceiptRequest,
} from "@erp/core-api";

import { Button } from "../../components/ui/button";
import { Combobox } from "../../components/ui/combobox";
import { DateField } from "../../components/ui/date-field";
import { Input } from "../../components/ui/input";
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
import { usePurchasingCommercialCost } from "../../hooks/use-purchasing-commercial-cost";

const decimal = z
  .string()
  .trim()
  .regex(/^\d+(\.\d{1,9})?$/, "Enter a positive decimal value.");
const positiveDecimal = decimal.refine(
  (value) => !/^0+(?:\.0+)?$/.test(value),
  "Enter a value greater than zero.",
);
const lineSchema = z.object({
  purchase_order_line_id: z.string().min(1, "Select an order line."),
  location_id: z.string().min(1, "Select a stock location."),
  received_qty: positiveDecimal,
  received_uom_id: z.string().min(1, "Select a UoM."),
  provisional_unit_cost: z.union([decimal, z.literal("")]),
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
        const firstIndex = firstIndexById.get(line.purchase_order_line_id);
        if (firstIndex !== undefined) {
          ctx.addIssue({
            code: z.ZodIssueCode.custom,
            message: "This order line is already used in another row.",
            path: [index, "purchase_order_line_id"],
          });
        } else {
          firstIndexById.set(line.purchase_order_line_id, index);
        }
      });
    }),
});
export type GoodsReceiptFormValues = z.infer<typeof schema>;

const emptyLine = {
  purchase_order_line_id: "",
  location_id: "",
  received_qty: "",
  received_uom_id: "",
  provisional_unit_cost: "",
  batch_no: "",
  serial_no: "",
};

export function GoodsReceiptForm({
  onSubmit,
  isSubmitting,
  formError,
}: {
  onSubmit: (
    payload: StoreGoodsReceiptRequest,
    form: UseFormReturn<GoodsReceiptFormValues>,
  ) => void;
  isSubmitting: boolean;
  formError?: string | null;
}) {
  const mayViewCommercialCost = usePurchasingCommercialCost();
  const now = new Date();
  const form = useForm<GoodsReceiptFormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
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
    },
  });
  const { fields, append, remove } = useFieldArray({
    control: form.control,
    name: "lines",
  });
  const supplierId = Number(form.watch("supplier_account_id"));
  const suppliersQuery = useSupplierAccountsIndex({
    per_page: 100,
    sort: "code",
    include: "party",
  });
  const suppliers =
    suppliersQuery.data?.status === 200 ? suppliersQuery.data.data.data : [];
  const supplierOptions = useMemo(
    () =>
      suppliers.map((supplier) => ({
        value: String(supplier.id),
        label: `${supplier.code}${supplier.party?.name ? ` — ${supplier.party.name}` : ""}`,
      })),
    [suppliers],
  );
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
  const orders =
    ordersQuery.data?.status === 200 ? ordersQuery.data.data.data : [];
  const eligibleLines = useMemo(
    () =>
      orders.flatMap((order) =>
        (order.lines ?? [])
          .filter((line) => line.outstanding_stock_qty !== "0.0000")
          .map((line) => ({ order, line })),
      ),
    [orders],
  );
  const locationsQuery = useLocationsIndex({ per_page: 100, sort: "code" });
  const locations =
    locationsQuery.data?.status === 200
      ? locationsQuery.data.data.data.filter(
          (location) => location.active && location.allows_stock,
        )
      : [];
  const uomsQuery = useUomsIndex({ per_page: 100, sort: "name" });
  const uoms = uomsQuery.data?.status === 200 ? uomsQuery.data.data.data : [];
  const itemsQuery = useItemsIndex({
    per_page: 100,
    sort: "name",
  });
  const items =
    itemsQuery.data?.status === 200 ? itemsQuery.data.data.data : [];

  function chooseLine(index: number, value: string) {
    form.setValue(`lines.${index}.purchase_order_line_id`, value, {
      shouldValidate: true,
    });
    const selected = eligibleLines.find(
      ({ line }) => line.id === Number(value),
    );
    if (!selected) return;
    form.setValue(
      `lines.${index}.received_qty`,
      selected.line.outstanding_stock_qty,
    );
    form.setValue(
      `lines.${index}.received_uom_id`,
      String(selected.line.purchase_uom_id),
    );
    form.setValue(
      `lines.${index}.provisional_unit_cost`,
      mayViewCommercialCost ? (selected.line.unit_price ?? "") : "",
    );
  }

  return (
    <Form {...form}>
      <form
        className="space-y-6"
        onSubmit={form.handleSubmit((values) => {
          const lines = values.lines.map((line) => {
            const selected = eligibleLines.find(
              ({ line: candidate }) =>
                candidate.id === Number(line.purchase_order_line_id),
            );
            if (!selected)
              throw new Error("Selected purchase-order line is unavailable.");
            return {
              purchase_order_line_id: selected.line.id,
              item_id: selected.line.item_id,
              location_id: Number(line.location_id),
              received_qty: line.received_qty,
              received_uom_id: Number(line.received_uom_id),
              ...(mayViewCommercialCost
                ? {
                    ...(line.provisional_unit_cost
                      ? { provisional_unit_cost: line.provisional_unit_cost }
                      : {}),
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
              batch_no: line.batch_no || null,
              serial_no: line.serial_no || null,
            };
          });
          onSubmit(
            {
              supplier_account_id: Number(values.supplier_account_id),
              physical_arrival_at: new Date(
                values.physical_arrival_at,
              ).toISOString(),
              posting_date: values.posting_date,
              lines,
            },
            form,
          );
        })}
      >
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
                  <Combobox
                    {...lookupState(suppliersQuery, "suppliers")}
                    ref={field.ref}
                    value={field.value}
                    onValueChange={(value) => {
                      field.onChange(value);
                      form.setValue("lines", [{ ...emptyLine }]);
                    }}
                    options={supplierOptions}
                    placeholder="Select supplier"
                    searchPlaceholder="Search suppliers…"
                    emptyText="No suppliers found."
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
              ({ line }) => !usedByOtherRows.has(String(line.id)),
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
                      <FormLabel>Received quantity</FormLabel>
                      <FormControl>
                        <Input inputMode="decimal" {...field} />
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
                          <Input inputMode="decimal" {...field} />
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
