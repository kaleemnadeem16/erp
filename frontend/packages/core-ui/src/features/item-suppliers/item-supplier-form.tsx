import { useEffect, useMemo, useState } from "react";
import { ItemCombobox, SupplierCombobox } from "../../components/lookups/entity-comboboxes";
import { useItemsById, useSuppliersById } from "../../lib/use-records-by-id";
import { useForm, type UseFormReturn } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import {
  useUomsIndex,
  type ItemResource,
  type SupplierAccountResource,
} from "@erp/core-api";
import { Button } from "../../components/ui/button";
import { Checkbox } from "../../components/ui/checkbox";
import { Input } from "../../components/ui/input";
import { Combobox } from "../../components/ui/combobox";
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "../../components/ui/form";
import { DetailSection } from "../../components/detail/detail-page-layout";
import { lookupState, type LookupState } from "../../lib/api-errors";
import { clearDraft, readDraft, writeDraft } from "../../lib/session-draft";
import {
  AlertCircle,
  Boxes,
  Building2,
  Calendar,
  CheckCircle2,
  Clock,
  ExternalLink,
  Info,
  Lock,
  RotateCcw,
  Star,
  Tag,
} from "lucide-react";
import { Badge } from "../../components/ui/badge";
import { Link } from "react-router";
import { decimalField, decimalForEdit } from "../../lib/decimal";
import { DecimalInput } from "../../components/ui/decimal-input";

const NONE = "NONE";
const DRAFT_KEY = "item_supplier_new_draft";

const schema = z.object({
  item_id: z.string().min(1, "Item is required"),
  supplier_account_id: z.string().min(1, "Supplier is required"),
  supplier_item_code: z.string().max(255),
  priority: z
    .string()
    .refine(
      (v) => !v || (/^\d+$/.test(v) && Number(v) >= 1 && Number(v) <= 32767),
      "Priority must be between 1 and 32767",
    ),
  lead_time_days: z
    .string()
    .refine(
      (v) => !v || (/^\d+$/.test(v) && Number(v) <= 32767),
      "Lead time must be between 0 and 32767",
    ),
  minimum_order_qty: decimalField({ spec: "stockLedger", sign: "positive", optional: true, label: "Minimum order quantity" }),
  purchase_uom_id: z.string(),
  active: z.boolean(),
});

export type ItemSupplierFormValues = z.infer<typeof schema>;

export const ITEM_SUPPLIER_FORM_FIELDS = [
  "item_id",
  "supplier_account_id",
  "supplier_item_code",
  "priority",
  "lead_time_days",
  "minimum_order_qty",
  "purchase_uom_id",
  "active",
] as const satisfies readonly (keyof ItemSupplierFormValues)[];

function isPristine(v: Partial<ItemSupplierFormValues>): boolean {
  return (
    !v.item_id &&
    !v.supplier_account_id &&
    !v.supplier_item_code &&
    !v.priority &&
    !v.lead_time_days &&
    !v.minimum_order_qty &&
    (v.purchase_uom_id === NONE || !v.purchase_uom_id)
  );
}

export function readUnfinishedItemSupplier(): ItemSupplierFormValues | null {
  const stored = readDraft<ItemSupplierFormValues>(DRAFT_KEY)?.value;
  if (!stored) return null;
  if (isPristine(stored)) return null;
  return {
    item_id: stored.item_id ?? "",
    supplier_account_id: stored.supplier_account_id ?? "",
    supplier_item_code: stored.supplier_item_code ?? "",
    priority: stored.priority ?? "",
    lead_time_days: stored.lead_time_days ?? "",
    minimum_order_qty: decimalForEdit(stored.minimum_order_qty),
    purchase_uom_id: stored.purchase_uom_id ?? NONE,
    active: stored.active ?? true,
  };
}

export function hasUnfinishedItemSupplier(): boolean {
  return readUnfinishedItemSupplier() !== null;
}

export function clearUnfinishedItemSupplier(): void {
  clearDraft(DRAFT_KEY);
}

export function itemSupplierPayload(v: ItemSupplierFormValues) {
  return {
    supplier_item_code: v.supplier_item_code.trim() || null,
    priority: v.priority ? Number(v.priority) : null,
    lead_time_days: v.lead_time_days ? Number(v.lead_time_days) : null,
    minimum_order_qty: v.minimum_order_qty ? v.minimum_order_qty.trim() : null,
    purchase_uom_id:
      v.purchase_uom_id === NONE ? null : Number(v.purchase_uom_id),
    active: v.active,
  };
}

