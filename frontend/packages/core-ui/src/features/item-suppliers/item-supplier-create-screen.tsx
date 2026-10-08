import { useState } from "react";
import { useLocation, useNavigate } from "react-router";
import type { UseFormReturn } from "react-hook-form";
import { useItemSuppliersStore } from "@erp/core-api";
import { PageHeader } from "../../components/page/page-header";
import { RecordSheet } from "../../components/page/record-sheet";
import { ScreenTemplate } from "../../components/page/screen-template";
import { applyApiErrorsToForm } from "../../lib/api-errors";
import type { Presentation } from "../../lib/presentation";
import {
  clearUnfinishedItemSupplier,
  ITEM_SUPPLIER_FORM_FIELDS,
  ItemSupplierForm,
  itemSupplierPayload,
  type ItemSupplierFormValues,
} from "./item-supplier-form";

export interface ItemSupplierCreateScreenProps {
  presentation?: Presentation;
}

export function ItemSupplierCreateScreen({
  presentation = "sheet",
}: ItemSupplierCreateScreenProps) {
  const navigate = useNavigate();
  const { search } = useLocation();
  const mutation = useItemSuppliersStore();
  const [error, setError] = useState<string | null>(null);

  const submit = (
    v: ItemSupplierFormValues,
    form: UseFormReturn<ItemSupplierFormValues>,
  ) => {
    setError(null);
    mutation.mutate(
      {
        data: {
          item_id: Number(v.item_id),
          supplier_account_id: Number(v.supplier_account_id),
          ...itemSupplierPayload(v),
        },
      },
      {
        onSuccess: (r) => {
          if (r.status === 201) {
            clearUnfinishedItemSupplier();
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

  const handleClose = () => {
    navigate({ pathname: "/item-suppliers", search });
  };

  const formElement = (
    <ItemSupplierForm
      mode="create"
      onSubmit={submit}
      onCancel={handleClose}
      isSubmitting={mutation.isPending}
      formError={error}
    />
  );

  if (presentation === "sheet") {
    return (
      <RecordSheet
        title="New item supplier"
        description="Approve a supplier for an item and record its sourcing terms."
        size="lg"
        className="erp-record"
        dismissOnOutsideClick={false}
        onClose={handleClose}
      >
        <div className="space-y-4 p-4 pb-6">{formElement}</div>
      </RecordSheet>
    );
  }

  return (
    <ScreenTemplate kind="form" className="erp-screen">
      <div className="space-y-4">
        <PageHeader
          title="New item supplier"
          description="Approve a supplier for an item and record its sourcing terms."
          backTo="/item-suppliers"
          breadcrumbs={[
            { label: "Item suppliers", to: "/item-suppliers" },
            { label: "New" },
          ]}
        />
        {formElement}
      </div>
    </ScreenTemplate>
  );
}
