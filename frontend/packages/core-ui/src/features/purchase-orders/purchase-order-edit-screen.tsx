import { useState } from "react";
import { useLocation, useNavigate, useParams } from "react-router";
import type { FieldPath, UseFormReturn } from "react-hook-form";
import { usePurchaseOrdersShow, usePurchaseOrdersUpdate } from "@erp/core-api";

import {
  AdvancedOptionsMenu,
  type AdvancedOption,
} from "../../components/ui/advanced-options-menu";
import { Button } from "../../components/ui/button";
import { PageHeader } from "../../components/page/page-header";
import { Skeleton } from "../../components/ui/skeleton";
import { getApiErrorMessage, isApiError } from "../../lib/api-errors";
import {
  PurchaseOrderForm,
  toPurchaseOrderPayload,
  type PurchaseOrderFormValues,
} from "./purchase-order-form";

export function PurchaseOrderEditScreen() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { search } = useLocation();
  const orderId = Number(id);
  const [formError, setFormError] = useState<string | null>(null);
  const [advancedOptions, setAdvancedOptions] = useState<AdvancedOption[]>([]);
  const orderQuery = usePurchaseOrdersShow(orderId, { include: "lines" });
  const order =
    orderQuery.data?.status === 200 ? orderQuery.data.data.data : undefined;
  const update = usePurchaseOrdersUpdate();

  function handleSubmit(
    values: PurchaseOrderFormValues,
    form: UseFormReturn<PurchaseOrderFormValues>,
  ) {
    setFormError(null);
    update.mutate(
      { purchaseOrder: orderId, data: toPurchaseOrderPayload(values) },
      {
        onSuccess: (result) => {
          if (result.status === 200)
            navigate({
              pathname: `/purchase-orders/${result.data.data.id}`,
              search,
            });
        },
        onError: (error) => {
          if (isApiError(error) && error.body?.errors) {
            for (const [field, messages] of Object.entries(error.body.errors)) {
              form.setError(field as FieldPath<PurchaseOrderFormValues>, {
                type: "server",
                message: messages[0],
              });
            }
          } else {
            setFormError(getApiErrorMessage(error));
          }
        },
      },
    );
  }

  if (orderQuery.isLoading) {
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }
  if (orderQuery.isError || !order) {
    return (
      <div className="space-y-4 p-6">
        <p className="text-sm text-destructive">
          Couldn&apos;t load this purchase order.
        </p>
        <Button variant="outline" onClick={() => navigate("/purchase-orders")}>
          Back to purchase orders
        </Button>
      </div>
    );
  }
  if (order.status !== "DRAFT") {
    return (
      <div className="space-y-4 p-6">
        <p className="text-sm text-destructive">
          Only draft purchase orders can be edited.
        </p>
        <Button
          variant="outline"
          onClick={() => navigate(`/purchase-orders/${order.id}`)}
        >
          View purchase order
        </Button>
      </div>
    );
  }
  if (
    [
      order.fx_policy,
      order.fx_rate,
      order.fx_rate_date,
      order.fx_rate_type,
      order.fx_rate_source,
    ].some((value) => value === undefined) ||
    (order.lines ?? []).some((line) =>
      [line.unit_price, line.discount_amount, line.tax_amount].some(
        (value) => value === undefined,
      ),
    )
  ) {
    return (
      <div className="space-y-4 p-6">
        <p className="text-sm text-destructive">
          Commercial fields are unavailable for this draft. Ask an administrator
          to review your Purchasing access.
        </p>
        <Button
          variant="outline"
          onClick={() => navigate(`/purchase-orders/${order.id}`)}
        >
          View purchase order
        </Button>
      </div>
    );
  }

  const defaultValues: PurchaseOrderFormValues = {
    supplier_account_id: String(order.supplier_account_id),
    supplier_site_id: order.supplier_site_id
      ? String(order.supplier_site_id)
      : "",
    currency_id: String(order.currency_id),
    fx_policy: order.fx_policy! as "FIXED" | "FLOATING",
    fx_rate: order.fx_rate!,
    fx_rate_date: order.fx_rate_date!,
    fx_rate_type: order.fx_rate_type!,
    fx_rate_source: order.fx_rate_source!,
    order_date: order.order_date,
    expected_delivery_date: order.expected_delivery_date ?? "",
    notes: order.notes ?? "",
    lines: (order.lines ?? []).map((line) => ({
      item_id: String(line.item_id),
      description: line.description,
      ordered_qty: line.ordered_qty,
      purchase_uom_id: String(line.purchase_uom_id),
      unit_price: line.unit_price!,
      discount_amount: line.discount_amount!,
      tax_amount: line.tax_amount!,
      expected_delivery_date: line.expected_delivery_date ?? "",
      qa_required: line.qa_required,
    })),
  };

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={`Edit draft #${order.id}`}
        description="Changes remain provisional until the purchase order is issued."
        breadcrumbs={[
          { label: "Purchase Orders", to: "/purchase-orders" },
          { label: `Draft #${order.id}`, to: `/purchase-orders/${order.id}` },
          { label: "Edit" },
        ]}
        actions={<AdvancedOptionsMenu options={advancedOptions} />}
      />
      <PurchaseOrderForm
        defaultValues={defaultValues}
        onSubmit={handleSubmit}
        isSubmitting={update.isPending}
        formError={formError}
        onAdvancedOptionsChange={setAdvancedOptions}
      />
    </div>
  );
}
