import { usePermission } from "../../hooks/use-permission";
import { useState } from "react";
import { useLocation, useNavigate } from "react-router";
import type { UseFormReturn } from "react-hook-form";
import { useItemsStore } from "@erp/core-api";

import { PageHeader } from "../../components/page/page-header";
import { ScreenTemplate } from "../../components/page/screen-template";
import { RecordSheet } from "../../components/page/record-sheet";
import { applyApiErrorsToForm } from "../../lib/api-errors";
import type { Presentation } from "../../lib/presentation";
import {
  ItemForm,
  ITEM_FORM_FIELDS,
  toItemPayload,
  type ItemFormValues,
} from "./item-form";

export interface ItemCreateScreenProps {
  presentation?: Presentation;
}

export function ItemCreateScreen({
  presentation = "page",
}: ItemCreateScreenProps) {
  const navigate = useNavigate();
  const { search } = useLocation();
  const mayManageMappings = usePermission("accounting.account-mapping.manage");
  const [formError, setFormError] = useState<string | null>(null);
  const createItem = useItemsStore();

  const handleClose = () => {
    navigate({ pathname: "/items", search });
  };

  function handleSubmit(
    values: ItemFormValues,
    form: UseFormReturn<ItemFormValues>,
  ) {
    setFormError(null);
    createItem.mutate(
      { data: toItemPayload(values, mayManageMappings) },
      {
        onSuccess: (result) => {
          if (result.status === 201) {
            navigate({ pathname: `/items/${result.data.data.id}`, search });
          }
        },
        onError: (error) => {
          setFormError(
            applyApiErrorsToForm(error, form.setError, ITEM_FORM_FIELDS),
          );
        },
      },
    );
  }

  const formBody = (
    <ItemForm
      mode="create"
      onSubmit={handleSubmit}
      onCancel={handleClose}
      isSubmitting={createItem.isPending}
      formError={formError}
    />
  );

  if (presentation === "sheet") {
    return (
      <RecordSheet
        title="New item"
        description="Add something the company buys, holds, makes, consumes or sells."
        size="xl"
        className="erp-record"
        onClose={handleClose}
      >
        <div className="space-y-4 pb-4">{formBody}</div>
      </RecordSheet>
    );
  }

  return (
    <ScreenTemplate kind="form" className="erp-screen">
      <div className="space-y-4">
        <PageHeader
          title="New item"
          description="Add something the company buys, holds, makes, consumes or sells."
          breadcrumbs={[{ label: "Items", to: "/items" }, { label: "New" }]}
          backTo="/items"
        />
        {formBody}
      </div>
    </ScreenTemplate>
  );
}
