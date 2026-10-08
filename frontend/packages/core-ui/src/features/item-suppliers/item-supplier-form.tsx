import { useEffect, useState } from "react";
import { useForm, type UseFormReturn } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import {
  useItemsIndex,
  useSupplierAccountsIndex,
  useUomsIndex,
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
import { AlertCircle, RotateCcw } from "lucide-react";

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
  minimum_order_qty: z
    .string()
    .refine(
      (v) => !v || (/^\d{1,15}(\.\d{1,4})?$/.test(v) && Number(v) > 0),
      "Enter a positive quantity with up to 4 decimal places",
    ),
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
    minimum_order_qty: stored.minimum_order_qty ?? "",
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

  const itemsResult = useItemsIndex(
    { per_page: 100, sort: "name" },
    { query: { enabled: !fixedItem && mode === "create" } },
  );
  const items =
    itemsResult.data?.status === 200 ? itemsResult.data.data.data : [];

  const suppliersResult = useSupplierAccountsIndex({
    per_page: 100,
    sort: "code",
    include: "party",
  });
  const suppliers =
    suppliersResult.data?.status === 200 ? suppliersResult.data.data.data : [];

  const uomsResult = useUomsIndex({ per_page: 100, sort: "name" });
  const uoms = uomsResult.data?.status === 200 ? uomsResult.data.data.data : [];

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
    options: { id: number; label: string }[],
    lookup: LookupState,
  ) => (
    <FormField
      control={form.control}
      name={name}
      render={({ field }) => (
        <FormItem>
          <FormLabel>{label}</FormLabel>
          <FormControl>
            <Combobox
              {...lookup}
              ref={field.ref}
              disabled={mode === "edit"}
              value={field.value}
              onValueChange={field.onChange}
              options={options.map((o) => ({
                value: String(o.id),
                label: o.label,
              }))}
              placeholder={`Select ${label.toLowerCase()}`}
              searchPlaceholder={`Search ${label.toLowerCase()}s…`}
              emptyText={`No ${label.toLowerCase()}s found.`}
            />
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
          <div className="flex items-center justify-between gap-3 rounded-lg border border-primary/20 bg-primary/5 p-3 text-sm text-foreground">
            <div className="flex items-center gap-2">
              <RotateCcw className="h-4 w-4 text-primary shrink-0" />
              <span>
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
          <div className="flex items-start gap-2.5 rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
            <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
            <div>{formError}</div>
          </div>
        )}

        <DetailSection
          title="Relationship"
          description={
            mode === "edit"
              ? "Item and supplier are permanent identifiers. To repoint, archive this relationship and create another."
              : "Choose the catalog item and supplier account to associate."
          }
        >
          {fixedItem ? (
            <div className="space-y-1">
              <p className="text-sm font-medium text-muted-foreground">Item</p>
              <p className="text-sm font-semibold text-foreground">
                {fixedItem.label}
              </p>
            </div>
          ) : (
            select(
              "item_id",
              "Item",
              items.map((i) => ({ id: i.id, label: `${i.code} — ${i.name}` })),
              lookupState(itemsResult, "items"),
            )
          )}

          {select(
            "supplier_account_id",
            "Supplier",
            suppliers.map((s) => ({
              id: s.id,
              label: `${s.code}${s.party?.name ? ` — ${s.party.name}` : ""}`,
            })),
            lookupState(suppliersResult, "suppliers"),
          )}

          <FormField
            control={form.control}
            name="supplier_item_code"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Supplier item code (SKU / Part #)</FormLabel>
                <FormControl>
                  <Input placeholder="e.g. ACM-PART-100" {...field} />
                </FormControl>
                <FormDescription>
                  The catalog code used by the supplier on invoices and purchase
                  orders.
                </FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />

          <FormField
            control={form.control}
            name="priority"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Priority</FormLabel>
                <FormControl>
                  <Input
                    type="number"
                    min="1"
                    max="32767"
                    placeholder="e.g. 1"
                    {...field}
                  />
                </FormControl>
                <FormDescription>
                  Set to 1 to designate as the preferred supplier for this item.
                </FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>

        <DetailSection
          title="Sourcing terms"
          description="Default ordering parameters and delivery expectations for this item."
        >
          <FormField
            control={form.control}
            name="lead_time_days"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Lead time override (days)</FormLabel>
                <FormControl>
                  <Input
                    type="number"
                    min="0"
                    max="32767"
                    placeholder="Leave blank to use supplier default"
                    {...field}
                  />
                </FormControl>
                <FormDescription>
                  If left blank, the supplier account's default lead time
                  applies.
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
                <FormLabel>Minimum order quantity (MOQ)</FormLabel>
                <FormControl>
                  <Input
                    inputMode="decimal"
                    placeholder="e.g. 50.0000"
                    {...field}
                  />
                </FormControl>
                <FormDescription>
                  Smallest acceptable order quantity in purchase units.
                </FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />

          <FormField
            control={form.control}
            name="purchase_uom_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Purchase unit</FormLabel>
                <FormControl>
                  <Combobox
                    {...lookupState(uomsResult, "units")}
                    ref={field.ref}
                    value={field.value}
                    onValueChange={field.onChange}
                    options={[
                      { value: NONE, label: "Use item stock unit" },
                      ...uoms.map((u) => ({
                        value: String(u.id),
                        label: `${u.name} (${u.code})`,
                      })),
                    ]}
                    searchPlaceholder="Search units…"
                    emptyText="No units found."
                  />
                </FormControl>
                <FormDescription>
                  Unit of measure when purchasing from this supplier.
                </FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />

          <FormField
            control={form.control}
            name="active"
            render={({ field }) => (
              <FormItem className="flex items-center gap-3 space-y-0 rounded-lg border p-3.5 shadow-xs bg-muted/20">
                <FormControl>
                  <Checkbox
                    checked={field.value}
                    onCheckedChange={(v) => field.onChange(!!v)}
                  />
                </FormControl>
                <div className="space-y-0.5">
                  <FormLabel className="cursor-pointer font-medium">
                    Active relationship
                  </FormLabel>
                  <p className="text-xs text-muted-foreground">
                    Inactive relationships are hidden from purchase order
                    selection.
                  </p>
                </div>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>

        <div className="flex items-center justify-end gap-2 border-t pt-4">
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
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting
              ? mode === "create"
                ? "Creating…"
                : "Saving…"
              : mode === "create"
                ? "Create relationship"
                : "Save changes"}
          </Button>
        </div>
      </form>
    </Form>
  );
}
