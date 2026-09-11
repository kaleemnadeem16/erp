import { useEffect, useMemo, useState } from "react";
import {
  useFieldArray,
  useForm,
  type FieldPath,
  type UseFormReturn,
} from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Plus, Trash2 } from "lucide-react";
import { z } from "zod";
import {
  useCurrenciesIndex,
  useItemsIndex,
  useSupplierAccountsIndex,
  useUomsIndex,
} from "@erp/core-api";

import { AdvancedOptionSection } from "../../components/ui/advanced-option-section";
import type { AdvancedOption } from "../../components/ui/advanced-options-menu";
import { Combobox } from "../../components/ui/combobox";
import { Button } from "../../components/ui/button";
import { Checkbox } from "../../components/ui/checkbox";
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "../../components/ui/form";
import { Input } from "../../components/ui/input";
import { Textarea } from "../../components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "../../components/ui/select";
import { DetailSection } from "../../components/detail/detail-page-layout";

const decimal = (label: string, positive = false) =>
  z
    .string()
    .trim()
    .regex(
      /^\d{1,15}(\.\d{1,4})?$/,
      `${label} must have up to 4 decimal places`,
    )
    .refine(
      (value) => !positive || Number(value) > 0,
      `${label} must be greater than zero`,
    );
const lineSchema = z.object({
  item_id: z.string().min(1, "Item is required"),
  description: z.string().trim().min(1, "Description is required").max(255),
  ordered_qty: decimal("Quantity", true),
  purchase_uom_id: z.string().min(1, "Purchase UoM is required"),
  unit_price: decimal("Unit price"),
  discount_amount: decimal("Discount"),
  tax_amount: decimal("Tax"),
  expected_delivery_date: z.string(),
  qa_required: z.boolean(),
});
const schema = z.object({
  supplier_account_id: z.string().min(1, "Supplier is required"),
  currency_id: z.string().min(1, "Currency is required"),
  fx_policy: z.enum(["FLOATING", "FIXED"]),
  fx_rate: z
    .string()
    .trim()
    .regex(/^\d{1,10}(\.\d{1,9})?$/, "FX rate must have up to 9 decimal places")
    .refine((value) => Number(value) > 0, "FX rate must be greater than zero"),
  fx_rate_date: z.string().min(1, "FX rate date is required"),
  fx_rate_type: z.string().trim().min(1, "FX rate type is required").max(50),
  fx_rate_source: z
    .string()
    .trim()
    .min(1, "FX rate source is required")
    .max(100),
  order_date: z.string().min(1, "Order date is required"),
  expected_delivery_date: z.string(),
  notes: z.string().max(2000, "Notes must be 2000 characters or fewer."),
  lines: z.array(lineSchema).min(1, "At least one line is required"),
});
export type PurchaseOrderFormValues = z.infer<typeof schema>;

const today = new Date().toISOString().slice(0, 10);

// No company-defaults settings exist yet (ADR pending) — until a real "base
// currency, no conversion needed" concept lands server-side, a same-currency
// 1:1 rate is the sensible stand-in default so the FX section can stay
// collapsed for the common case instead of demanding four fields up front.
const DEFAULT_FX = {
  fx_policy: "FIXED" as const,
  fx_rate: "1.000000000",
  fx_rate_type: "MANUAL",
  fx_rate_source: "Manual entry",
};

const emptyLine = (): PurchaseOrderFormValues["lines"][number] => ({
  item_id: "",
  description: "",
  ordered_qty: "1.0000",
  purchase_uom_id: "",
  unit_price: "0.0000",
  discount_amount: "0.0000",
  tax_amount: "0.0000",
  expected_delivery_date: "",
  qa_required: false,
});

export function toPurchaseOrderPayload(values: PurchaseOrderFormValues) {
  return {
    supplier_account_id: Number(values.supplier_account_id),
    currency_id: Number(values.currency_id),
    fx_policy: values.fx_policy,
    fx_rate: values.fx_rate,
    fx_rate_date: values.fx_rate_date,
    fx_rate_type: values.fx_rate_type.trim(),
    fx_rate_source: values.fx_rate_source.trim(),
    order_date: values.order_date,
    expected_delivery_date: values.expected_delivery_date || null,
    notes: values.notes.trim() || null,
    lines: values.lines.map((line, index) => ({
      line_number: index + 1,
      item_id: Number(line.item_id),
      description: line.description.trim(),
      ordered_qty: line.ordered_qty,
      purchase_uom_id: Number(line.purchase_uom_id),
      unit_price: line.unit_price,
      discount_amount: line.discount_amount,
      tax_amount: line.tax_amount,
      expected_delivery_date: line.expected_delivery_date || null,
      qa_required: line.qa_required,
    })),
  };
}

