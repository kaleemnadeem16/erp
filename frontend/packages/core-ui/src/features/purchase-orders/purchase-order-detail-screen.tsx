import { ScreenTemplate } from "../../components/page/screen-template";
import { PurchaseOrderQuickPrint } from "./purchase-order-print";
import { useEffect, useState, type ReactNode } from "react";
import { useLocation, useNavigate, useParams } from "react-router";
import {
  usePurchaseOrderLinesShortClose,
  usePurchaseOrdersCancel,
  usePurchaseOrdersIssue,
  usePurchaseOrdersShow,
  useCurrenciesShow,
  useSupplierAccountsShow,
  useUomsIndex,
  type PurchaseOrderLineResource,
  type PurchaseOrderResource,
} from "@erp/core-api";

import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import type { DataColumn } from "../../components/data-table/types";
import { DetailField } from "../../components/detail/detail-field";
import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
import {
  PageHeader,
  type PageHeaderBreadcrumb,
} from "../../components/page/page-header";
import { RecordSheet } from "../../components/page/record-sheet";
import { PrintTrigger, useQuickPrint } from "../../components/print";
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
import { DataTableViewToggle } from "../../components/data-table/data-table-view-toggle";
import { useListViewPreference } from "../../lib/use-view-preference";
import { formatDateTime } from "../../lib/format-date";
import type { Presentation } from "../../lib/presentation";
import { useFocusOnOpen } from "../../lib/use-focus-on-open";
import { usePermission } from "../../hooks/use-permission";
import {
  PurchaseOrderApprovalSection,
  type PurchaseOrderApproval,
} from "./purchase-order-approval-section";
import { usePurchasingCommercialCost } from "../../hooks/use-purchasing-commercial-cost";
import { PurchaseOrderStatusBadge } from "./purchase-order-columns";

const SITE_ROLE_LABEL: Record<string, string> = {
  ORDER_FROM: "Ordering from",
  SHIP_FROM: "Shipping from",
};

/** "12 Industrial Road, Lahore, Punjab 54000, PK" — skips whatever the address doesn't have. */
function siteAddressLine(
  site: NonNullable<PurchaseOrderResource["supplier_site"]>,
): string {
  const {
    address_line1,
    address_line2,
    city,
    state,
    postal_code,
    country_code,
  } = site.party_site;
  return [
    address_line1,
    address_line2,
    [city, state, postal_code].filter(Boolean).join(" "),
    country_code,
  ]
    .filter(Boolean)
    .join(", ");
}

/**
 * Both presentations are kept on purpose — switch with one word in
 * `apps/main/src/presentation.ts`. `"page"` is the full-page detail; `"sheet"`
 * (the nested `:id` route) shows the same content in a side sheet over the
 * purchase-order list, which stays mounted behind it.
 */
