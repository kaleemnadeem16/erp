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
import { DateField } from "../../components/ui/date-field";
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
import { DetailField } from "../../components/detail/detail-field";
import { WizardShell, type WizardStep } from "../../components/page/wizard-shell";

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

// The create flow only — an edit screen shows every section at once since
// the user is changing an already-complete order, not filling one in for
// the first time. Exchange rate has no step of its own: it's rarely edited
// (see the "Override the exchange rate" advanced option below) and belongs
// with the fields around it, not promoted to a whole extra step.
const WIZARD_STEPS: WizardStep[] = [
  { id: "details", label: "Supplier & dates" },
  { id: "lines", label: "Lines" },
  { id: "review", label: "Review" },
];
const WIZARD_STEP_FIELDS: FieldPath<PurchaseOrderFormValues>[][] = [
  [
    "supplier_account_id",
    "order_date",
    "expected_delivery_date",
    "currency_id",
    "notes",
    "fx_policy",
    "fx_rate",
    "fx_rate_date",
    "fx_rate_type",
    "fx_rate_source",
  ],
  ["lines"],
  [],
];

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
  const isCreate = !defaultValues;
  const [step, setStep] = useState(0);
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
  // Lands keyboard/screen-reader focus on Supplier — the form's first field —
  // instead of wherever it was left (e.g. the "New PO" nav link), on create only.
  useEffect(() => {
    if (!defaultValues) form.setFocus("supplier_account_id");
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

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
  const currencyOptions = useMemo(
    () =>
      currencies.map((currency) => ({
        value: String(currency.id),
        label: `${currency.code} — ${currency.name}`,
      })),
    [currencies],
  );
  const itemsQuery = useItemsIndex({
    per_page: 100,
    sort: "name",
    "filter[status]": "ACTIVE",
  });
  const items =
    itemsQuery.data?.status === 200 ? itemsQuery.data.data.data : [];
  const itemOptions = useMemo(
    () =>
      items.map((item) => ({
        value: String(item.id),
        label: `${item.code} — ${item.name}`,
      })),
    [items],
  );
  const uomsQuery = useUomsIndex({ per_page: 100, sort: "name" });
  const uoms = uomsQuery.data?.status === 200 ? uomsQuery.data.data.data : [];
  const uomOptions = useMemo(
    () =>
      uoms.map((uom) => ({
        value: String(uom.id),
        label: `${uom.code} — ${uom.name}`,
      })),
    [uoms],
  );
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

  const watchedLines = form.watch("lines");

  // A single stable button drives both "Next" and the final "Save draft" —
  // it never swaps from type="button" to type="submit" under the user's own
  // click. It used to: form.trigger() is async, and on a real click (mouse
  // down, then up) there's enough of a gap for that promise to resolve and
  // React to re-render *between* the two — if the re-render swaps in a
  // type="submit" button at the same screen position, the same click's
  // mouseup lands on it and silently double-fires (advance step *and*
  // submit). Keeping one persistent element removes the race entirely.
  async function handleFooterAction() {
    const isLastStep = step === WIZARD_STEPS.length - 1;
    const valid = await form.trigger(WIZARD_STEP_FIELDS[step]);
    if (!valid) return;
    if (isLastStep) form.handleSubmit((values) => onSubmit(values, form))();
    else setStep((current) => Math.min(current + 1, WIZARD_STEPS.length - 1));
  }
  function goToPreviousStep() {
    setStep((current) => Math.max(current - 1, 0));
  }

  // Every step's fields stay mounted in RHF's own form state regardless of
  // which section is currently shown — only the JSX is conditional, so
  // switching steps never loses a value the same way the AVCO-only field
  // elsewhere in this codebase doesn't reset when it's hidden.
  const showDetailsStep = !isCreate || step === 0;
  const showLinesStep = !isCreate || step === 1;
  const showReviewStep = isCreate && step === 2;

  const sections = (
    <>
      {showDetailsStep && (
        <DetailSection title="Supplier and dates">
          <FormField
            control={form.control}
            name="supplier_account_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Supplier</FormLabel>
                <FormControl>
                  <Combobox
                    ref={field.ref}
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
                  <DateField value={field.value} onChange={field.onChange} onBlur={field.onBlur} />
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
                  <DateField value={field.value} onChange={field.onChange} onBlur={field.onBlur} />
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
                <FormControl>
                  <Combobox
                    ref={field.ref}
                    value={field.value}
                    onValueChange={field.onChange}
                    options={currencyOptions}
                    placeholder="Select currency"
                    searchPlaceholder="Search currencies…"
                    emptyText="No currencies found."
                  />
                </FormControl>
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
      )}
      {showDetailsStep && (
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
                      {name === "fx_rate_date" ? (
                        <DateField value={field.value} onChange={field.onChange} onBlur={field.onBlur} />
                      ) : (
                        <Input
                          type="text"
                          inputMode={name === "fx_rate" ? "decimal" : undefined}
                          {...field}
                        />
                      )}
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            ))}
          </DetailSection>
        </AdvancedOptionSection>
      )}
      {showLinesStep && (
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
                      <FormControl>
                        <Combobox
                          ref={itemField.ref}
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
                          {name === "expected_delivery_date" ? (
                            <DateField
                              value={String(lineField.value)}
                              onChange={lineField.onChange}
                              onBlur={lineField.onBlur}
                            />
                          ) : (
                            <Input
                              type="text"
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
                          )}
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
                      <FormControl>
                        <Combobox
                          ref={uomField.ref}
                          value={uomField.value}
                          onValueChange={uomField.onChange}
                          options={uomOptions}
                          placeholder="Select UoM"
                          searchPlaceholder="Search units…"
                          emptyText="No units found."
                        />
                      </FormControl>
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
      )}
      {showReviewStep && (
        <DetailSection
          title="Review"
          description="Check the details below, then save the draft."
        >
          <DetailField label="Supplier">
            {supplierOptions.find((option) => option.value === supplierId)?.label}
          </DetailField>
          <DetailField label="Order date">{form.watch("order_date") || undefined}</DetailField>
          <DetailField label="Expected delivery">
            {form.watch("expected_delivery_date") || undefined}
          </DetailField>
          <DetailField label="Currency">
            {currencyOptions.find((option) => option.value === form.watch("currency_id"))?.label}
          </DetailField>
          <DetailField label="Notes" fullWidth>
            {form.watch("notes") || undefined}
          </DetailField>
          <DetailField label="Lines" fullWidth>
            <ul className="space-y-1">
              {watchedLines.map((line, index) => (
                <li key={index} className="text-sm">
                  {itemOptions.find((option) => option.value === line.item_id)?.label ?? line.description}
                  {" — "}
                  {line.ordered_qty} × {line.unit_price}
                </li>
              ))}
            </ul>
          </DetailField>
        </DetailSection>
      )}
    </>
  );

  const wizardFooter = (
    <>
      <Button
        type="button"
        variant="outline"
        onClick={goToPreviousStep}
        className={step === 0 ? "invisible" : undefined}
      >
        Back
      </Button>
      <Button type="button" onClick={handleFooterAction} disabled={isSubmitting}>
        {step < WIZARD_STEPS.length - 1 ? "Next" : isSubmitting ? "Saving…" : "Save draft"}
      </Button>
    </>
  );

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
        {isCreate ? (
          <WizardShell steps={WIZARD_STEPS} activeIndex={step} footer={wizardFooter}>
            {sections}
          </WizardShell>
        ) : (
          <>
            {sections}
            <div className="flex justify-end">
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting ? "Saving…" : "Save draft"}
              </Button>
            </div>
          </>
        )}
      </form>
    </Form>
  );
}
