import { usePermission } from "../../hooks/use-permission";
import { CustomFieldInputs } from "../custom-fields/custom-field-inputs";
import { useEffect, useMemo } from "react";
import { useForm, type UseFormReturn } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import {
  useUomsIndex,
  useCategoriesIndex,
  useChartOfAccountsIndex,
  ItemType,
  ValuationMethod,
  AvcoPoolScope,
  ItemTracking,
  Status,
} from "@erp/core-api";

import { Button } from "../../components/ui/button";
import { Input } from "../../components/ui/input";
import { Textarea } from "../../components/ui/textarea";
import { Checkbox } from "../../components/ui/checkbox";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "../../components/ui/select";
import { Combobox } from "../../components/ui/combobox";
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "../../components/ui/form";
import { DetailSection } from "../../components/detail/detail-page-layout";
import { lookupState } from "../../lib/api-errors";

const ITEM_TYPE_OPTIONS = Object.values(ItemType);
const TRACKING_OPTIONS = Object.values(ItemTracking);
const STATUS_OPTIONS = Object.values(Status);

/** Sentinel for "not set" on an optional Select — Radix rejects an empty-string item value. */
export const DEFAULT_OPTION = "DEFAULT" as const;

const itemFormSchema = z
  .object({
    code: z.string().trim().min(1, "Code is required").max(255),
    name: z.string().trim().min(1, "Name is required").max(255),
    description: z.string().max(5000).optional(),
    type: z.enum(ItemType),
    quantity_precision: z
      .string()
      .trim()
      .refine(
        (v) => !v || (/^\d{1,10}(\.\d{1,9})?$/.test(v) && Number(v) > 0),
        "Enter a positive increment with up to 9 decimal places",
      ),
    stock_uom_id: z.string().min(1, "Stock unit of measure is required"),
    category_id: z.string().min(1, "Category is required"),
    is_stocked: z.boolean(),
    is_purchasable: z.boolean(),
    is_sellable: z.boolean(),
    valuation_method: z.enum([
      DEFAULT_OPTION,
      ...Object.values(ValuationMethod),
    ]),
    avco_pool_scope: z.enum([DEFAULT_OPTION, ...Object.values(AvcoPoolScope)]),
    tracking: z.enum(ItemTracking),
    status: z.enum(Status),
    income_account_id: z.string(),
    expense_account_id: z.string(),
    inventory_account_id: z.string(),
    custom_fields: z.record(
      z.string(),
      z.union([z.string(), z.number(), z.boolean()]),
    ),
  })
  .refine(
    (values) =>
      values.valuation_method !== ValuationMethod.AVCO ||
      values.avco_pool_scope !== DEFAULT_OPTION,
    {
      message: "AVCO pool scope is required when valuation method is AVCO.",
      path: ["avco_pool_scope"],
    },
  )
  .refine(
    (values) =>
      values.avco_pool_scope !== AvcoPoolScope.COMPANY_ITEM_BATCH ||
      values.tracking === ItemTracking.BATCH,
    {
      message: "COMPANY_ITEM_BATCH pooling requires BATCH tracking.",
      path: ["avco_pool_scope"],
    },
  );

export type ItemFormValues = z.infer<typeof itemFormSchema>;

/** Field names a 422 response can name — kept in sync with StoreItemRequest/UpdateItemRequest's `rules()`. */
export const ITEM_FORM_FIELDS = [
  "code",
  "name",
  "description",
  "type",
  "stock_uom_id",
  "quantity_precision",
  "category_id",
  "is_stocked",
  "is_purchasable",
  "is_sellable",
  "valuation_method",
  "avco_pool_scope",
  "tracking",
  "status",
  "income_account_id",
  "expense_account_id",
  "inventory_account_id",
  "custom_fields",
] as const satisfies readonly (keyof ItemFormValues)[];

export const ITEM_FORM_DEFAULT_VALUES: ItemFormValues = {
  code: "",
  name: "",
  description: "",
  type: ItemType.RAW,
  stock_uom_id: "",
  quantity_precision: "",
  category_id: "",
  is_stocked: true,
  is_purchasable: true,
  is_sellable: true,
  valuation_method: DEFAULT_OPTION,
  avco_pool_scope: DEFAULT_OPTION,
  tracking: ItemTracking.NONE,
  status: Status.ACTIVE,
  income_account_id: "",
  expense_account_id: "",
  inventory_account_id: "",
  custom_fields: {},
};