export function PurchaseOrderDetailScreen({
  presentation = "page",
}: {
  presentation?: Presentation;
}) {
  const mayViewCommercialCost = usePurchasingCommercialCost();
  const mayEdit = usePermission("purchasing.purchase-order.create");
  const mayIssue = usePermission("purchasing.purchase-order.issue");
  const mayCancel = usePermission("purchasing.purchase-order.cancel");
  const mayShortClose = usePermission("purchasing.purchase-order.short-close");
  const maySubmit = usePermission("purchasing.purchase-order.submit");
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { search } = useLocation();
  const orderId = Number(id);
  const orderQuery = usePurchaseOrdersShow(orderId, {
    include: "lines,supplierSite",
  });
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
  const { view: linesView, setView: setLinesView } =
    useListViewPreference("po-detail-lines");
  const [cancelReason, setCancelReason] = useState("");
  const [showIssueConfirmation, setShowIssueConfirmation] = useState(false);
  const [showCancel, setShowCancel] = useState(false);
  const [pendingShortCloseLineId, setPendingShortCloseLineId] = useState<
    number | null
  >(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const issueHeadingRef = useFocusOnOpen<HTMLHeadingElement>(
    showIssueConfirmation,
  );
  const cancelHeadingRef = useFocusOnOpen<HTMLHeadingElement>(showCancel);
  const shortCloseHeadingRef = useFocusOnOpen<HTMLHeadingElement>(
    pendingShortCloseLineId !== null,
  );
  const refetch = () => orderQuery.refetch();
  // The list's row menu can hand over "issue" or "cancel": open that confirmation here, where the approval rules
  // and the confirmation already live, but only when it is currently allowed — and only once.
  const { pathname: currentPath, state: navigationState } = useLocation();
  const quickAction = (navigationState as { quickAction?: string } | null)
    ?.quickAction;
  useEffect(() => {
    if (!quickAction || !order) return;
    const approvalState = (
      order as unknown as { approval?: PurchaseOrderApproval }
    ).approval;
    const needsApproval =
      !!approvalState?.required && approvalState.status !== "APPROVED";
    if (
      quickAction === "issue" &&
      order.status === "DRAFT" &&
      mayIssue &&
      !needsApproval
    )
      setShowIssueConfirmation(true);
    if (quickAction === "cancel" && order.status === "ISSUED" && mayCancel)
      setShowCancel(true);
    navigate({ pathname: currentPath, search }, { replace: true, state: null });
  }, [quickAction, order, mayIssue, mayCancel, navigate, currentPath, search]);
  const fail = (error: unknown) => setActionError(getApiErrorMessage(error));
  const { contentRef: printRef, print } = useQuickPrint({
    pageStyle: "@page { size: A4; margin: 0; }",
    documentTitle: order?.number
      ? `Purchase Order ${order.number}`
      : `Draft Purchase Order #${orderId}`,
  });

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
          title={header?.title ?? "Purchase order"}
          description={header?.description}
          actions={header?.actions}
          size="xl"
          className="po-detail"
          onClose={() => navigate({ pathname: "/purchase-orders", search })}
        >
          <div className="space-y-4 pb-4">{content}</div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="detail" className="po-detail">
        {header && (
          <PageHeader
            title={header.title}
            description={header.description}
            breadcrumbs={header.breadcrumbs}
            actions={header.actions}
          />
        )}
        {content}
      </ScreenTemplate>
    );
  }

  if (orderQuery.isLoading)
    return present(
      <>
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-48 w-full" />
      </>,
    );
  if (orderQuery.isError || !order)
    return present(
      <>
        <p className="text-sm text-destructive">
          Couldn&apos;t load this purchase order.
        </p>
        <Button
          variant="outline"
          onClick={() => navigate({ pathname: "/purchase-orders", search })}
        >
          Back to purchase orders
        </Button>
      </>,
    );

  // In the side sheet the lines table has less room than on a page, so it drops
  // the unit's long name (kept as a hover title) and lets descriptions wrap —
  // otherwise the QA and Short-close columns are pushed off the right edge.
  const compact = presentation === "sheet";
  const purchaseUomLabel = (uomId: number) =>
    uomLabels.get(uomId) ?? `UoM #${uomId}`;
  const uomCode = (uomId: number) =>
    uomLabels.get(uomId)?.split(" — ")[0] ?? `UoM #${uomId}`;
  const isDraft = order.status === "DRAFT";
  const isIssued = order.status === "ISSUED";
  const approval = (order as unknown as { approval?: PurchaseOrderApproval })
    .approval;
  // The server enforces both; the buttons just say so instead of failing after a click.
  const awaitingApproval = approval?.status === "PENDING";
  const needsApprovalFirst =
    !!approval?.required && approval.status !== "APPROVED";
  const supplierLabel = supplier
    ? `${supplier.code}${supplier.party?.name ? ` — ${supplier.party.name}` : ""}`
    : `Supplier #${order.supplier_account_id}`;

  const actions = (
    <>
      <PrintTrigger onPrint={() => print()} />
      {isDraft && mayEdit && (
        <Button
          variant="outline"
          disabled={awaitingApproval}
          title={
            awaitingApproval
              ? "Withdraw the approval request to edit this order"
              : undefined
          }
          onClick={() =>
            navigate({ pathname: `/purchase-orders/${order.id}/edit`, search })
          }
        >
          Edit draft
        </Button>
      )}
      {isDraft && mayIssue && (
        <Button
          onClick={() => setShowIssueConfirmation(true)}
          disabled={issue.isPending || needsApprovalFirst}
          title={
            needsApprovalFirst
              ? "This order needs approval before it can be issued"
              : undefined
          }
        >
          Issue purchase order
        </Button>
      )}
      {isIssued && mayCancel && (
        <Button
          variant="outline"
          onClick={() => setShowCancel((value) => !value)}
        >
          Cancel order
        </Button>
      )}
    </>
  );

  return present(
    <>
      {actionError && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
          {actionError}
        </div>
      )}
      <PurchaseOrderApprovalSection
        orderId={order.id}
        approval={approval}
        isDraft={isDraft}
        maySubmit={maySubmit}
        onChanged={refetch}
      />
      {showIssueConfirmation && isDraft && (
        <section
          className="space-y-3 rounded-md border p-4"
          aria-label="Issue purchase order"
        >
          <div>
            <h2
              ref={issueHeadingRef}
              tabIndex={-1}
              className="font-medium outline-none"
            >
              Issue this purchase order
            </h2>
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
            <h2
              ref={cancelHeadingRef}
              tabIndex={-1}
              className="font-medium outline-none"
            >
              Cancel this issued order
            </h2>
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
            <h2
              ref={shortCloseHeadingRef}
              tabIndex={-1}
              className="font-medium outline-none"
            >
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
      <div id={`po-order-details-${order.id}`}>
        <DetailPageLayout equalHeight>
          <DetailSection title="Order">
            <DetailField label="PO number">
              <span className="font-semibold">
                {order.number ?? `Draft #${order.id}`}
              </span>
            </DetailField>
            <DetailField label="Status">
              <PurchaseOrderStatusBadge status={order.status} />
            </DetailField>
            <DetailField label="Order date">{order.order_date}</DetailField>
            <DetailField label="Expected delivery">
              {order.expected_delivery_date ?? "Not set"}
            </DetailField>
            {order.issued_at && (
              <DetailField label="Issued at">
                {formatDateTime(order.issued_at)}
              </DetailField>
            )}
            {order.closed_at && (
              <DetailField label="Closed at">
                {formatDateTime(order.closed_at)}
              </DetailField>
            )}
            {order.cancelled_at && (
              <DetailField label="Cancelled at">
                {formatDateTime(order.cancelled_at)}
              </DetailField>
            )}
            {order.cancellation_reason && (
              <DetailField label="Cancellation reason" fullWidth>
                {order.cancellation_reason}
              </DetailField>
            )}
            {order.notes && (
              <DetailField label="Notes" fullWidth>
                {order.notes}
              </DetailField>
            )}
          </DetailSection>
          <DetailSection title="Supplier">
            <DetailField label="Supplier">{supplierLabel}</DetailField>
            {order.supplier_site && (
              <DetailField
                label={SITE_ROLE_LABEL[order.supplier_site.role] ?? "Site"}
              >
                <div>{order.supplier_site.party_site.name}</div>
                {siteAddressLine(order.supplier_site) && (
                  <div className="text-xs text-muted-foreground">
                    {siteAddressLine(order.supplier_site)}
                  </div>
                )}
              </DetailField>
            )}
            <DetailField label="Payment terms">
              {supplier ? `${supplier.payment_terms_days} days` : undefined}
            </DetailField>
            {supplier?.party?.tax_id && (
              <DetailField label="Tax ID">{supplier.party.tax_id}</DetailField>
            )}
            {supplier?.party?.registration_no && (
              <DetailField label="Registration no.">
                {supplier.party.registration_no}
              </DetailField>
            )}
            {supplier?.party?.website && (
              <DetailField label="Website">
                {supplier.party.website}
              </DetailField>
            )}
            {supplier?.lead_time_days != null && (
              <DetailField label="Lead time">
                {supplier.lead_time_days} days
              </DetailField>
            )}
          </DetailSection>
          {mayViewCommercialCost && (
            <DetailSection title="Commercial">
              <DetailField label="Currency">
                {currency
                  ? `${currency.code} — ${currency.name}`
                  : `Currency #${order.currency_id}`}
              </DetailField>
              <DetailField label="FX policy">{order.fx_policy}</DetailField>
              <DetailField label="FX rate">
                {order.fx_rate}
                {order.fx_rate_date && (
                  <span className="ml-1 text-xs text-muted-foreground">
                    ({order.fx_rate_date}
                    {order.fx_rate_source ? `, ${order.fx_rate_source}` : ""})
                  </span>
                )}
              </DetailField>
              <DetailField label="Order total">
                {order.total_amount ?? "—"} {currency?.code ?? ""}
              </DetailField>
              <DetailField label="Base-currency total">
                {order.base_total_amount ?? "—"}
              </DetailField>
            </DetailSection>
          )}
          <DetailSection title="Record information">
            <DetailField label="Created at">
              {formatDateTime(order.created_at)}
            </DetailField>
            {order.inventory_cutover_id && (
              <DetailField label="Cutover origin">
                Opening inventory commitment
              </DetailField>
            )}
            {order.source_system && (
              <DetailField label="Source system">
                {order.source_system}
              </DetailField>
            )}
            {order.source_record_id && (
              <DetailField label="Legacy reference">
                {order.source_record_id}
              </DetailField>
            )}
          </DetailSection>
          <DetailSection
            title="Lines"
            description={
              mayViewCommercialCost
                ? "Ordered quantities and commercial values are immutable after issue. Line amount = quantity × unit price − line discount + line tax (discount and tax are fixed amounts for the line)."
                : "Ordered quantities are immutable after issue."
            }
            fullWidth
          >
            <div className="flex justify-end sm:col-span-2 md:hidden">
              <DataTableViewToggle value={linesView} onChange={setLinesView} />
            </div>
            {linesView === "cards" ? (
              <ol className="grid gap-3 @lg:grid-cols-2 sm:col-span-2">
                {(order.lines ?? []).map((line) => {
                  const closed = Number(line.short_closed_stock_qty) > 0;
                  const fullyReceived = Number(line.outstanding_stock_qty) <= 0;
                  const purchaseCode =
                    line.order_unit_label ?? uomCode(line.purchase_uom_id);
                  const stockCode = uomCode(line.stock_uom_id);
                  const stat = (name: string, value: ReactNode) => (
                    <div className="min-w-0">
                      <dt className="text-xs text-muted-foreground">{name}</dt>
                      <dd className="text-sm tabular-nums">{value}</dd>
                    </div>
                  );
                  return (
                    <li
                      key={line.id}
                      className="space-y-3 rounded-lg border border-border bg-card p-3"
                    >
                      <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                          <p className="text-xs text-muted-foreground">
                            Line {line.line_number}
                          </p>
                          <p className="font-medium">
                            {line.item_code ?? `#${line.item_id}`}
                          </p>
                          <p className="text-sm">{line.description}</p>
                          {line.supplier_item_code && (
                            <p className="text-xs text-muted-foreground">
                              SKU: {line.supplier_item_code}
                            </p>
                          )}
                        </div>
                        {mayViewCommercialCost && (
                          <p className="shrink-0 text-right text-sm font-semibold tabular-nums">
                            {line.line_amount} {currency?.code ?? ""}
                          </p>
                        )}
                      </div>
                      <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
                        {stat(
                          "Ordered",
                          `${line.ordered_qty} ${purchaseCode ?? ""}`,
                        )}
                        {stat(
                          "Received",
                          `${line.received_stock_qty} ${stockCode ?? ""}`,
                        )}
                        {stat(
                          "Outstanding",
                          `${line.outstanding_stock_qty} ${stockCode ?? ""}`,
                        )}
                        {line.purchase_uom_id !== line.stock_uom_id &&
                          stat(
                            "In stock units",
                            `${line.ordered_stock_qty} ${stockCode ?? ""}`,
                          )}
                        {mayViewCommercialCost &&
                          stat(
                            "Price",
                            `${line.unit_price} ${currency?.code ?? ""} / ${line.price_quantity ?? "1"} ${line.price_unit_label ?? purchaseCode ?? ""}`,
                          )}
                        {mayViewCommercialCost &&
                          Number(line.discount_amount) > 0 &&
                          stat("Line discount", `−${line.discount_amount}`)}
                        {mayViewCommercialCost &&
                          Number(line.tax_amount) > 0 &&
                          stat("Line tax", `+${line.tax_amount}`)}
                        {line.expected_delivery_date &&
                          line.expected_delivery_date !==
                            order.expected_delivery_date &&
                          stat("Expected", line.expected_delivery_date)}
                        {stat(
                          "QA",
                          line.qa_required ? "Required" : "Not required",
                        )}
                      </dl>
                      {(closed ||
                        (isIssued && !fullyReceived && mayShortClose)) && (
                        <div className="flex items-center justify-between gap-2">
                          {closed ? (
                            <Badge variant="secondary">
                              Short-closed · {line.short_closed_stock_qty}{" "}
                              {stockCode}
                            </Badge>
                          ) : (
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() =>
                                setPendingShortCloseLineId(line.id)
                              }
                              disabled={shortClose.isPending}
                            >
                              Short-close
                            </Button>
                          )}
                        </div>
                      )}
                    </li>
                  );
                })}
              </ol>
            ) : (
              <div className="po-detail-table overflow-hidden rounded-md border sm:col-span-2">
                <Table scrollRegionLabel="Order lines">
                  <TableHeader>
                    <TableRow>
                      <TableHead>Line</TableHead>
                      <TableHead>Item</TableHead>
                      <TableHead>Description</TableHead>
                      <TableHead className="text-right">Ordered</TableHead>
                      <TableHead className="text-right">Received</TableHead>
                      <TableHead className="text-right">Outstanding</TableHead>
                      {mayViewCommercialCost && (
                        <TableHead className="text-right">Amount</TableHead>
                      )}
                      <TableHead>QA</TableHead>
                      <TableHead />
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {(order.lines ?? []).map((line) => {
                      const closed = Number(line.short_closed_stock_qty) > 0;
                      const fullyReceived =
                        Number(line.outstanding_stock_qty) <= 0;
                      const purchaseCode =
                        line.order_unit_label ?? uomCode(line.purchase_uom_id);
                      const stockCode = uomCode(line.stock_uom_id);
                      const hasUomConversion =
                        line.purchase_uom_id !== line.stock_uom_id;
                      const hasLineDelivery = Boolean(
                        line.expected_delivery_date &&
                        line.expected_delivery_date !==
                          order.expected_delivery_date,
                      );
                      const hasDiscount = Number(line.discount_amount) > 0;
                      const hasTax = Number(line.tax_amount) > 0;

                      return (
                        <TableRow key={line.id}>
                          <TableCell>{line.line_number}</TableCell>
                          <TableCell>
                            <div className="font-medium">
                              {line.item_code ?? `#${line.item_id}`}
                            </div>
                            {line.supplier_item_code && (
                              <div className="text-xs text-muted-foreground">
                                SKU: {line.supplier_item_code}
                              </div>
                            )}
                          </TableCell>
                          <TableCell
                            className={
                              compact ? "whitespace-normal" : undefined
                            }
                          >
                            <div>{line.description}</div>
                            {hasLineDelivery && (
                              <div className="text-xs text-muted-foreground">
                                Expected: {line.expected_delivery_date}
                              </div>
                            )}
                          </TableCell>
                          <TableCell className="text-right tabular-nums">
                            <div>
                              {line.ordered_qty}{" "}
                              <span
                                className="text-muted-foreground"
                                title={purchaseUomLabel(line.purchase_uom_id)}
                              >
                                {purchaseCode}
                              </span>
                            </div>
                            {hasUomConversion && (
                              <div className="text-xs text-muted-foreground">
                                = {line.ordered_stock_qty} {stockCode} (1{" "}
                                {purchaseCode} = {line.uom_factor} {stockCode})
                              </div>
                            )}
                          </TableCell>
                          <TableCell className="text-right tabular-nums">
                            <div>
                              {line.received_stock_qty}{" "}
                              <span
                                className="text-muted-foreground"
                                title={uomLabels.get(line.stock_uom_id)}
                              >
                                {stockCode}
                              </span>
                            </div>
                          </TableCell>
                          <TableCell className="text-right tabular-nums">
                            <div>
                              {line.outstanding_stock_qty}{" "}
                              <span
                                className="text-muted-foreground"
                                title={uomLabels.get(line.stock_uom_id)}
                              >
                                {stockCode}
                              </span>
                            </div>
                            {closed && (
                              <div className="text-xs text-muted-foreground">
                                Short-closed: {line.short_closed_stock_qty}{" "}
                                {stockCode}
                              </div>
                            )}
                          </TableCell>
                          {mayViewCommercialCost && (
                            <TableCell className="text-right tabular-nums">
                              <div className="font-medium">
                                {line.line_amount} {currency?.code ?? ""}
                              </div>
                              <div className="text-xs text-muted-foreground">
                                <span>
                                  {line.unit_price} {currency?.code ?? ""} /{" "}
                                  {line.price_quantity ?? "1"}{" "}
                                  {line.price_unit_label ?? purchaseCode}
                                </span>
                                {hasDiscount && (
                                  <span>
                                    {" "}
                                    · Line discount −{line.discount_amount}{" "}
                                    {currency?.code ?? ""}
                                  </span>
                                )}
                                {hasTax && (
                                  <span>
                                    {" "}
                                    · Line tax +{line.tax_amount}{" "}
                                    {currency?.code ?? ""}
                                  </span>
                                )}
                              </div>
                            </TableCell>
                          )}
                          <TableCell>
                            {line.qa_required ? "Required" : "Not required"}
                          </TableCell>
                          <TableCell className="text-right">
                            {isIssued &&
                              !closed &&
                              !fullyReceived &&
                              mayShortClose && (
                                <Button
                                  size="sm"
                                  variant="outline"
                                  onClick={() =>
                                    setPendingShortCloseLineId(line.id)
                                  }
                                  disabled={shortClose.isPending}
                                >
                                  Short-close
                                </Button>
                              )}
                            {closed && (
                              <div className="flex flex-col items-end gap-0.5">
                                <Badge variant="secondary">Short-closed</Badge>
                                <span className="text-[11px] text-muted-foreground">
                                  {line.short_closed_stock_qty} {stockCode}
                                </span>
                              </div>
                            )}
                          </TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              </div>
            )}
          </DetailSection>
        </DetailPageLayout>
      </div>

      {/* Quick Print — a dedicated print-only layout, hidden until react-to-print clones it. */}
      <div className="hidden">
        <div ref={printRef}>
          <PurchaseOrderQuickPrint
            order={order}
            supplier={supplier}
            currency={currency?.code ?? ""}
            commercial={mayViewCommercialCost}
            uom={uomCode}
          />
        </div>
      </div>
    </>,
    {
      title: order.number
        ? `Purchase order ${order.number}`
        : `Draft purchase order #${order.id}`,
      description: supplierLabel,
      breadcrumbs: [
        { label: "Purchase Orders", to: "/purchase-orders" },
        { label: order.number ?? `Draft #${order.id}` },
      ],
      actions,
    },
  );
}
