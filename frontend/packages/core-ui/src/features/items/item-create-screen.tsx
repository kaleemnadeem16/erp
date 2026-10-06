import { usePermission } from "../../hooks/use-permission";
import { useState } from "react";
import { useNavigate } from "react-router";
import type { UseFormReturn } from "react-hook-form";
import { useItemsStore } from "@erp/core-api";

import { PageHeader } from "../../components/page/page-header";
import { applyApiErrorsToForm } from "../../lib/api-errors";
import {
  ItemForm,
  ITEM_FORM_FIELDS,
  toItemPayload,
  type ItemFormValues,
} from "./item-form";

export function ItemCreateScreen() {
  const navigate = useNavigate();
  const mayManageMappings = usePermission("accounting.account-mapping.manage");
  const [formError, setFormError] = useState<string | null>(null);
  const createItem = useItemsStore();

  function handleSubmit(
    values: ItemFormValues,
    form: UseFormReturn<ItemFormValues>,
  ) {
    setFormError(null);
    createItem.mutate(
      { data: toItemPayload(values, mayManageMappings) },
      {
        onSuccess: (result) => {
          if (result.status === 201) navigate(`/items/${result.data.data.id}`);
        },
        onError: (error) => {
          setFormError(
            applyApiErrorsToForm(error, form.setError, ITEM_FORM_FIELDS),
          );
        },
      },
    );
  }

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="New item"
        description="Add something the company buys, holds, makes, consumes or sells."
        breadcrumbs={[{ label: "Items", to: "/items" }, { label: "New" }]}
        backTo="/items"
      />
      <ItemForm
        mode="create"
        onSubmit={handleSubmit}
        onCancel={() => navigate("/items")}
        isSubmitting={createItem.isPending}
        formError={formError}
      />
    </div>
  );
}
