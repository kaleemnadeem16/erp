import { useState } from "react";
import { useNavigate, useParams } from "react-router";
import {
  usePurchaseOrderLinesShortClose,
  usePurchaseOrdersCancel,
  usePurchaseOrdersIssue,
  usePurchaseOrdersShow,
  useCurrenciesShow,
  useSupplierAccountsShow,
  useUomsIndex,
} from "@erp/core-api";

import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import { DetailField } from "../../components/detail/detail-field";
import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
import { PageHeader } from "../../components/page/page-header";
import { Skeleton } from "../../components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "../../components/ui/table";
import { Textarea } from "../../components/ui/textarea";
import { getApiErrorMessage } from "../../lib/api-errors";

const STATUS_VARIANT: Record<
  string,
  "secondary" | "default" | "success" | "destructive"
> = {
  DRAFT: "secondary",
  ISSUED: "default",
  CLOSED: "success",
  CANCELLED: "destructive",
};

export function PurchaseOrderDetailScreen() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const orderId = Number(id);
  const orderQuery = usePurchaseOrdersShow(orderId, { include: "lines" });
  const order =
    orderQuery.data?.status === 200 ? orderQuery.data.data.data : undefined;
  const supplierQuery = useSupplierAccountsShow(
    order?.supplier_account_id ?? 0,
    { include: "party" },
    { query: { enabled: !!order } },
  );
  const supplier =
    supplierQuery.data?.status === 200
      ? supplierQuery.data.data.data
      : undefined;
  const currencyQuery = useCurrenciesShow(order?.currency_id ?? 0, {
    query: { enabled: !!order },
  });
  const currency =
    currencyQuery.data?.status === 200
      ? currencyQuery.data.data.data
      : undefined;
  const uomsQuery = useUomsIndex(
    { per_page: 100, sort: "name" },
    { query: { enabled: !!order } },
  );
  const uoms = uomsQuery.data?.status === 200 ? uomsQuery.data.data.data : [];
  const uomLabels = new Map(
    uoms.map((uom) => [uom.id, `${uom.code} — ${uom.name}`]),
  );
  const issue = usePurchaseOrdersIssue();
  const cancel = usePurchaseOrdersCancel();
  const shortClose = usePurchaseOrderLinesShortClose();
  const [cancelReason, setCancelReason] = useState("");
  const [showIssueConfirmation, setShowIssueConfirmation] = useState(false);
  const [showCancel, setShowCancel] = useState(false);
  const [pendingShortCloseLineId, setPendingShortCloseLineId] = useState<
    number | null
  >(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const refetch = () => orderQuery.refetch();
  const fail = (error: unknown) => setActionError(getApiErrorMessage(error));

  function handleIssue() {
    setActionError(null);
    issue.mutate(
      { purchaseOrder: orderId },
      {
        onSuccess: () => {
          setShowIssueConfirmation(false);
          refetch();
        },
        onError: fail,
      },
    );
  }
  function handleCancel() {
    if (!cancelReason.trim()) return;
    setActionError(null);
    cancel.mutate(
      { purchaseOrder: orderId, data: { reason: cancelReason.trim() } },
      {
        onSuccess: () => {
          setShowCancel(false);
          setCancelReason("");
          refetch();
        },
        onError: fail,
      },
    );
  }
  function handleShortClose(lineId: number) {
    setActionError(null);
    shortClose.mutate(
      { purchaseOrderLine: lineId },
      {
        onSuccess: () => {
          setPendingShortCloseLineId(null);
          refetch();
        },
        onError: fail,
      },
    );
  }

  if (orderQuery.isLoading)
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-48 w-full" />
      </div>
    );
  if (orderQuery.isError || !order)
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

  const isDraft = order.status === "DRAFT";
  const isIssued = order.status === "ISSUED";
  const supplierLabel = supplier
    ? `${supplier.code}${supplier.party?.name ? ` — ${supplier.party.name}` : ""}`
    : `Supplier #${order.supplier_account_id}`;
  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={
          order.number
            ? `Purchase order ${order.number}`
            : `Draft purchase order #${order.id}`
        }
        description={supplierLabel}
        breadcrumbs={[
          { label: "Purchase Orders", to: "/purchase-orders" },
          { label: order.number ?? `Draft #${order.id}` },
        ]}
        actions={
          <>
            {isDraft && (
              <Button
                variant="outline"
                onClick={() => navigate(`/purchase-orders/${order.id}/edit`)}
              >
                Edit draft
              </Button>
            )}
            {isDraft && (
              <Button
                onClick={() => setShowIssueConfirmation(true)}
                disabled={issue.isPending}
              >
                Issue purchase order
              </Button>
            )}
            {isIssued && (
              <Button
                variant="outline"
                onClick={() => setShowCancel((value) => !value)}
              >
                Cancel order
              </Button>
            )}
          </>
        }
      />
      {actionError && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
          {actionError}
        </div>
      )}
      {showIssueConfirmation && isDraft && (
        <section
          className="space-y-3 rounded-md border p-4"
          aria-label="Issue purchase order"
        >
          <div>
            <h2 className="font-medium">Issue this purchase order</h2>
            <p className="text-sm text-muted-foreground">
              Issuing allocates the official number and freezes the supplier,
              quantities, UoM, prices and FX snapshots.
            </p>
          </div>
          <div className="flex gap-2">
            <Button onClick={handleIssue} disabled={issue.isPending}>
              Confirm issue
            </Button>
            <Button
              variant="outline"
              onClick={() => setShowIssueConfirmation(false)}
            >
              Keep as draft
            </Button>
          </div>
        </section>
      )}
      {showCancel && isIssued && (
        <section
          className="space-y-3 rounded-md border border-destructive/40 p-4"
          aria-label="Cancel purchase order"
        >
          <div>
            <h2 className="font-medium">Cancel this issued order</h2>
            <p className="text-sm text-muted-foreground">
              Cancellation stops future receiving and records the reason. Issued
              commercial facts remain unchanged.
            </p>
          </div>
          <Textarea
            value={cancelReason}
            onChange={(event) => setCancelReason(event.target.value)}
            placeholder="Why can this supplier commitment no longer continue?"
          />
          <div className="flex gap-2">
            <Button
              variant="destructive"
              onClick={handleCancel}
              disabled={!cancelReason.trim() || cancel.isPending}
            >
              Confirm cancellation
            </Button>
            <Button variant="outline" onClick={() => setShowCancel(false)}>
              Keep order
            </Button>
          </div>
        </section>
      )}
      {pendingShortCloseLineId !== null && isIssued && (
        <section
          className="space-y-3 rounded-md border p-4"
          aria-label="Short-close purchase-order line"
        >
          <div>
            <h2 className="font-medium">
              Short-close line{" "}
              {
                order.lines?.find((line) => line.id === pendingShortCloseLineId)
                  ?.line_number
              }
            </h2>
            <p className="text-sm text-muted-foreground">
              This permanently reduces the remaining receivable quantity to
              zero. It does not change the original ordered quantity.
            </p>
          </div>
          <div className="flex gap-2">
            <Button
              onClick={() => handleShortClose(pendingShortCloseLineId)}
              disabled={shortClose.isPending}
            >
              Confirm short-close
            </Button>
            <Button
              variant="outline"
              onClick={() => setPendingShortCloseLineId(null)}
            >
              Keep line open
            </Button>
          </div>
        </section>
      )}
      <DetailPageLayout>
        <DetailSection title="Order">
          <DetailField label="Status">
            <Badge variant={STATUS_VARIANT[order.status] ?? "secondary"}>
              {order.status}
            </Badge>
          </DetailField>
          <DetailField label="Supplier">{supplierLabel}</DetailField>
          <DetailField label="Order date">{order.order_date}</DetailField>
          <DetailField label="Expected delivery">
            {order.expected_delivery_date ?? "Not set"}
          </DetailField>
          <DetailField label="FX policy">{order.fx_policy}</DetailField>
          <DetailField label="FX rate">{order.fx_rate}</DetailField>
          <DetailField label="Currency">
            {currency
              ? `${currency.code} — ${currency.name}`
              : `Currency #${order.currency_id}`}
          </DetailField>
          <DetailField label="Order total">
            {order.total_amount ?? "—"} {currency?.code ?? ""}
          </DetailField>
          <DetailField label="Base-currency total">
            {order.base_total_amount ?? "—"}
          </DetailField>
          {order.cancellation_reason && (
            <DetailField label="Cancellation reason">
              {order.cancellation_reason}
            </DetailField>
          )}
        </DetailSection>
        <DetailSection
          title="Lines"
          description="Ordered quantities and commercial values are immutable after issue."
        >
          <div className="overflow-x-auto rounded-md border sm:col-span-2">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Line</TableHead>
                  <TableHead>Item</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead className="text-right">Ordered</TableHead>
                  <TableHead className="text-right">Received</TableHead>
                  <TableHead className="text-right">Outstanding</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                  <TableHead>QA</TableHead>
                  <TableHead />
                </TableRow>
              </TableHeader>
              <TableBody>
                {(order.lines ?? []).map((line) => {
                  const closed = Number(line.short_closed_stock_qty) > 0;
                  return (
                    <TableRow key={line.id}>
                      <TableCell>{line.line_number}</TableCell>
                      <TableCell>
                        {line.item_code ?? `#${line.item_id}`}
                      </TableCell>
                      <TableCell>{line.description}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {line.ordered_qty}{" "}
                        <span className="text-muted-foreground">
                          {uomLabels.get(line.purchase_uom_id) ??
                            `UoM #${line.purchase_uom_id}`}
                        </span>
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {line.received_stock_qty}{" "}
                        {uomLabels.get(line.stock_uom_id)?.split(" — ")[0] ??
                          ""}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {line.outstanding_stock_qty}{" "}
                        {uomLabels.get(line.stock_uom_id)?.split(" — ")[0] ??
                          ""}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {line.line_amount} {currency?.code ?? ""}
                      </TableCell>
                      <TableCell>
                        {line.qa_required ? "Required" : "Not required"}
                      </TableCell>
                      <TableCell className="text-right">
                        {isIssued && !closed && (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setPendingShortCloseLineId(line.id)}
                            disabled={shortClose.isPending}
                          >
                            Short-close
                          </Button>
                        )}
                        {closed && (
                          <Badge variant="secondary">Short-closed</Badge>
                        )}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </div>
        </DetailSection>
      </DetailPageLayout>
    </div>
  );
}
