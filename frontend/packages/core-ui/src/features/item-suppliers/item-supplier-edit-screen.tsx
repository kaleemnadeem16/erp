import { useState } from "react";
import { useLocation, useNavigate, useParams } from "react-router";
import type { UseFormReturn } from "react-hook-form";
import { useItemSuppliersShow, useItemSuppliersUpdate } from "@erp/core-api";
import { Button } from "../../components/ui/button";
import { Skeleton } from "../../components/ui/skeleton";
import { PageHeader } from "../../components/page/page-header";
import { RecordSheet } from "../../components/page/record-sheet";
import { ScreenTemplate } from "../../components/page/screen-template";
import { applyApiErrorsToForm } from "../../lib/api-errors";
import type { Presentation } from "../../lib/presentation";
import {
  ITEM_SUPPLIER_FORM_FIELDS,
  ItemSupplierForm,
  itemSupplierPayload,
  type ItemSupplierFormValues,
} from "./item-supplier-form";

export interface ItemSupplierEditScreenProps {
  presentation?: Presentation;
}

export function ItemSupplierEditScreen({
  presentation = "sheet",
}: ItemSupplierEditScreenProps) {
  const { id } = useParams<{ id: string }>();
  const relationshipId = Number(id);
  const navigate = useNavigate();
  const { search } = useLocation();
  const query = useItemSuppliersShow(relationshipId, {
    include: "item,supplierAccount,purchaseUom",
  });
  const row = query.data?.status === 200 ? query.data.data.data : undefined;
  const mutation = useItemSuppliersUpdate();
  const [error, setError] = useState<string | null>(null);

  const handleClose = () => {
    navigate({ pathname: `/item-suppliers/${relationshipId}`, search });
  };

  const submit = (
    v: ItemSupplierFormValues,
    form: UseFormReturn<ItemSupplierFormValues>,
  ) => {
    setError(null);
    mutation.mutate(
      { itemSupplier: relationshipId, data: itemSupplierPayload(v) },
      {
        onSuccess: (r) => {
          if (r.status === 200) {
            navigate({ pathname: `/item-suppliers/${r.data.data.id}`, search });
          }
        },
        onError: (e) =>
          setError(
            applyApiErrorsToForm(e, form.setError, ITEM_SUPPLIER_FORM_FIELDS),
          ),
      },
    );
  };

  function wrap(
    content: React.ReactNode,
    title: string = `Edit sourcing terms`,
  ) {
    if (presentation === "sheet") {
      return (
        <RecordSheet
          title={title}
          description={
            row
              ? `${row.item?.code ?? row.item_id} • ${row.supplier_account?.code ?? row.supplier_account_id}`
              : undefined
          }
          size="lg"
          className="erp-record"
          dismissOnOutsideClick={false}
          onClose={handleClose}
        >
          <div className="space-y-4 pb-4">{content}</div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="form" className="erp-screen">
        <div className="space-y-4">
          <PageHeader
            title={title}
            description={
              row
                ? `${row.item?.code ?? row.item_id} • ${row.supplier_account?.code ?? row.supplier_account_id}`
                : undefined
            }
            backTo={`/item-suppliers/${relationshipId}`}
            breadcrumbs={[
              { label: "Item suppliers", to: "/item-suppliers" },
              {
                label: String(relationshipId),
                to: `/item-suppliers/${relationshipId}`,
              },
              { label: "Edit" },
            ]}
          />
          {content}
        </div>
      </ScreenTemplate>
    );
  }

  if (query.isLoading) {
    return wrap(
      <div className="space-y-4">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>,
    );
  }

  if (!row || query.isError) {
    return wrap(
      <div className="space-y-4">
        <p className="text-sm text-destructive">
          Couldn't load this item-supplier relationship.
        </p>
        <Button
          variant="outline"
          onClick={() => navigate({ pathname: "/item-suppliers", search })}
        >
          Back to item suppliers
        </Button>
      </div>,
    );
  }

  return wrap(
    <ItemSupplierForm
      mode="edit"
      itemDetails={
        row.item
          ? {
              id: row.item.id,
              code: row.item.code,
              name: row.item.name,
              stock_uom_id: row.item.stock_uom_id,
              category: row.item.category
                ? { name: row.item.category.name, code: row.item.category.code }
                : null,
            }
          : undefined
      }
      supplierDetails={
        row.supplier_account
          ? {
              id: row.supplier_account.id,
              code: row.supplier_account.code,
              party_name: row.supplier_account.party?.name,
              payment_terms_days: row.supplier_account.payment_terms_days,
              lead_time_days: row.supplier_account.lead_time_days,
            }
          : undefined
      }
      defaultValues={{
        item_id: String(row.item_id),
        supplier_account_id: String(row.supplier_account_id),
        supplier_item_code: row.supplier_item_code ?? "",
        priority: row.priority == null ? "" : String(row.priority),
        lead_time_days:
          row.lead_time_days == null ? "" : String(row.lead_time_days),
        minimum_order_qty: row.minimum_order_qty ?? "",
        purchase_uom_id:
          row.purchase_uom_id == null ? "NONE" : String(row.purchase_uom_id),
        active: row.active,
      }}
      onSubmit={submit}
      onCancel={handleClose}
      isSubmitting={mutation.isPending}
      formError={error}
    />,
  );
}
