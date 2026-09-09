import { useState } from "react";
import { useNavigate } from "react-router";
import type { FieldPath, UseFormReturn } from "react-hook-form";
import { usePurchaseOrdersStore } from "@erp/core-api";

import { PageHeader } from "../../components/page/page-header";
import { getApiErrorMessage, isApiError } from "../../lib/api-errors";
import {
  PurchaseOrderForm,
  toPurchaseOrderPayload,
  type PurchaseOrderFormValues,
} from "./purchase-order-form";

export function PurchaseOrderCreateScreen() {
  const navigate = useNavigate();
  const create = usePurchaseOrdersStore();
  const [formError, setFormError] = useState<string | null>(null);

  function handleSubmit(
    values: PurchaseOrderFormValues,
    form: UseFormReturn<PurchaseOrderFormValues>,
  ) {
    setFormError(null);
    create.mutate(
      { data: toPurchaseOrderPayload(values) },
      {
        onSuccess: (result) => {
          if (result.status === 201)
            navigate(`/purchase-orders/${result.data.data.id}`);
        },
        onError: (error) => {
          if (isApiError(error) && error.body?.errors) {
            for (const [field, messages] of Object.entries(error.body.errors))
              form.setError(field as FieldPath<PurchaseOrderFormValues>, {
                type: "server",
                message: messages[0],
              });
          } else setFormError(getApiErrorMessage(error));
        },
      },
    );
  }

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="New purchase order"
        description="Create a draft supplier commitment. Nothing is frozen until issue."
        breadcrumbs={[
          { label: "Purchase Orders", to: "/purchase-orders" },
          { label: "New" },
        ]}
      />
      <PurchaseOrderForm
        onSubmit={handleSubmit}
        isSubmitting={create.isPending}
        formError={formError}
      />
    </div>
  );
}
