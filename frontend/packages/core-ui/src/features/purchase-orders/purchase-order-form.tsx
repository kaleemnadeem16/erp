import { useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import {
  useFieldArray,
  useForm,
  type FieldPath,
  type UseFormReturn,
} from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { History, MapPin, Plus, RotateCcw } from "lucide-react";
import { z } from "zod";
import {
  useCurrenciesIndex,
  useItemSuppliersIndex,
  useItemsIndex,
  usePurchaseOrdersIndex,
  type SupplierAccountResource,
  useSupplierAccountsSitesIndex,
  useUomsIndex,
  type SupplierSiteResource,
} from "@erp/core-api";

import { AdvancedOptionSection } from "../../components/ui/advanced-option-section";
import { Collapse } from "../../components/ui/collapse";
import { SectionStack } from "../../components/ui/section-stack";
import { MoreOptionsToggle } from "../../components/ui/more-options-toggle";
import type { OptionsStyle } from "../../lib/presentation";
import type { AdvancedOption } from "../../components/ui/advanced-options-menu";
import { Combobox } from "../../components/ui/combobox";
import { DateField } from "../../components/ui/date-field";
import { Button } from "../../components/ui/button";
import { FormLabelWithHint } from "../../components/ui/field-hint";
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
import { RecordSheetAside } from "../../components/page/record-sheet";
import {
  WizardShell,
  type WizardStep,
} from "../../components/page/wizard-shell";
import { clearDraft, readDraft, writeDraft } from "../../lib/session-draft";
import { usePermission } from "../../hooks/use-permission";
import { PurchaseOrderLineFields } from "./purchase-order-line-fields";
import { useItemsById, useSuppliersById } from "../../lib/use-records-by-id";
import { SupplierCombobox } from "../../components/lookups/entity-comboboxes";
import { PurchaseOrderSummary } from "./purchase-order-summary";
import { SupplierSiteDialog } from "./supplier-site-form";
import { lookupState } from "../../lib/api-errors";
import { decimalField } from "../../lib/decimal";
import { DecimalInput } from "../../components/ui/decimal-input";

// "CODE — City, ST" when there's an address to show; the name alone otherwise.
const supplierSiteLabel = (site: SupplierSiteResource) => {
  const place = [site.party_site.city, site.party_site.state]
    .filter(Boolean)
    .join(", ");
  return `${site.party_site.name}${place ? ` — ${place}` : ""}`;
};

const lineSchema = z.object({
  item_id: z.string().min(1, "Item is required"),
  description: z.string().trim().min(1, "Description is required").max(255),
  item_packaging_id: z.string().optional(),
  supplier_offer_id: z.string().optional(),
  price_uom_id: z.string().optional(),
  price_quantity: decimalField({ spec: "stockLedger", sign: "positive", optional: true, label: "Price per quantity" }).optional(),
  ordering_exception_reason: z.string().optional(),
  priced_qty: z.string().optional(),
  // Order quantity: the four-place transaction column; the server then checks the converted stock quantity and the Item's increment (ADR 0142).
  ordered_qty: decimalField({ spec: "stockLedger", sign: "positive", label: "Quantity" }),
  purchase_uom_id: z.string().min(1, "Purchase UoM is required"),
  unit_price: decimalField({ spec: "money", sign: "nonNegative", label: "Unit price" }),
  discount_amount: decimalField({ spec: "money", sign: "nonNegative", label: "Discount" }),
  tax_amount: decimalField({ spec: "money", sign: "nonNegative", label: "Tax" }),
  expected_delivery_date: z.string(),
  qa_required: z.boolean(),
});
const schema = z.object({
  supplier_account_id: z.string().min(1, "Supplier is required"),
  supplier_site_id: z.string(),
  replaces_purchase_order_id: z.string().optional(),
  currency_id: z.string().min(1, "Currency is required"),
  fx_policy: z.enum(["FLOATING", "FIXED"]),
  // Manual exchange rate: nine fractional and ten whole digits, strictly positive (the server and the database refuse zero).
  fx_rate: decimalField({ spec: "fxRate", sign: "positive", label: "FX rate" }),
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

const createDefaults = (): PurchaseOrderFormValues => ({
  supplier_account_id: "",
  supplier_site_id: "",
  currency_id: "",
  ...DEFAULT_FX,
  fx_rate_date: today,
  order_date: today,
  expected_delivery_date: "",
  notes: "",
  lines: [emptyLine()],
});

// `fx_rate_date` is excluded — it's expected to differ from `today`, not a
// sign of a real override.
const fxIsCustomised = (values: PurchaseOrderFormValues) =>
  values.fx_policy !== DEFAULT_FX.fx_policy ||
  values.fx_rate !== DEFAULT_FX.fx_rate ||
  values.fx_rate_type !== DEFAULT_FX.fx_rate_type ||
  values.fx_rate_source !== DEFAULT_FX.fx_rate_source;

// Closing the create sheet never throws away work: the form is kept for the
// tab's lifetime and offered back the next time "New purchase order" opens —
// someone partway through a PO who needs to check a supplier, a stock level
// or an earlier order shouldn't have to choose between that and their entries.
// Named "unfinished purchase order" in the UI, not "draft" — a *saved* PO in
// DRAFT status is a different thing here.
const DRAFT_NAME = "purchase-order";

interface UnfinishedPurchaseOrder {
  values: PurchaseOrderFormValues;
  step: number;
}

const isPristine = (values: PurchaseOrderFormValues) =>
  JSON.stringify(values) === JSON.stringify(createDefaults());

function readUnfinishedPurchaseOrder(): UnfinishedPurchaseOrder | null {
  const stored = readDraft<UnfinishedPurchaseOrder>(DRAFT_NAME)?.value;
  if (
    !stored ||
    typeof stored.step !== "number" ||
    !Array.isArray(stored.values?.lines)
  )
    return null;
  const base = createDefaults();
  const values: PurchaseOrderFormValues = {
    ...base,
    ...stored.values,
    lines: stored.values.lines.length
      ? stored.values.lines.map((line) => ({ ...emptyLine(), ...line }))
      : base.lines,
  };
  if (isPristine(values)) return null;
  return {
    values,
    step: Math.min(Math.max(stored.step, 0), WIZARD_STEPS.length - 1),
  };
}

export const hasUnfinishedPurchaseOrder = () =>
  readUnfinishedPurchaseOrder() !== null;
export const clearUnfinishedPurchaseOrder = () => clearDraft(DRAFT_NAME);

// The same steps for create and edit, so the two never feel like different screens; editing just lets any step be opened
// at any time (an order being edited is already complete). Exchange rate has no step of its own: it's rarely edited
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
    "supplier_site_id",
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
    replaces_purchase_order_id: values.replaces_purchase_order_id
      ? Number(values.replaces_purchase_order_id)
      : null,
    supplier_account_id: Number(values.supplier_account_id),
    supplier_site_id: values.supplier_site_id
      ? Number(values.supplier_site_id)
      : null,
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
      item_packaging_id: line.item_packaging_id
        ? Number(line.item_packaging_id)
        : null,
      supplier_offer_id: line.supplier_offer_id
        ? Number(line.supplier_offer_id)
        : null,
      price_uom_id: line.price_uom_id ? Number(line.price_uom_id) : null,
      price_quantity: line.price_quantity || "1",
      ordering_exception_reason: line.ordering_exception_reason || null,
      unit_price: line.unit_price,
      discount_amount: line.discount_amount,
      tax_amount: line.tax_amount,
      // The order-level date is the common promise; a line only needs its own
      // value when that item is due on a different date. Persist the resolved
      // date on every line so issued snapshots and later planning never have
      // to reinterpret a mutable header default.
      expected_delivery_date:
        line.expected_delivery_date || values.expected_delivery_date || null,
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
  optionsStyle = "disclosure",
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
  /**
   * How the rarely-changed fields (replaces-order, exchange rate, and on each line the package, price basis, discount and tax…)
   * are put away: `"disclosure"` — one "More options" toggle, fields keep their defaults — or `"menu"` — the Advanced options
   * checkbox menu. Both are kept; the choice is made in `apps/main/src/presentation.ts`.
   */
  optionsStyle?: OptionsStyle;
}) {
  const isCreate = !defaultValues;
  const mayManageSupplierSites = usePermission("parties.supplier-site.manage");
  const [unfinished] = useState(() =>
    isCreate ? readUnfinishedPurchaseOrder() : null,
  );
  const [resumed, setResumed] = useState(unfinished !== null);
  // Wiping the form is the one thing on this screen that can't be taken back,
  // so it asks first — in place, not in a modal, and defaulting to "keep".
  const [confirmingStartOver, setConfirmingStartOver] = useState(false);
  const [step, setStep] = useState(unfinished?.step ?? 0);
  const startingValues = defaultValues ?? unfinished?.values;
  const form = useForm<PurchaseOrderFormValues>({
    resolver: zodResolver(schema),
    defaultValues: startingValues ?? createDefaults(),
  });
  const { fields, append, remove } = useFieldArray({
    control: form.control,
    name: "lines",
  });
  // Keep the unfinished order for as long as this tab lives (create only). A
  // form back at its blank starting values isn't work worth keeping.
  useEffect(() => {
    if (!isCreate) return;
    const persist = () => {
      const values = form.getValues();
      if (isPristine(values)) clearDraft(DRAFT_NAME);
      else writeDraft<UnfinishedPurchaseOrder>(DRAFT_NAME, { values, step });
    };
    persist();
    const subscription = form.watch(persist);
    return () => subscription.unsubscribe();
  }, [form, isCreate, step]);

  // A field that was reported invalid is checked again as soon as it changes, so its message goes away the moment the value is
  // fixed. (Without this, an error found by "Next" stays on screen until the whole form is submitted.)
  useEffect(() => {
    const subscription = form.watch((_values, { name }) => {
      if (name && form.getFieldState(name as FieldPath<PurchaseOrderFormValues>).error) void form.trigger(name as FieldPath<PurchaseOrderFormValues>);
    });
    return () => subscription.unsubscribe();
  }, [form]);

  // Collapsed for the common case; auto-expanded when editing an order — or
  // resuming one — whose FX fields were already customized, so existing
  // overrides stay visible instead of hiding behind a click.
  const [showFxDetails, setShowFxDetails] = useState(
    () => !!startingValues && fxIsCustomised(startingValues),
  );

  const [showReplaces, setShowReplaces] = useState(
    () => !!startingValues?.replaces_purchase_order_id,
  );

  const [moreOpen, setMoreOpen] = useState(
    () => showFxDetails || showReplaces,
  );
  const replacesVisible = optionsStyle === "menu" ? showReplaces : moreOpen;
  const fxVisible = optionsStyle === "menu" ? showFxDetails : moreOpen;
  // An optional block: in the menu style it is simply absent until its checkbox is ticked; in the disclosure style it stays
  // mounted and slides open and closed with the "More options" toggle.
  const optional = (open: boolean, node: ReactNode) =>
    optionsStyle === "menu" ? (
      <AdvancedOptionSection expanded={open}>{node}</AdvancedOptionSection>
    ) : (
      <Collapse open={open}>{node}</Collapse>
    );
  const moreSummary = [
    form.watch("replaces_purchase_order_id") ? "replaces an order" : null,
    fxIsCustomised(form.getValues()) ? "exchange rate" : null,
  ]
    .filter(Boolean)
    .join(", ");

  function startOver() {
    form.reset(createDefaults());
    setStep(0);
    setShowFxDetails(false);
    setShowReplaces(false);
    setMoreOpen(false);
    setResumed(false);
    setConfirmingStartOver(false);
    clearDraft(DRAFT_NAME);
  }
  useEffect(() => {
    if (optionsStyle !== "menu") {
      onAdvancedOptionsChange?.([]);
      return;
    }
    onAdvancedOptionsChange?.([
      {
        id: "replaces-order",
        label: "This order replaces another",
        checked: showReplaces,
        onCheckedChange: (checked) => {
          if (!checked) form.setValue("replaces_purchase_order_id", "");
          setShowReplaces(checked);
        },
      },
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
  }, [showFxDetails, showReplaces, optionsStyle]);
  // Lands keyboard/screen-reader focus on Supplier — the form's first field —
  // instead of wherever it was left (e.g. the "New PO" nav link), on create only.
  useEffect(() => {
    if (!defaultValues) form.setFocus("supplier_account_id");
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // The chosen supplier is resolved by its own id, and the picker searches the server (a first page of suppliers is not a lookup table).
  const suppliers = useSuppliersById<SupplierAccountResource>(
    Number(form.watch("supplier_account_id")) > 0 ? [Number(form.watch("supplier_account_id"))] : [],
    [],
  );
  const supplierOptions = useMemo(
    () =>
      suppliers.map((supplier) => ({
        value: String(supplier.id),
        label: `${supplier.code}${supplier.party?.name ? ` — ${supplier.party.name}` : ""}`,
      })),
    [suppliers],
  );
  const supplierIdNumber = Number(form.watch("supplier_account_id")) || 0;
  const chosenSupplier = suppliers.find(
    (supplier) => supplier.id === supplierIdNumber,
  );
  const sitesQuery = useSupplierAccountsSitesIndex(supplierIdNumber, {
    per_page: 100,
  });
  const sites =
    sitesQuery.data?.status === 200 ? sitesQuery.data.data.data : [];
  const siteOptions = useMemo(
    () =>
      sites.map((site) => ({
        value: String(site.id),
        label: supplierSiteLabel(site),
      })),
    [sites],
  );
  const currenciesQuery = useCurrenciesIndex({ per_page: 250, sort: "code" });
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
  // The lines' own items are resolved by id: a first page of items is not a lookup table (see lib/use-records-by-id.ts).
  const items = useItemsById(
    (form.watch("lines") ?? []).map((line) => Number(line?.item_id) || 0).filter((id) => id > 0),
    itemsQuery.data?.status === 200 ? itemsQuery.data.data.data : [],
  );
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
  // The unit this supplier sells each item in (ADR 0033 keeps it on the item-supplier row, since it
  // varies per item and supplier). Used only to default a line's Purchase UoM when its item is
  // chosen; an item with no such row, or one that names no unit, starts on its stock unit.
  const itemSuppliersQuery = useItemSuppliersIndex(
    {
      "filter[supplier_account_id]": supplierIdNumber,
      "filter[active]": true,
      per_page: 100,
    },
    { query: { enabled: supplierIdNumber > 0 } },
  );
  const purchaseUomIdByItem = useMemo(() => {
    const rows =
      itemSuppliersQuery.data?.status === 200
        ? itemSuppliersQuery.data.data.data
        : [];

    return new Map(
      rows.flatMap((row) =>
        row.purchase_uom_id === null
          ? []
          : [[row.item_id, row.purchase_uom_id] as const],
      ),
    );
  }, [itemSuppliersQuery.data]);
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
  // A site belongs to one supplier — dropping the old one when the supplier
  // itself changes, but not on first mount, where it's the resumed or
  // already-saved order's own valid combination and must survive untouched.
  const previousSupplierId = useRef(supplierId);
  useEffect(() => {
    if (previousSupplierId.current !== supplierId) {
      form.setValue("supplier_site_id", "");
    }
    previousSupplierId.current = supplierId;
  }, [form, supplierId]);

  const [addingSite, setAddingSite] = useState(false);

  // Orders this one could replace: the chosen supplier's orders that have left draft. The server decides which are eligible
  // (cancelled, or with a short-closed quantity) and says so if the pick is not.
  const replaceableQuery = usePurchaseOrdersIndex(
    {
      "filter[supplier_account_id]": supplierIdNumber,
      sort: "-created_at",
      per_page: 100,
    },
    { query: { enabled: replacesVisible && supplierIdNumber > 0 } },
  );
  const replaceableOptions = useMemo(
    () =>
      (replaceableQuery.data?.status === 200
        ? replaceableQuery.data.data.data
        : []
      )
        .filter((order) => order.status !== "DRAFT")
        .map((order) => ({
          value: String(order.id),
          label: `${order.number ?? `#${order.id}`} · ${order.status.toLowerCase()} · ${order.order_date}`,
        })),
    [replaceableQuery.data],
  );

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
  const showDetailsStep = step === 0;
  const showLinesStep = step === 1;
  const showReviewStep = step === 2;

  const summaryProps = {
    supplierOptions,
    siteOptions,
    currencyOptions,
    itemOptions,
    uomOptions,
    paymentTermsDays: chosenSupplier?.payment_terms_days,
    leadTimeDays: chosenSupplier?.lead_time_days,
    currencyPlaces: Object.fromEntries(currencies.map((currency) => [String(currency.id), currency.decimal_places])),
  };

  const sections = (
    <SectionStack>
      {showDetailsStep && (
        <DetailSection title="Supplier and dates">
          <FormField
            control={form.control}
            name="supplier_account_id"
            render={({ field }) => (
              <FormItem>
                <FormLabelWithHint>Supplier</FormLabelWithHint>
                <FormControl>
                  <SupplierCombobox
                    label="Supplier"
                    placeholder="Select supplier"
                    value={field.value}
                    onValueChange={field.onChange}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="supplier_site_id"
            render={({ field }) => (
              <FormItem>
                <FormLabelWithHint hint="The supplier's address this order is placed with. Leave it on the default to use the supplier's main site; add one with the pin button when they have another (a warehouse, a factory).">
                  Supplier site
                </FormLabelWithHint>
                <div className="flex gap-1.5">
                  <FormControl>
                    <Combobox
                      {...lookupState(sitesQuery, "supplier sites")}
                      ref={field.ref}
                      value={field.value}
                      onValueChange={field.onChange}
                      options={siteOptions}
                      disabled={!supplierIdNumber}
                      placeholder={
                        supplierIdNumber
                          ? "Order from the supplier's default"
                          : "Select a supplier first"
                      }
                      searchPlaceholder="Search sites…"
                      emptyText="No sites on file for this supplier yet."
                      className="flex-1"
                    />
                  </FormControl>
                  {mayManageSupplierSites && (
                    <Button
                      type="button"
                      variant="outline"
                      size="icon"
                      disabled={!supplierIdNumber}
                      title="Add a supplier site"
                      onClick={() => setAddingSite(true)}
                    >
                      <MapPin />
                      <span className="sr-only">Add a supplier site</span>
                    </Button>
                  )}
                </div>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="order_date"
            render={({ field }) => (
              <FormItem>
                <FormLabelWithHint>Order date</FormLabelWithHint>
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
          <FormField
            control={form.control}
            name="expected_delivery_date"
            render={({ field }) => (
              <FormItem>
                <FormLabelWithHint hint="When you expect the goods overall. Every line follows this date unless it is given its own (line Advanced options → A delivery date for this line).">
                  Expected delivery
                </FormLabelWithHint>
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
          <FormField
            control={form.control}
            name="currency_id"
            render={({ field }) => (
              <FormItem>
                <FormLabelWithHint hint="The currency the supplier invoices in. It starts as the supplier's own currency. If it differs from the company's base currency the exchange rate applies — see Advanced options → Override the exchange rate.">
                  Currency
                </FormLabelWithHint>
                <FormControl>
                  <Combobox
                    {...lookupState(currenciesQuery, "currencies")}
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
          {optional(replacesVisible, (
            <FormField
              control={form.control}
              name="replaces_purchase_order_id"
              render={({ field }) => (
                <FormItem>
                  <FormLabelWithHint hint="Links this order to an earlier one it takes the place of — a cancelled order, or one with a short-closed quantity — so the history stays connected and nothing is ordered twice by mistake. The earlier order must belong to the same supplier.">
                    Replaces order
                  </FormLabelWithHint>
                  <FormControl>
                    <Combobox
                      {...lookupState(replaceableQuery, "orders")}
                      ref={field.ref}
                      value={field.value ?? ""}
                      onValueChange={field.onChange}
                      disabled={!supplierIdNumber}
                      options={replaceableOptions}
                      placeholder={
                        supplierIdNumber
                          ? "Choose the order this replaces"
                          : "Select a supplier first"
                      }
                      searchPlaceholder="Search orders…"
                      emptyText="This supplier has no orders that can be replaced."
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          ))}
          <FormField
            control={form.control}
            name="notes"
            render={({ field }) => (
              <FormItem className="sm:col-span-2">
                <FormLabelWithHint>Notes</FormLabelWithHint>
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
          {optionsStyle === "disclosure" && (
            <MoreOptionsToggle
              className="sm:col-span-2"
              open={moreOpen}
              onOpenChange={setMoreOpen}
              controls="po-more-options"
              label="More options"
              openLabel="Hide options"
              summary={moreSummary}
            />
          )}
        </DetailSection>
      )}
      {showDetailsStep && (
        optional(fxVisible, (
          <DetailSection
            title="Exchange rate"
            description="The rate and its source are pinned when the order is issued."
          >
            <FormField
              control={form.control}
              name="fx_policy"
              render={({ field }) => (
                <FormItem>
                  <FormLabelWithHint hint="Fixed keeps this rate. Floating until issue lets it follow the market rate until the order is issued, then pins it.">
                    Policy
                  </FormLabelWithHint>
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
                    <FormLabelWithHint
                      hint={
                        {
                          fx_rate: "How many units of the company's base currency one unit of the order currency buys. 1 means no conversion.",
                          fx_rate_date: "The day the rate applies to.",
                          fx_rate_type: "Which kind of rate it is — for example MANUAL, or a bank's buying or selling rate.",
                          fx_rate_source: "Where the rate came from, kept so it can be traced later.",
                        }[name]
                      }
                    >
                      {
                        {
                          fx_rate: "Rate",
                          fx_rate_date: "Rate date",
                          fx_rate_type: "Rate type",
                          fx_rate_source: "Source",
                        }[name]
                      }
                    </FormLabelWithHint>
                    <FormControl>
                      {name === "fx_rate_date" ? (
                        <DateField
                          value={field.value}
                          onChange={field.onChange}
                          onBlur={field.onBlur}
                        />
                      ) : (
                        name === "fx_rate" ? (
                          <DecimalInput
                            rule={{ spec: "fxRate", sign: "positive" }}
                            {...field}
                          />
                        ) : (
                          <Input type="text" {...field} />
                        )
                      )}
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            ))}
          </DetailSection>
        ))
      )}
      {showLinesStep && (
        <DetailSection
          title="Lines"
          description="Use Add line for each distinct item, delivery or QA requirement."
        >
          <div className="space-y-4 sm:col-span-2">
            {fields.map((field, index) => (
              <PurchaseOrderLineFields
                key={field.id}
                form={form}
                index={index}
                items={items}
                uoms={uoms}
                uomLookup={lookupState(uomsQuery, "units")}
                purchaseUomIdByItem={purchaseUomIdByItem}
                optionsStyle={optionsStyle}
                canRemove={fields.length > 1}
                onRemove={() => remove(index)}
              />
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
          description="This is everything that will be saved. Go back to change anything; nothing is frozen until the order is issued."
          fullWidth
        >
          <PurchaseOrderSummary
            variant="review"
            control={form.control}
            {...summaryProps}
          />
        </DetailSection>
      )}
    </SectionStack>
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
      <Button
        type="button"
        onClick={handleFooterAction}
        disabled={isSubmitting}
      >
        {step < WIZARD_STEPS.length - 1
          ? "Next"
          : isSubmitting
            ? "Saving…"
            : isCreate
              ? "Save draft"
              : "Save changes"}
      </Button>
    </>
  );

  return (
    <Form {...form}>
      <form
        // A container of its own: the line grid below sizes to the room the
        // form actually has (a sheet with a side column, a sheet without one,
        // or a page), not to the browser window.
        className="@container space-y-6"
        onSubmit={form.handleSubmit((values) => onSubmit(values, form))}
      >
        {/* The review step is the full version of this summary, so the side column steps aside for it. */}
        {!showReviewStep && (
          <RecordSheetAside>
            <PurchaseOrderSummary
              variant="aside"
              control={form.control}
              {...summaryProps}
            />
          </RecordSheetAside>
        )}
        {formError && (
          <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
            {formError}
          </div>
        )}
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
                    : "Continuing your unfinished purchase order"}
                </p>
                <p className="text-xs opacity-80">
                  {confirmingStartOver
                    ? "The supplier, dates and lines will be cleared and can't be brought back."
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
                  <RotateCcw />
                  Yes, start over
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  autoFocus
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
                <RotateCcw />
                Start over
              </Button>
            )}
          </div>
        )}
        <WizardShell
          steps={WIZARD_STEPS}
          activeIndex={step}
          freeNavigation={!isCreate}
          onStepClick={setStep}
          footer={wizardFooter}
        >
          {sections}
        </WizardShell>
      </form>
      {mayManageSupplierSites && supplierIdNumber > 0 && (
        <SupplierSiteDialog
          supplierAccountId={supplierIdNumber}
          open={addingSite}
          onOpenChange={setAddingSite}
          onCreated={(site) => {
            sitesQuery.refetch();
            form.setValue("supplier_site_id", String(site.id), {
              shouldValidate: true,
            });
          }}
        />
      )}
    </Form>
  );
}