/** ItemFormValues -> the request body shape both StoreItemRequest and UpdateItemRequest accept. */
export function toItemPayload(
  values: ItemFormValues,
  mayManageMappings: boolean,
) {
  return {
    code: values.code.trim(),
    name: values.name.trim(),
    description: values.description?.trim() ? values.description.trim() : null,
    type: values.type,
    stock_uom_id: Number(values.stock_uom_id),
    quantity_precision: values.quantity_precision || null,
    category_id: Number(values.category_id),
    is_stocked: values.is_stocked,
    is_purchasable: values.is_purchasable,
    is_sellable: values.is_sellable,
    valuation_method:
      values.valuation_method === DEFAULT_OPTION
        ? null
        : values.valuation_method,
    avco_pool_scope:
      values.avco_pool_scope === DEFAULT_OPTION ? null : values.avco_pool_scope,
    tracking: values.tracking,
    status: values.status,
    custom_fields: values.custom_fields,
    ...(mayManageMappings
      ? {
          income_account_id: values.income_account_id
            ? Number(values.income_account_id)
            : null,
          expense_account_id: values.expense_account_id
            ? Number(values.expense_account_id)
            : null,
          inventory_account_id: values.inventory_account_id
            ? Number(values.inventory_account_id)
            : null,
        }
      : {}),
  };
}

export interface ItemFormProps {
  mode: "create" | "edit";
  defaultValues?: Partial<ItemFormValues>;
  onSubmit: (
    values: ItemFormValues,
    form: UseFormReturn<ItemFormValues>,
  ) => void;
  onCancel?: () => void;
  isSubmitting?: boolean;
  formError?: string | null;
}

/**
 * The shared create/edit form convention: react-hook-form + zod validation,
 * server-side 422 field errors mapped back onto the same fields (see
 * lib/api-errors.ts), sections built from DetailSection so a form reads like
 * the detail page it will become. Reused as-is by ItemCreateScreen and
 * ItemEditScreen — only `defaultValues` and the submit handler differ.
 */
