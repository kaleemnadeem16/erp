import { useState } from "react";
import type { UseFormReturn } from "react-hook-form";
import {
  useItemSuppliersIndex,
  useItemSuppliersStore,
  useItemSuppliersUpdate,
  type ItemSupplierResource,
} from "@erp/core-api";
import { Button } from "../../components/ui/button";
import { usePermission } from "../../hooks/use-permission";
import { applyApiErrorsToForm, getApiErrorMessage } from "../../lib/api-errors";
import {
  ItemSupplierForm,
  itemSupplierPayload,
  ITEM_SUPPLIER_FORM_FIELDS,
  type ItemSupplierFormValues,
} from "../item-suppliers/item-supplier-form";

/** This item's identity comes from its detail route, never the list URL or editable form fields. */
export function ItemSupplierPanel({
  itemId,
  itemLabel,
}: {
  itemId: number;
  itemLabel: string;
}) {
  const mayManage = usePermission("purchasing.item-supplier.manage");
  const supplierView = usePermission("purchasing.item-supplier.view");
  const orderView = usePermission("purchasing.purchase-order.view");
  const orderCreate = usePermission("purchasing.purchase-order.create");
  const mayRead = supplierView || mayManage || orderView || orderCreate;
  const [page, setPage] = useState(1);
  const [editor, setEditor] = useState<ItemSupplierResource | "new" | null>(
    null,
  );
  const [error, setError] = useState<string | null>(null);
  const query = useItemSuppliersIndex(
    {
      "filter[item_id]": itemId,
      include: "supplierAccount,purchaseUom",
      sort: "priority",
      per_page: 25,
      page,
    },
    { query: { enabled: mayRead } },
  );
  const create = useItemSuppliersStore();
  const update = useItemSuppliersUpdate();
  const busy = create.isPending || update.isPending;
  if (!mayRead) return null;
  const rows = query.data?.status === 200 ? query.data.data.data : [];
  const lastPage =
    query.data?.status === 200 ? query.data.data.meta.last_page : 1;
  const open = (row: ItemSupplierResource | "new") => {
    setError(null);
    setEditor(row);
  };
  const saved = () => {
    setEditor(null);
    setError(null);
    void query.refetch();
  };
  const submit = (
    values: ItemSupplierFormValues,
    form: UseFormReturn<ItemSupplierFormValues>,
  ) => {
    setError(null);
    const onError = (cause: unknown) =>
      setError(
        applyApiErrorsToForm(cause, form.setError, ITEM_SUPPLIER_FORM_FIELDS),
      );
    if (editor === "new") {
      create.mutate(
        {
          data: {
            item_id: itemId,
            supplier_account_id: Number(values.supplier_account_id),
            ...itemSupplierPayload(values),
          },
        },
        { onSuccess: saved, onError },
      );
    } else if (editor) {
      update.mutate(
        { itemSupplier: editor.id, data: itemSupplierPayload(values) },
        { onSuccess: saved, onError },
      );
    }
  };
  const toggle = (row: ItemSupplierResource) => {
    setError(null);
    update.mutate(
      { itemSupplier: row.id, data: { active: !row.active } },
      {
        onSuccess: saved,
        onError: (cause) => setError(getApiErrorMessage(cause)),
      },
    );
  };
  const editing = editor && editor !== "new" ? editor : null;
  return (
    <section
      aria-label="Supplier relationships"
      className="space-y-3 rounded-md border p-4"
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-medium">Supplier relationships</h3>
        {mayManage && !editor && (
          <Button type="button" size="sm" onClick={() => open("new")}>
            Add supplier relationship
          </Button>
        )}
      </div>
      <p className="text-sm text-muted-foreground">
        Approve suppliers for this item and set their supplier code, sourcing
        priority, lead time and default purchase unit. Package-specific prices
        and ordering rules belong to the offers below.
      </p>
      {query.isLoading ? (
        <p role="status">Loading supplier relationships…</p>
      ) : query.isError ? (
        <p role="alert" className="text-destructive">
          {getApiErrorMessage(query.error)}
        </p>
      ) : rows.length === 0 ? (
        <p className="text-sm">No supplier relationships for this item.</p>
      ) : (
        <ul className="space-y-2">
          {rows.map((row) => {
            const supplier =
              row.supplier_account?.code ?? String(row.supplier_account_id);
            return (
              <li
                key={row.id}
                className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3"
              >
                <div className="text-sm">
                  <p className="font-medium">
                    {supplier}
                    {row.supplier_account?.party?.name
                      ? " — " + row.supplier_account.party.name
                      : ""}{" "}
                    · {row.active ? "Active" : "Archived"}
                  </p>
                  <p>
                    Supplier code: {row.supplier_item_code ?? "—"} · Priority:{" "}
                    {row.priority ?? "—"} · Lead time:{" "}
                    {row.resolved_lead_time_days ?? "—"} days · Purchase unit:{" "}
                    {row.purchase_uom?.code ?? "Use stock unit"} · Minimum:{" "}
                    {row.minimum_order_qty ?? "—"}
                  </p>
                </div>
                {mayManage && (
                  <div className="flex gap-2">
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      disabled={busy || !!editor}
                      aria-label={"Edit relationship " + supplier}
                      onClick={() => open(row)}
                    >
                      Edit
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      disabled={busy || !!editor}
                      aria-label={
                        (row.active ? "Archive" : "Restore") +
                        " relationship " +
                        supplier
                      }
                      onClick={() => toggle(row)}
                    >
                      {row.active ? "Archive" : "Restore"}
                    </Button>
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      )}
      {lastPage > 1 && (
        <div className="flex items-center gap-2">
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={page === 1 || !!editor}
            onClick={() => setPage(page - 1)}
          >
            Previous suppliers
          </Button>
          <span className="text-sm">
            Page {page} of {lastPage}
          </span>
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={page >= lastPage || !!editor}
            onClick={() => setPage(page + 1)}
          >
            Next suppliers
          </Button>
        </div>
      )}
      {error && !editor && (
        <p role="alert" className="text-destructive">
          {error}
        </p>
      )}
      {editor && mayManage && (
        <div className="space-y-3 border-t pt-4">
          <div className="flex items-center justify-between">
            <h4 className="font-medium">
              {editing
                ? "Edit supplier relationship"
                : "New supplier relationship"}
            </h4>
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={busy}
              onClick={() => {
                setEditor(null);
                setError(null);
              }}
            >
              Cancel relationship edit
            </Button>
          </div>
          <ItemSupplierForm
            key={editing?.id ?? "new"}
            mode={editing ? "edit" : "create"}
            fixedItem={{ id: itemId, label: itemLabel }}
            defaultValues={
              editing
                ? {
                    supplier_account_id: String(editing.supplier_account_id),
                    supplier_item_code: editing.supplier_item_code ?? "",
                    priority:
                      editing.priority == null ? "" : String(editing.priority),
                    lead_time_days:
                      editing.lead_time_days == null
                        ? ""
                        : String(editing.lead_time_days),
                    minimum_order_qty: editing.minimum_order_qty ?? "",
                    purchase_uom_id:
                      editing.purchase_uom_id == null
                        ? "NONE"
                        : String(editing.purchase_uom_id),
                    active: editing.active,
                  }
                : undefined
            }
            onSubmit={submit}
            isSubmitting={busy}
            formError={error}
          />
        </div>
      )}
    </section>
  );
}