export interface ItemSupplierFormProps {
  mode: "create" | "edit";
  defaultValues?: Partial<ItemSupplierFormValues>;
  itemDetails?: {
    id: number;
    code: string;
    name: string;
    stock_uom_id?: number;
    category?: { name: string; code: string } | null;
  };
  supplierDetails?: {
    id: number;
    code: string;
    party_name?: string | null;
    payment_terms_days?: number | null;
    lead_time_days?: number | null;
  };
  onSubmit: (
    v: ItemSupplierFormValues,
    f: UseFormReturn<ItemSupplierFormValues>,
  ) => void;
  onCancel?: () => void;
  isSubmitting?: boolean;
  formError?: string | null;
  fixedItem?: { id: number; label: string };
}

export function ItemSupplierForm({
  mode,
  defaultValues,
  itemDetails,
  supplierDetails,
  onSubmit,
  onCancel,
  isSubmitting,
  formError,
  fixedItem,
}: ItemSupplierFormProps) {
  const [draftRestored, setDraftRestored] = useState<boolean>(() => {
    if (mode === "create" && !defaultValues) {
      return hasUnfinishedItemSupplier();
    }
    return false;
  });

  const initialDraft =
    mode === "create" && !defaultValues ? readUnfinishedItemSupplier() : null;

  const form = useForm<ItemSupplierFormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      item_id: "",
      supplier_account_id: "",
      supplier_item_code: "",
      priority: "",
      lead_time_days: "",
      minimum_order_qty: "",
      purchase_uom_id: NONE,
      active: true,
      ...(initialDraft ?? {}),
      ...defaultValues,
      ...(fixedItem ? { item_id: String(fixedItem.id) } : {}),
    },
  });

  useEffect(() => {
    if (mode !== "create") return;
    const subscription = form.watch((values) => {
      if (values && !isPristine(values as Partial<ItemSupplierFormValues>)) {
        writeDraft(DRAFT_KEY, values as ItemSupplierFormValues);
      }
    });
    return () => subscription.unsubscribe();
  }, [form, mode]);

  const uomsResult = useUomsIndex({ per_page: 100, sort: "name" });
  const uoms = uomsResult.data?.status === 200 ? uomsResult.data.data.data : [];
  const uomMap = useMemo(() => new Map(uoms.map((u) => [u.id, u])), [uoms]);

  // Selected item / supplier tracking for context & feedback
  const watchedItemId = form.watch("item_id");
  const watchedSupplierId = form.watch("supplier_account_id");
  const watchedPriority = form.watch("priority");
  const watchedLeadTime = form.watch("lead_time_days");
  const watchedPurchaseUomId = form.watch("purchase_uom_id");
  // The chosen item and supplier are resolved by id (a first page of 100 is not a lookup table).
  const items = useItemsById<ItemResource>(watchedItemId ? [Number(watchedItemId)] : [], []);
  const suppliers = useSuppliersById<SupplierAccountResource>(watchedSupplierId ? [Number(watchedSupplierId)] : [], []);

  const selectedSupplierAccount = useMemo(() => {
    if (supplierDetails) return supplierDetails;
    if (!watchedSupplierId) return null;
    const found = suppliers.find((s) => String(s.id) === watchedSupplierId);
    if (!found) return null;
    return {
      id: found.id,
      code: found.code,
      party_name: found.party?.name,
      payment_terms_days: found.payment_terms_days,
      lead_time_days: found.lead_time_days,
    };
  }, [supplierDetails, watchedSupplierId, suppliers]);

  const selectedItemData = useMemo(() => {
    if (itemDetails) return itemDetails;
    if (!watchedItemId) return null;
    const found = items.find((i) => String(i.id) === watchedItemId);
    if (!found) return null;
    return {
      id: found.id,
      code: found.code,
      name: found.name,
      stock_uom_id: found.stock_uom_id,
      category: found.category
        ? { name: found.category.name, code: found.category.code }
        : null,
    };
  }, [itemDetails, watchedItemId, items]);

  const isPreferred = watchedPriority === "1";

  const handleTogglePreferred = (checked: boolean) => {
    if (checked) {
      form.setValue("priority", "1", {
        shouldValidate: true,
        shouldDirty: true,
      });
    } else {
      form.setValue("priority", "", {
        shouldValidate: true,
        shouldDirty: true,
      });
    }
  };

  const handleDiscardDraft = () => {
    clearUnfinishedItemSupplier();
    setDraftRestored(false);
    form.reset({
      item_id: "",
      supplier_account_id: "",
      supplier_item_code: "",
      priority: "",
      lead_time_days: "",
      minimum_order_qty: "",
      purchase_uom_id: NONE,
      active: true,
      ...(fixedItem ? { item_id: String(fixedItem.id) } : {}),
    });
  };

  const select = (
    name: "item_id" | "supplier_account_id",
    label: string,
  ) => (
    <FormField
      control={form.control}
      name={name}
      render={({ field }) => (
        <FormItem>
          <FormLabel className="text-xs font-medium text-foreground">
            {label}
          </FormLabel>
          <FormControl>
            {name === "item_id" ? (
              <ItemCombobox
                label={label}
                placeholder={`Select ${label.toLowerCase()}`}
                disabled={mode === "edit"}
                className="h-10"
                value={field.value}
                onValueChange={field.onChange}
              />
            ) : (
              <SupplierCombobox
                label={label}
                placeholder={`Select ${label.toLowerCase()}`}
                disabled={mode === "edit"}
                className="h-10"
                value={field.value}
                onValueChange={field.onChange}
              />
            )}
          </FormControl>
          <FormMessage />
        </FormItem>
      )}
    />
  );

  return (
    <Form {...form}>
      <form
        className="space-y-6"
        onSubmit={form.handleSubmit((v) => onSubmit(v, form))}
      >
        {draftRestored && (
          <div className="flex items-center justify-between gap-3 rounded-lg border border-primary/20 bg-primary/5 p-3.5 text-sm text-foreground">
            <div className="flex items-center gap-2.5">
              <RotateCcw className="h-4 w-4 text-primary shrink-0" />
              <span className="font-medium">
                Restored unsaved relationship draft from your previous session.
              </span>
            </div>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={handleDiscardDraft}
              className="h-7 text-xs text-muted-foreground hover:text-foreground"
            >
              Discard draft
            </Button>
          </div>
        )}

        {formError && (
          <div className="flex items-start gap-2.5 rounded-lg border border-destructive/50 bg-destructive/10 p-3.5 text-sm text-destructive">
            <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
            <div className="font-medium">{formError}</div>
          </div>
        )}

        {/* Section 1: Item & Supplier Association */}
        <DetailSection
          title="Relationship identification"
          description={
            mode === "edit"
              ? "Item and supplier are permanent identity keys. To change either, archive this relationship and create a replacement."
              : "Specify the catalog item and approved supplier account."
          }
        >
          {mode === "edit" && selectedItemData ? (
            <div className="rounded-lg border bg-muted/30 p-4 space-y-2">
              <div className="flex items-center justify-between">
                <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider flex items-center gap-1.5">
                  <Boxes className="h-3.5 w-3.5 text-primary" />
                  Item
                </span>
                <span className="inline-flex items-center gap-1 text-[11px] text-muted-foreground bg-muted px-2 py-0.5 rounded">
                  <Lock className="h-3 w-3" /> Locked
                </span>
              </div>
              <div>
                <p className="font-semibold text-foreground text-sm leading-tight">
                  {selectedItemData.name}
                </p>
                <p className="text-xs font-mono text-muted-foreground mt-0.5">
                  {selectedItemData.code}
                </p>
              </div>
              <div className="flex items-center gap-2 pt-1 border-t text-xs text-muted-foreground">
                {selectedItemData.category && (
                  <span>
                    Category:{" "}
                    <strong className="text-foreground">
                      {selectedItemData.category.name}
                    </strong>
                  </span>
                )}
                {selectedItemData.stock_uom_id &&
                  uomMap.get(selectedItemData.stock_uom_id) && (
                    <span>
                      • Stock unit:{" "}
                      <strong className="text-foreground">
                        {uomMap.get(selectedItemData.stock_uom_id)?.code}
                      </strong>
                    </span>
                  )}
              </div>
            </div>
          ) : fixedItem ? (
            <div className="space-y-1 p-3 rounded-lg border bg-muted/20">
              <p className="text-xs font-medium text-muted-foreground">Item</p>
              <p className="text-sm font-semibold text-foreground">
                {fixedItem.label}
              </p>
            </div>
          ) : (
            select("item_id", "Catalog Item")
          )}

          {mode === "edit" && selectedSupplierAccount ? (
            <div className="rounded-lg border bg-muted/30 p-4 space-y-2">
              <div className="flex items-center justify-between">
                <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wider flex items-center gap-1.5">
                  <Building2 className="h-3.5 w-3.5 text-primary" />
                  Supplier Account
                </span>
                <span className="inline-flex items-center gap-1 text-[11px] text-muted-foreground bg-muted px-2 py-0.5 rounded">
                  <Lock className="h-3 w-3" /> Locked
                </span>
              </div>
              <div>
                <p className="font-semibold text-foreground text-sm leading-tight">
                  {selectedSupplierAccount.party_name ??
                    selectedSupplierAccount.code}
                </p>
                <p className="text-xs font-mono text-muted-foreground mt-0.5">
                  {selectedSupplierAccount.code}
                </p>
              </div>
              <div className="flex items-center gap-2 pt-1 border-t text-xs text-muted-foreground">
                {selectedSupplierAccount.payment_terms_days != null && (
                  <span>
                    Terms:{" "}
                    <strong className="text-foreground">
                      {selectedSupplierAccount.payment_terms_days} days
                    </strong>
                  </span>
                )}
                {selectedSupplierAccount.lead_time_days != null && (
                  <span>
                    • Default lead time:{" "}
                    <strong className="text-foreground">
                      {selectedSupplierAccount.lead_time_days} days
                    </strong>
                  </span>
                )}
              </div>
            </div>
          ) : (
            select("supplier_account_id", "Supplier Account")
          )}

          <FormField
            control={form.control}
            name="supplier_item_code"
            render={({ field }) => (
              <FormItem className="sm:col-span-2">
                <FormLabel className="text-xs font-medium text-foreground">
                  Supplier Item Code (SKU / Part #)
                </FormLabel>
                <FormControl>
                  <Input
                    placeholder="e.g. ACM-PART-100"
                    className="h-10 font-mono text-sm"
                    {...field}
                  />
                </FormControl>
                <FormDescription className="text-xs leading-relaxed text-muted-foreground">
                  The part identifier used by the supplier on delivery notes and
                  vendor invoices.
                </FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>

        {/* Section 2: Priority & Preferred Status */}
        <DetailSection
          title="Sourcing priority & preferred status"
          description="Control how purchasing logic ranks this vendor when creating purchase requisitions."
        >
          <div className="sm:col-span-2 rounded-lg border p-4 bg-card space-y-3">
            <div className="flex items-start gap-3">
              <Checkbox
                id="preferred_supplier_toggle"
                checked={isPreferred}
                onCheckedChange={(val) => handleTogglePreferred(!!val)}
                className="mt-0.5"
              />
              <div className="space-y-1">
                <label
                  htmlFor="preferred_supplier_toggle"
                  className="text-sm font-semibold text-foreground cursor-pointer flex items-center gap-1.5"
                >
                  <Star className="h-4 w-4 fill-emerald-500 text-emerald-500" />
                  Preferred Supplier (#1 Priority)
                </label>
                <p className="text-xs text-muted-foreground leading-relaxed">
                  Designating this vendor as the preferred supplier (#1) makes
                  it the default source on automated purchase recommendations.
                  Only one active supplier can be preferred per item.
                </p>
              </div>
            </div>

            {!isPreferred && (
              <div className="pt-2 border-t">
                <FormField
                  control={form.control}
                  name="priority"
                  render={({ field }) => (
                    <FormItem className="space-y-1.5">
                      <FormLabel className="text-xs font-medium text-foreground">
                        Secondary priority ranking
                      </FormLabel>
                      <FormControl>
                        <Input
                          type="number"
                          min="2"
                          max="32767"
                          placeholder="e.g. 2, 3 (Optional rank for alternative suppliers)"
                          className="h-10"
                          {...field}
                        />
                      </FormControl>
                      <FormDescription className="text-xs text-muted-foreground">
                        Leave blank for standard alternative supplier with
                        unranked priority.
                      </FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </div>
            )}
          </div>
        </DetailSection>

        {/* Section 3: Sourcing Terms & UoM Conversion */}
        <DetailSection
          title="Sourcing terms & delivery parameters"
          description="Default order quantities, units of measure, and supplier-specific delivery lead times."
        >
          <FormField
            control={form.control}
            name="purchase_uom_id"
            render={({ field }) => (
              <FormItem className="sm:col-span-2">
                <FormLabel className="text-xs font-medium text-foreground">
                  Purchase Unit (UoM)
                </FormLabel>
                <FormControl>
                  <Combobox
                    {...lookupState(uomsResult, "units")}
                    ref={field.ref}
                    value={field.value}
                    onValueChange={field.onChange}
                    options={[
                      { value: NONE, label: "Use item stock unit (Default)" },
                      ...uoms.map((u) => ({
                        value: String(u.id),
                        label: `${u.name} (${u.code})`,
                      })),
                    ]}
                    searchPlaceholder="Search units…"
                    emptyText="No units found."
                    className="h-10"
                  />
                </FormControl>
                <FormDescription className="text-xs text-muted-foreground leading-relaxed">
                  The packaging or ordering unit used on purchase orders. If
                  different from the item stock unit, automated conversion rates
                  will apply upon receipt.
                </FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />

          <FormField
            control={form.control}
            name="minimum_order_qty"
            render={({ field }) => (
              <FormItem>
                <FormLabel className="text-xs font-medium text-foreground">
                  Minimum Order Quantity (MOQ)
                </FormLabel>
                <FormControl>
                  <DecimalInput
                    rule={{ spec: "stockLedger", sign: "positive" }}
                    placeholder="e.g. 100.0000"
                    className="h-10"
                    {...field}
                  />
                </FormControl>
                <FormDescription className="text-xs text-muted-foreground">
                  Smallest allowable order batch in purchase units.
                </FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />

          <FormField
            control={form.control}
            name="lead_time_days"
            render={({ field }) => (
              <FormItem>
                <FormLabel className="text-xs font-medium text-foreground">
                  Lead Time Override (days)
                </FormLabel>
                <FormControl>
                  <Input
                    type="number"
                    min="0"
                    max="32767"
                    placeholder="Leave blank to use account default"
                    className="h-10"
                    {...field}
                  />
                </FormControl>
                <FormDescription className="text-xs text-muted-foreground">
                  {selectedSupplierAccount?.lead_time_days != null
                    ? `Supplier account default is ${selectedSupplierAccount.lead_time_days} days.`
                    : "No supplier account default set."}
                </FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />

          {/* Lead time preview helper */}
          <div className="sm:col-span-2 rounded-lg border border-primary/20 bg-primary/5 p-3 text-xs text-foreground flex items-center gap-2.5">
            <Clock className="h-4 w-4 text-primary shrink-0" />
            <div>
              {watchedLeadTime ? (
                <span>
                  Expected delivery: <strong>{watchedLeadTime} days</strong>{" "}
                  (Custom relationship override active).
                </span>
              ) : selectedSupplierAccount?.lead_time_days != null ? (
                <span>
                  Expected delivery:{" "}
                  <strong>{selectedSupplierAccount.lead_time_days} days</strong>{" "}
                  (Inherited from supplier account default).
                </span>
              ) : (
                <span>
                  No lead time specified. Lead time will default to 0 days
                  during order scheduling.
                </span>
              )}
            </div>
          </div>

          <FormField
            control={form.control}
            name="active"
            render={({ field }) => (
              <FormItem className="sm:col-span-2 flex items-center gap-3.5 space-y-0 rounded-lg border p-4 shadow-xs bg-muted/20">
                <FormControl>
                  <Checkbox
                    checked={field.value}
                    onCheckedChange={(v) => field.onChange(!!v)}
                  />
                </FormControl>
                <div className="space-y-0.5">
                  <FormLabel className="cursor-pointer font-semibold text-foreground text-sm">
                    Active relationship
                  </FormLabel>
                  <p className="text-xs text-muted-foreground leading-relaxed">
                    Inactive relationships are retained for audit and purchase
                    history, but hidden from requisition and order line vendor
                    selection.
                  </p>
                </div>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>

        {/* Footer Actions */}
        <div className="flex items-center justify-end gap-3 border-t pt-5">
          {onCancel && (
            <Button
              type="button"
              variant="outline"
              onClick={onCancel}
              disabled={isSubmitting}
            >
              Cancel
            </Button>
          )}
          <Button type="submit" disabled={isSubmitting} className="min-w-32">
            {isSubmitting
              ? mode === "create"
                ? "Creating…"
                : "Saving…"
              : mode === "create"
                ? "Create relationship"
                : "Save sourcing terms"}
          </Button>
        </div>
      </form>
    </Form>
  );
}
