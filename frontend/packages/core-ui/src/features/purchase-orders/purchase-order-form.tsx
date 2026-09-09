import { useEffect } from "react";
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
  lines: z.array(lineSchema).min(1, "At least one line is required"),
});
export type PurchaseOrderFormValues = z.infer<typeof schema>;

const today = new Date().toISOString().slice(0, 10);
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
}: {
  defaultValues?: PurchaseOrderFormValues;
  onSubmit: (
    values: PurchaseOrderFormValues,
    form: UseFormReturn<PurchaseOrderFormValues>,
  ) => void;
  isSubmitting?: boolean;
  formError?: string | null;
}) {
  const form = useForm<PurchaseOrderFormValues>({
    resolver: zodResolver(schema),
    defaultValues: defaultValues ?? {
      supplier_account_id: "",
      currency_id: "",
      fx_policy: "FIXED",
      fx_rate: "1.000000000",
      fx_rate_date: today,
      fx_rate_type: "MANUAL",
      fx_rate_source: "Manual entry",
      order_date: today,
      expected_delivery_date: "",
      lines: [emptyLine()],
    },
  });
  const { fields, append, remove } = useFieldArray({
    control: form.control,
    name: "lines",
  });
  const suppliersQuery = useSupplierAccountsIndex({
    per_page: 100,
    sort: "code",
    include: "party",
  });
  const suppliers =
    suppliersQuery.data?.status === 200 ? suppliersQuery.data.data.data : [];
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
                <Select value={field.value} onValueChange={field.onChange}>
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue placeholder="Select supplier" />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    {suppliers.map((supplier) => (
                      <SelectItem key={supplier.id} value={String(supplier.id)}>
                        {supplier.code}
                        {supplier.party?.name
                          ? ` — ${supplier.party.name}`
                          : ""}
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
        </DetailSection>
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
                      inputMode={name === "fx_rate" ? "decimal" : undefined}
                      {...field}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          ))}
        </DetailSection>
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
