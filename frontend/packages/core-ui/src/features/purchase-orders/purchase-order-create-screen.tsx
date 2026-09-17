import { useState } from "react";
import { useNavigate } from "react-router";
import type { FieldPath, UseFormReturn } from "react-hook-form";
import { usePurchaseOrdersStore } from "@erp/core-api";

import { AdvancedOptionsMenu, type AdvancedOption } from "../../components/ui/advanced-options-menu";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "../../components/ui/sheet";
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
  const [advancedOptions, setAdvancedOptions] = useState<AdvancedOption[]>([]);

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
    <Sheet
      open
      onOpenChange={(open) => {
        if (!open) navigate("/purchase-orders");
      }}
    >
      <SheetContent
        side="right"
        className="flex w-full flex-col gap-0 sm:max-w-2xl"
      >
        <SheetHeader className="shrink-0 border-b border-border pb-4">
          <div className="flex items-start justify-between gap-2">
            <div>
              <SheetTitle>New purchase order</SheetTitle>
              <SheetDescription>
                Create a draft supplier commitment. Nothing is frozen until
                issue.
              </SheetDescription>
            </div>
            <AdvancedOptionsMenu options={advancedOptions} />
          </div>
        </SheetHeader>
        <div className="min-h-0 flex-1 overflow-y-auto pt-4">
          <PurchaseOrderForm
            onSubmit={handleSubmit}
            isSubmitting={create.isPending}
            formError={formError}
            onAdvancedOptionsChange={setAdvancedOptions}
          />
        </div>
      </SheetContent>
    </Sheet>
  );
}