export function PurchaseOrderForm({
  defaultValues,
  onSubmit,
  isSubmitting,
  formError,
  onAdvancedOptionsChange,
}: {
  defaultValues?: PurchaseOrderFormValues;
  onSubmit: (
    values: PurchaseOrderFormValues,
    form: UseFormReturn<PurchaseOrderFormValues>,
  ) => void;
  isSubmitting?: boolean;
  formError?: string | null;
  /**
   * Reports this form's "Advanced options" entries (currently just the FX
   * override) so the screen wrapping this form can render an
   * `AdvancedOptionsMenu` in its own `PageHeader` — the form keeps owning the
   * state and the reset-on-uncheck logic; the screen only decides where the
   * trigger button sits.
   */
  onAdvancedOptionsChange?: (options: AdvancedOption[]) => void;
}) {
  const form = useForm<PurchaseOrderFormValues>({
    resolver: zodResolver(schema),
    defaultValues: defaultValues ?? {
      supplier_account_id: "",
      currency_id: "",
      ...DEFAULT_FX,
      fx_rate_date: today,
      order_date: today,
      expected_delivery_date: "",
      notes: "",
      lines: [emptyLine()],
    },
  });
  const { fields, append, remove } = useFieldArray({
    control: form.control,
    name: "lines",
  });
  // Collapsed for the common case; auto-expanded when editing an order whose
  // FX fields were already customized, so existing overrides stay visible
  // instead of hiding behind a click. `fx_rate_date` is excluded — it's
  // expected to differ from `today` on every edit, not a sign of a real override.
  const [showFxDetails, setShowFxDetails] = useState(
    () =>
      !!defaultValues &&
      (defaultValues.fx_policy !== DEFAULT_FX.fx_policy ||
        defaultValues.fx_rate !== DEFAULT_FX.fx_rate ||
        defaultValues.fx_rate_type !== DEFAULT_FX.fx_rate_type ||
        defaultValues.fx_rate_source !== DEFAULT_FX.fx_rate_source),
  );
  useEffect(() => {
    onAdvancedOptionsChange?.([
      {
        id: "fx-override",
        label: "Override the exchange rate",
        checked: showFxDetails,
        onCheckedChange: (checked) => {
          if (!checked) {
            // Unchecking always snaps back to the default, live values —
            // never leaves a customized rate sitting hidden behind a
            // collapsed section, which is what re-checking would then
            // silently show again.
            form.setValue("fx_policy", DEFAULT_FX.fx_policy);
            form.setValue("fx_rate", DEFAULT_FX.fx_rate);
            form.setValue("fx_rate_date", today);
            form.setValue("fx_rate_type", DEFAULT_FX.fx_rate_type);
            form.setValue("fx_rate_source", DEFAULT_FX.fx_rate_source);
          }
          setShowFxDetails(checked);
        },
      },
    ]);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [showFxDetails]);
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
  const currenciesQuery = useCurrenciesIndex({ per_page: 100, sort: "code" });
  const currencies =
    currenciesQuery.data?.status === 200 ? currenciesQuery.data.data.data : [];
  const itemsQuery = useItemsIndex({
    per_page: 100,
    sort: "name",
    "filter[status]": "ACTIVE",
  });
  const items =
    itemsQuery.data?.status === 200 ? itemsQuery.data.data.data : [];
  const uomsQuery = useUomsIndex({ per_page: 100, sort: "name" });
  const uoms = uomsQuery.data?.status === 200 ? uomsQuery.data.data.data : [];
  const supplierId = form.watch("supplier_account_id");
  useEffect(() => {
    const supplier = suppliers.find(
      (candidate) => candidate.id === Number(supplierId),
    );
    if (supplier?.default_currency_id)
      form.setValue("currency_id", String(supplier.default_currency_id), {
        shouldValidate: true,
      });
  }, [form, supplierId, suppliers]);

  return (
    <Form {...form}>
      <form
        className="space-y-6"
        onSubmit={form.handleSubmit((values) => onSubmit(values, form))}
      >
        {formError && (
          <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
            {formError}
          </div>
        )}
        <DetailSection title="Supplier and dates">
          <FormField
            control={form.control}
            name="supplier_account_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Supplier</FormLabel>
                <FormControl>
                  <Combobox
                    value={field.value}
                    onValueChange={field.onChange}
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
            name="order_date"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Order date</FormLabel>
                <FormControl>
                  <Input type="date" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="expected_delivery_date"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Expected delivery</FormLabel>
                <FormControl>
                  <Input type="date" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="currency_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Currency</FormLabel>
                <Select value={field.value} onValueChange={field.onChange}>
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue placeholder="Select currency" />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    {currencies.map((currency) => (
                      <SelectItem key={currency.id} value={String(currency.id)}>
                        {currency.code} — {currency.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="notes"
            render={({ field }) => (
              <FormItem className="sm:col-span-2">
                <FormLabel>Notes</FormLabel>
                <FormControl>
                  <Textarea
                    {...field}
                    placeholder="Delivery instructions, requester reference, or any other remark for the order as a whole."
                    rows={3}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>
        <AdvancedOptionSection expanded={showFxDetails}>
          <DetailSection
            title="Exchange rate"
            description="The rate and its source are pinned when the order is issued."
          >
            <FormField
              control={form.control}
              name="fx_policy"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Policy</FormLabel>
                  <Select value={field.value} onValueChange={field.onChange}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      <SelectItem value="FIXED">Fixed</SelectItem>
                      <SelectItem value="FLOATING">
                        Floating until issue
                      </SelectItem>
                    </SelectContent>
                  </Select>
                  <FormMessage />
                </FormItem>
              )}
            />
            {(
              [
                "fx_rate",
                "fx_rate_date",
                "fx_rate_type",
                "fx_rate_source",
              ] as const
            ).map((name) => (
              <FormField
                key={name}
                control={form.control}
                name={name}
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>
                      {
                        {
                          fx_rate: "Rate",
                          fx_rate_date: "Rate date",
                          fx_rate_type: "Rate type",
                          fx_rate_source: "Source",
                        }[name]
                      }
                    </FormLabel>
                    <FormControl>
                      <Input
                        type={name === "fx_rate_date" ? "date" : "text"}
                        inputMode={
                          name === "fx_rate" ? "decimal" : undefined
                        }
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            ))}
          </DetailSection>
        </AdvancedOptionSection>
        <DetailSection
          title="Lines"
          description="Use Add line for each distinct item, delivery or QA requirement."
        >
          <div className="space-y-4 sm:col-span-2">
            {fields.map((field, index) => (
              <fieldset
                key={field.id}
                className="grid gap-3 rounded-md border p-4 md:grid-cols-4"
              >
                <legend className="px-1 text-sm font-medium">
                  Line {index + 1}
                </legend>
                <FormField
                  control={form.control}
                  name={`lines.${index}.item_id`}
                  render={({ field: itemField }) => (
                    <FormItem>
                      <FormLabel>Item</FormLabel>
                      <Select
                        value={itemField.value}
                        onValueChange={(value) => {
                          itemField.onChange(value);
                          const item = items.find(
                            (candidate) => candidate.id === Number(value),
                          );
                          if (item) {
                            form.setValue(
                              `lines.${index}.description`,
                              item.name,
                            );
                            form.setValue(
                              `lines.${index}.purchase_uom_id`,
                              String(item.stock_uom_id),
                            );
                          }
                        }}
                      >
                        <FormControl>
                          <SelectTrigger>
                            <SelectValue placeholder="Select item" />
                          </SelectTrigger>
                        </FormControl>
                        <SelectContent>
                          {items.map((item) => (
                            <SelectItem key={item.id} value={String(item.id)}>
                              {item.code} — {item.name}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                {(
                  [
                    "description",
                    "ordered_qty",
                    "unit_price",
                    "discount_amount",
                    "tax_amount",
                    "expected_delivery_date",
                  ] as const
                ).map((name) => (
                  <FormField
                    key={name}
                    control={form.control}
                    name={
                      `lines.${index}.${name}` as FieldPath<PurchaseOrderFormValues>
                    }
                    render={({ field: lineField }) => (
                      <FormItem>
                        <FormLabel>
                          {
                            {
                              description: "Description",
                              ordered_qty: "Quantity",
                              unit_price: "Unit price",
                              discount_amount: "Discount",
                              tax_amount: "Tax",
                              expected_delivery_date: "Expected date",
                            }[name]
                          }
                        </FormLabel>
                        <FormControl>
                          <Input
                            type={
                              name === "expected_delivery_date"
                                ? "date"
                                : "text"
                            }
                            inputMode={
                              [
                                "ordered_qty",
                                "unit_price",
                                "discount_amount",
                                "tax_amount",
                              ].includes(name)
                                ? "decimal"
                                : undefined
                            }
                            {...lineField}
                            value={String(lineField.value)}
                          />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                ))}
                <FormField
                  control={form.control}
                  name={`lines.${index}.purchase_uom_id`}
                  render={({ field: uomField }) => (
                    <FormItem>
                      <FormLabel>Purchase UoM</FormLabel>
                      <Select
                        value={uomField.value}
                        onValueChange={uomField.onChange}
                      >
                        <FormControl>
                          <SelectTrigger>
                            <SelectValue placeholder="Select UoM" />
                          </SelectTrigger>
                        </FormControl>
                        <SelectContent>
                          {uoms.map((uom) => (
                            <SelectItem key={uom.id} value={String(uom.id)}>
                              {uom.code} — {uom.name}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name={`lines.${index}.qa_required`}
                  render={({ field: qaField }) => (
                    <FormItem className="flex items-center gap-2 pt-6">
                      <FormControl>
                        <Checkbox
                          checked={qaField.value}
                          onCheckedChange={(checked) =>
                            qaField.onChange(checked === true)
                          }
                        />
                      </FormControl>
                      <FormLabel>QA required</FormLabel>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <div className="flex items-end justify-end">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => remove(index)}
                    disabled={fields.length === 1}
                  >
                    <Trash2 className="size-4" />
                    Remove
                  </Button>
                </div>
              </fieldset>
            ))}
          </div>
          <Button
            type="button"
            variant="outline"
            onClick={() => append(emptyLine())}
          >
            <Plus className="size-4" />
            Add line
          </Button>
        </DetailSection>
        <div className="flex justify-end">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? "Saving…" : "Save draft"}
          </Button>
        </div>
      </form>
    </Form>
  );
}
