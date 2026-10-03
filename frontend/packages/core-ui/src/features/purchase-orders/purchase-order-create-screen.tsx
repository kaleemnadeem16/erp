import { useState } from "react";
import { useLocation, useNavigate } from "react-router";
import type { FieldPath, UseFormReturn } from "react-hook-form";
import { FilePlus2 } from "lucide-react";
import { usePurchaseOrdersStore } from "@erp/core-api";

import { AdvancedOptionsMenu, type AdvancedOption } from "../../components/ui/advanced-options-menu";
import { PageHeader } from "../../components/page/page-header";
import { RecordSheet } from "../../components/page/record-sheet";
import type { OptionsStyle, Presentation } from "../../lib/presentation";
import { getApiErrorMessage, isApiError } from "../../lib/api-errors";
import {
  PurchaseOrderForm,
  clearUnfinishedPurchaseOrder,
  toPurchaseOrderPayload,
  type PurchaseOrderFormValues,
} from "./purchase-order-form";

const TITLE = "New purchase order";
const DESCRIPTION = "Create a draft supplier commitment. Nothing is frozen until issue.";

/**
 * Both presentations are kept on purpose — switch with one word in
 * `apps/main/src/presentation.ts`. `"sheet"` is for the nested `new` route: the
 * purchase-order list stays mounted (dimmed) behind a side sheet, a click
 * outside it is ignored, and closing is always safe because the form keeps what
 * was entered (see PurchaseOrderForm). `"page"` is the same form on a page of
 * its own.
 */
export function PurchaseOrderCreateScreen({
  presentation = "page",
  optionsStyle = "disclosure",
}: {
  presentation?: Presentation;
  optionsStyle?: OptionsStyle;
}) {
  const navigate = useNavigate();
  const { search } = useLocation();
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
          if (result.status === 201) {
            clearUnfinishedPurchaseOrder();
            navigate({ pathname: `/purchase-orders/${result.data.data.id}`, search });
          }
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

  const form = (
    <PurchaseOrderForm
      onSubmit={handleSubmit}
      isSubmitting={create.isPending}
      formError={formError}
      onAdvancedOptionsChange={setAdvancedOptions}
      optionsStyle={optionsStyle}
    />
  );
  const advancedOptionsMenu = <AdvancedOptionsMenu options={advancedOptions} />;

  if (presentation === "sheet") {
    return (
      <RecordSheet
        title={TITLE}
        description={DESCRIPTION}
        icon={<FilePlus2 />}
        size="xl"
        className="po-detail"
        actions={advancedOptionsMenu}
        dismissOnOutsideClick={false}
        onClose={() => navigate({ pathname: "/purchase-orders", search })}
      >
        {form}
      </RecordSheet>
    );
  }
  return (
    <div className="po-detail space-y-4 p-6">
      <PageHeader
        title={TITLE}
        description={DESCRIPTION}
        breadcrumbs={[
          { label: "Purchase Orders", to: "/purchase-orders" },
          { label: "New" },
        ]}
        actions={advancedOptionsMenu}
      />
      {form}
    </div>
  );
}
