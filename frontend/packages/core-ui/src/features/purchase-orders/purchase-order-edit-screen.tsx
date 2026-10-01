import { useState, type ReactNode } from "react";
import { useLocation, useNavigate, useParams } from "react-router";
import type { FieldPath, UseFormReturn } from "react-hook-form";
import { usePurchaseOrdersShow, usePurchaseOrdersUpdate } from "@erp/core-api";

import {
  AdvancedOptionsMenu,
  type AdvancedOption,
} from "../../components/ui/advanced-options-menu";
import { Button } from "../../components/ui/button";
import { PageHeader, type PageHeaderBreadcrumb } from "../../components/page/page-header";
import { RecordSheet } from "../../components/page/record-sheet";
import type { Presentation } from "../../lib/presentation";
import { Skeleton } from "../../components/ui/skeleton";
import { getApiErrorMessage, isApiError } from "../../lib/api-errors";
import {
  PurchaseOrderForm,
  toPurchaseOrderPayload,
  type PurchaseOrderFormValues,
} from "./purchase-order-form";

/**
 * Both presentations are kept on purpose — switch with one word in `apps/main/src/presentation.ts`.
 * `"sheet"` (current) is for the nested `:id/edit` route: the purchase-order list stays mounted behind a side
 * sheet, a click outside is ignored (a stray click must not throw away typed changes), and closing returns to the
 * order. `"page"` is the same form on a page of its own.
 */
export function PurchaseOrderEditScreen({ presentation = "page" }: { presentation?: Presentation }) {
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

  // The same content either fills a page or a side sheet over the list.
  function present(
    content: ReactNode,
    header?: {
      title: string;
      description?: string;
      breadcrumbs?: PageHeaderBreadcrumb[];
      actions?: ReactNode;
    },
  ) {
    if (presentation === "sheet") {
      return (
        <RecordSheet
          title={header?.title ?? "Edit purchase order"}
          description={header?.description}
          actions={header?.actions}
          size="xl"
          className="po-detail"
          dismissOnOutsideClick={false}
          onClose={() => navigate({ pathname: `/purchase-orders/${orderId}`, search })}
        >
          <div className="space-y-4 pb-4">{content}</div>
        </RecordSheet>
      );
    }
    return (
      <div className="po-detail space-y-4 p-6">
        {header && (
          <PageHeader
            title={header.title}
            description={header.description}
            breadcrumbs={header.breadcrumbs}
            actions={header.actions}
          />
        )}
        {content}
      </div>
    );
  }

  if (orderQuery.isLoading) {
    return present(
      <>
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-64 w-full" />
      </>,
    );
  }
  if (orderQuery.isError || !order) {
    return present(
      <>
        <p className="text-sm text-destructive">
          Couldn&apos;t load this purchase order.
        </p>
        <Button variant="outline" onClick={() => navigate({ pathname: "/purchase-orders", search })}>
          Back to purchase orders
        </Button>
      </>,
    );
  }
  if (order.status !== "DRAFT") {
    return present(
      <>
        <p className="text-sm text-destructive">
          Only draft purchase orders can be edited.
        </p>
        <Button
          variant="outline"
          onClick={() => navigate({ pathname: `/purchase-orders/${order.id}`, search })}
        >
          View purchase order
        </Button>
      </>,
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
    return present(
      <>
        <p className="text-sm text-destructive">
          Commercial fields are unavailable for this draft. Ask an administrator
          to review your Purchasing access.
        </p>
        <Button
          variant="outline"
          onClick={() => navigate({ pathname: `/purchase-orders/${order.id}`, search })}
        >
          View purchase order
        </Button>
      </>,
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

  return present(
    <PurchaseOrderForm
      defaultValues={defaultValues}
      onSubmit={handleSubmit}
      isSubmitting={update.isPending}
      formError={formError}
      onAdvancedOptionsChange={setAdvancedOptions}
    />,
    {
      title: `Edit draft #${order.id}`,
      description: "Changes remain provisional until the purchase order is issued.",
      breadcrumbs: [
        { label: "Purchase Orders", to: "/purchase-orders" },
        { label: `Draft #${order.id}`, to: `/purchase-orders/${order.id}` },
        { label: "Edit" },
      ],
      actions: <AdvancedOptionsMenu options={advancedOptions} />,
    },
  );
}