export function ItemForm({
  mode,
  defaultValues,
  onSubmit,
  onCancel,
  isSubmitting,
  formError,
}: ItemFormProps) {
  const mayManageMappings = usePermission("accounting.account-mapping.manage");
  const accountsQuery = useChartOfAccountsIndex(
    { per_page: 100, sort: "code" },
    { query: { enabled: mayManageMappings } },
  );
  const accounts =
    accountsQuery.data?.status === 200 ? accountsQuery.data.data.data : [];
  const form = useForm<ItemFormValues>({
    resolver: zodResolver(itemFormSchema),
    defaultValues: { ...ITEM_FORM_DEFAULT_VALUES, ...defaultValues },
  });

  // Lands keyboard/screen-reader focus on the first field instead of wherever
  // it was left (e.g. the "New item" nav link just activated) — only on
  // create, since an edit screen's first field is rarely the one being changed.
  useEffect(() => {
    if (mode === "create") form.setFocus("code");
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const uomsQuery = useUomsIndex({ per_page: 100, sort: "name" });
  const uoms = uomsQuery.data?.status === 200 ? uomsQuery.data.data.data : [];
  const uomOptions = useMemo(
    () =>
      uoms.map((uom) => ({
        value: String(uom.id),
        label: `${uom.name} (${uom.code})`,
      })),
    [uoms],
  );

  const categoriesQuery = useCategoriesIndex({ per_page: 100, sort: "name" });
  const categories =
    categoriesQuery.data?.status === 200 ? categoriesQuery.data.data.data : [];
  const categoryOptions = useMemo(
    () =>
      categories.map((category) => ({
        value: String(category.id),
        label: category.name,
      })),
    [categories],
  );

  const valuationMethod = form.watch("valuation_method");
  const tracking = form.watch("tracking");

  return (
    <Form {...form}>
      <form
        onSubmit={form.handleSubmit((values) => onSubmit(values, form))}
        className="space-y-6"
      >
        {formError && (
          <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
            {formError}
          </div>
        )}

        <DetailSection title="General">
          <FormField
            control={form.control}
            name="code"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Code</FormLabel>
                <FormControl>
                  <Input {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="name"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Name</FormLabel>
                <FormControl>
                  <Input {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="type"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Type</FormLabel>
                <Select value={field.value} onValueChange={field.onChange}>
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    {ITEM_TYPE_OPTIONS.map((type) => (
                      <SelectItem key={type} value={type}>
                        {type.replace("_", " ")}
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
            name="category_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Category</FormLabel>
                <FormControl>
                  <Combobox
                    {...lookupState(categoriesQuery, "categories")}
                    ref={field.ref}
                    value={field.value}
                    onValueChange={field.onChange}
                    options={categoryOptions}
                    placeholder="Select a category"
                    searchPlaceholder="Search categories…"
                    emptyText="No categories found."
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="description"
            render={({ field }) => (
              <FormItem className="sm:col-span-2">
                <FormLabel>Description</FormLabel>
                <FormControl>
                  <Textarea rows={3} {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </DetailSection>

        <DetailSection title="Stock &amp; valuation">
          <FormField
            control={form.control}
            name="stock_uom_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Stock unit of measure</FormLabel>
                <FormControl>
                  <Combobox
                    {...lookupState(uomsQuery, "units")}
                    ref={field.ref}
                    value={field.value}
                    onValueChange={field.onChange}
                    options={uomOptions}
                    placeholder="Select a unit"
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
            name="quantity_precision"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Stock quantity increment (optional)</FormLabel>
                <FormControl>
                  <Input
                    inputMode="decimal"
                    placeholder="Unrestricted"
                    {...field}
                  />
                </FormControl>
                <p className="text-xs text-muted-foreground">
                  In the stock unit: 1 for whole units, 0.5 for half units.
                  Leave blank for unrestricted quantities. This is not a
                  supplier order multiple.
                </p>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="tracking"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Tracking</FormLabel>
                <Select value={field.value} onValueChange={field.onChange}>
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    {TRACKING_OPTIONS.map((option) => (
                      <SelectItem key={option} value={option}>
                        {option}
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
            name="valuation_method"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Valuation method</FormLabel>
                <Select value={field.value} onValueChange={field.onChange}>
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    {/* UpdateItemRequest doesn't accept null for valuation_method (see item-form.tsx's ITEM_FORM_FIELDS
                        comment) — once an item exists its valuation method can only be changed to another concrete
                        method, never reset back to "let the category decide". Only offer that choice on create. */}
                    {mode === "create" && (
                      <SelectItem value={DEFAULT_OPTION}>
                        Category default
                      </SelectItem>
                    )}
                    {Object.values(ValuationMethod).map((method) => (
                      <SelectItem key={method} value={method}>
                        {method}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>
            )}
          />
          {valuationMethod === ValuationMethod.AVCO && (
            <FormField
              control={form.control}
              name="avco_pool_scope"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>AVCO pool scope</FormLabel>
                  <Select value={field.value} onValueChange={field.onChange}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      <SelectItem value={DEFAULT_OPTION}>
                        Company default
                      </SelectItem>
                      {Object.values(AvcoPoolScope).map((scope) => (
                        <SelectItem
                          key={scope}
                          value={scope}
                          disabled={
                            scope === AvcoPoolScope.COMPANY_ITEM_BATCH &&
                            tracking !== ItemTracking.BATCH
                          }
                        >
                          {scope}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormMessage />
                </FormItem>
              )}
            />
          )}
          <div className="flex flex-col gap-3 sm:col-span-2 sm:flex-row sm:gap-6">
            <FormField
              control={form.control}
              name="is_stocked"
              render={({ field }) => (
                <FormItem className="flex flex-row items-center gap-2 space-y-0">
                  <FormControl>
                    <Checkbox
                      checked={field.value}
                      onCheckedChange={field.onChange}
                    />
                  </FormControl>
                  <FormLabel className="font-normal">Stocked</FormLabel>
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="is_purchasable"
              render={({ field }) => (
                <FormItem className="flex flex-row items-center gap-2 space-y-0">
                  <FormControl>
                    <Checkbox
                      checked={field.value}
                      onCheckedChange={field.onChange}
                    />
                  </FormControl>
                  <FormLabel className="font-normal">Purchasable</FormLabel>
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="is_sellable"
              render={({ field }) => (
                <FormItem className="flex flex-row items-center gap-2 space-y-0">
                  <FormControl>
                    <Checkbox
                      checked={field.value}
                      onCheckedChange={field.onChange}
                    />
                  </FormControl>
                  <FormLabel className="font-normal">Sellable</FormLabel>
                </FormItem>
              )}
            />
          </div>
        </DetailSection>

        {mayManageMappings && (
          <DetailSection
            title="Account mappings"
            description="Leave blank to inherit the category's account defaults."
          >
            {(
              [
                "income_account_id",
                "expense_account_id",
                "inventory_account_id",
              ] as const
            ).map((name) => (
              <FormField
                key={name}
                control={form.control}
                name={name}
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>
                      {name === "income_account_id"
                        ? "Income account"
                        : name === "expense_account_id"
                          ? "Expense account"
                          : "Inventory account"}
                    </FormLabel>
                    <FormControl>
                      <Combobox
                        {...lookupState(accountsQuery, "accounts")}
                        value={field.value}
                        onValueChange={field.onChange}
                        options={[
                          { value: "", label: "Category default" },
                          ...accounts.map((account) => ({
                            value: String(account.id),
                            label: `${account.code} — ${account.name}`,
                          })),
                        ]}
                        placeholder="Category default"
                        searchPlaceholder="Search accounts…"
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            ))}
          </DetailSection>
        )}
        <FormField
          control={form.control}
          name="custom_fields"
          render={({ field }) => (
            <FormItem>
              <CustomFieldInputs
                entity="items"
                value={field.value}
                onChange={field.onChange}
              />
              <FormMessage />
            </FormItem>
          )}
        />
        {
          <DetailSection title="Status">
            <FormField
              control={form.control}
              name="status"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Status</FormLabel>
                  <Select value={field.value} onValueChange={field.onChange}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {STATUS_OPTIONS.map((status) => (
                        <SelectItem key={status} value={status}>
                          {status}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormMessage />
                </FormItem>
              )}
            />
          </DetailSection>
        }

        {mode === "create" && (
          <p className="text-sm text-muted-foreground">
            After saving, add pack conversions, packages and supplier offers on
            the item detail screen.
          </p>
        )}
        <div className="flex justify-end gap-2">
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
              ? "Saving…"
              : mode === "create"
                ? "Create item"
                : "Save changes"}
          </Button>
        </div>
      </form>
    </Form>
  );
}
