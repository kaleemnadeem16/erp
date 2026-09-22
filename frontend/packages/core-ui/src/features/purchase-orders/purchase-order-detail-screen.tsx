import { useState, type ReactNode } from "react";
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
import { PageHeader, type PageHeaderBreadcrumb } from "../../components/page/page-header";
import { RecordSheet } from "../../components/page/record-sheet";
import {
  DetailPrintLayout,
  PrintableTable,
  PrintTrigger,
  useQuickPrint,
  type DetailPrintSection,
} from "../../components/print";
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
import type { Presentation } from "../../lib/presentation";
import { useFocusOnOpen } from "../../lib/use-focus-on-open";

const STATUS_VARIANT: Record<
  string,
  "secondary" | "default" | "success" | "destructive"
> = {
  DRAFT: "secondary",
  ISSUED: "default",
  CLOSED: "success",
  CANCELLED: "destructive",
};

const SITE_ROLE_LABEL: Record<string, string> = {
  ORDER_FROM: "Ordering from",
  SHIP_FROM: "Shipping from",
};

/** "12 Industrial Road, Lahore, Punjab 54000, PK" — skips whatever the address doesn't have. */
function siteAddressLine(site: NonNullable<PurchaseOrderResource["supplier_site"]>): string {
  const { address_line1, address_line2, city, state, postal_code, country_code } = site.party_site;
  return [address_line1, address_line2, [city, state, postal_code].filter(Boolean).join(" "), country_code]
    .filter(Boolean)
    .join(", ");
}

/**
 * Both presentations are kept on purpose — switch with one word in
 * `apps/main/src/presentation.ts`. `"page"` is the full-page detail; `"sheet"`
 * (the nested `:id` route) shows the same content in a side sheet over the
 * purchase-order list, which stays mounted behind it.
 */
export function PurchaseOrderDetailScreen({ presentation = "page" }: { presentation?: Presentation }) {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { search } = useLocation();
  const orderId = Number(id);
  const orderQuery = usePurchaseOrdersShow(orderId, { include: "lines,supplierSite" });
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
  const issueHeadingRef = useFocusOnOpen<HTMLHeadingElement>(showIssueConfirmation);
  const cancelHeadingRef = useFocusOnOpen<HTMLHeadingElement>(showCancel);
  const shortCloseHeadingRef = useFocusOnOpen<HTMLHeadingElement>(pendingShortCloseLineId !== null);
  const refetch = () => orderQuery.refetch();
  const fail = (error: unknown) => setActionError(getApiErrorMessage(error));
  const { contentRef: printRef, print } = useQuickPrint({
    documentTitle: order?.number ? `Purchase Order ${order.number}` : `Draft Purchase Order #${orderId}`,
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
    header?: { title: string; description?: string; breadcrumbs?: PageHeaderBreadcrumb[]; actions?: ReactNode },
  ) {
    if (presentation === "sheet") {
      return (
        <RecordSheet
          title={header?.title ?? "Purchase order"}
          description={header?.description}
          actions={header?.actions}
          size="xl"
          onClose={() => navigate({ pathname: "/purchase-orders", search })}
        >
          <div className="space-y-4 pb-4">{content}</div>
        </RecordSheet>
      );
    }
    return (
      <div className="space-y-4 p-6">
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
        <Button variant="outline" onClick={() => navigate({ pathname: "/purchase-orders", search })}>
          Back to purchase orders
        </Button>
      </>,
    );

  // In the side sheet the lines table has less room than on a page, so it drops
  // the unit's long name (kept as a hover title) and lets descriptions wrap —
  // otherwise the QA and Short-close columns are pushed off the right edge.
  const compact = presentation === "sheet";
  const purchaseUomLabel = (uomId: number) => uomLabels.get(uomId) ?? `UoM #${uomId}`;
  const isDraft = order.status === "DRAFT";
  const isIssued = order.status === "ISSUED";
  const supplierLabel = supplier
    ? `${supplier.code}${supplier.party?.name ? ` — ${supplier.party.name}` : ""}`
    : `Supplier #${order.supplier_account_id}`;

  const printSections: DetailPrintSection[] = [
    {
      title: "Order",
      fields: [
        { label: "Status", value: order.status },
        { label: "Order date", value: order.order_date },
        { label: "Expected delivery", value: order.expected_delivery_date ?? "" },
        { label: "Currency", value: currency ? `${currency.code} — ${currency.name}` : `Currency #${order.currency_id}` },
        { label: "FX policy", value: order.fx_policy },
        { label: "FX rate", value: `${order.fx_rate} (${order.fx_rate_date}, ${order.fx_rate_source})` },
      ],
    },
    {
      title: "Supplier",
      fields: [
        { label: "Supplier", value: supplierLabel },
        ...(order.supplier_site
          ? [
              {
                label: SITE_ROLE_LABEL[order.supplier_site.role] ?? "Site",
                value: [order.supplier_site.party_site.name, siteAddressLine(order.supplier_site)]
                  .filter(Boolean)
                  .join(" — "),
              },
            ]
          : []),
        ...(supplier?.party?.tax_id ? [{ label: "Tax ID", value: supplier.party.tax_id }] : []),
        ...(supplier?.party?.registration_no
          ? [{ label: "Registration no.", value: supplier.party.registration_no }]
          : []),
        { label: "Payment terms", value: supplier ? `${supplier.payment_terms_days} days` : "" },
      ],
    },
    ...(order.notes ? [{ title: "Notes", fullWidth: true, fields: [{ label: "Notes", value: order.notes }] }] : []),
  ];
  const printLineColumns: DataColumn<PurchaseOrderLineResource>[] = [
    { key: "line_number", header: "#", value: (line) => line.line_number, align: "right" },
    { key: "item", header: "Item", value: (line) => line.item_code ?? `#${line.item_id}` },
    { key: "description", header: "Description", value: (line) => line.description },
    {
      key: "ordered_qty",
      header: "Ordered",
      value: (line) => `${line.ordered_qty} ${uomLabels.get(line.purchase_uom_id)?.split(" — ")[0] ?? ""}`,
      align: "right",
    },
    { key: "unit_price", header: "Unit price", value: (line) => line.unit_price, align: "right" },
    { key: "discount_amount", header: "Discount", value: (line) => line.discount_amount, align: "right" },
    { key: "tax_amount", header: "Tax", value: (line) => line.tax_amount, align: "right" },
    {
      key: "line_amount",
      header: "Amount",
      value: (line) => `${line.line_amount} ${currency?.code ?? ""}`,
      align: "right",
    },
  ];
  const printLineColumnCount = printLineColumns.length;
  const printTotalsFooter = (
    <tr>
      <td
        colSpan={printLineColumnCount - 1}
        className="px-1.5 py-2 text-right text-[9.5px] font-semibold uppercase tracking-wide text-black/60"
      >
        Order total
      </td>
      <td className="px-1.5 py-2 text-right text-[11px] font-bold text-[var(--print-accent)]">
        {order.total_amount ?? "—"} {currency?.code ?? ""}
      </td>
    </tr>
  );

  const actions = (
    <>
      <PrintTrigger onPrint={() => print()} />
      {isDraft && (
        <Button
          variant="outline"
          onClick={() => navigate({ pathname: `/purchase-orders/${order.id}/edit`, search })}
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
  );

  return present(
    <>
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
            <h2 ref={issueHeadingRef} tabIndex={-1} className="font-medium outline-none">
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
            <h2 ref={cancelHeadingRef} tabIndex={-1} className="font-medium outline-none">
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
            <h2 ref={shortCloseHeadingRef} tabIndex={-1} className="font-medium outline-none">
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
          <DetailField label="Order date">{order.order_date}</DetailField>
          <DetailField label="Expected delivery">
            {order.expected_delivery_date ?? "Not set"}
          </DetailField>
          {order.notes && (
            <DetailField label="Notes" fullWidth>
              {order.notes}
            </DetailField>
          )}
          {order.cancellation_reason && (
            <DetailField label="Cancellation reason">
              {order.cancellation_reason}
            </DetailField>
          )}
        </DetailSection>
        <DetailSection title="Supplier">
          <DetailField label="Supplier">{supplierLabel}</DetailField>
          {order.supplier_site && (
            <DetailField label={SITE_ROLE_LABEL[order.supplier_site.role] ?? "Site"}>
              <div>{order.supplier_site.party_site.name}</div>
              {siteAddressLine(order.supplier_site) && (
                <div className="text-xs text-muted-foreground">{siteAddressLine(order.supplier_site)}</div>
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
            <DetailField label="Registration no.">{supplier.party.registration_no}</DetailField>
          )}
          {supplier?.party?.website && (
            <DetailField label="Website">{supplier.party.website}</DetailField>
          )}
          {supplier?.lead_time_days != null && (
            <DetailField label="Lead time">{supplier.lead_time_days} days</DetailField>
          )}
        </DetailSection>
        <DetailSection title="Commercial">
          <DetailField label="Currency">
            {currency
              ? `${currency.code} — ${currency.name}`
              : `Currency #${order.currency_id}`}
          </DetailField>
          <DetailField label="FX policy">{order.fx_policy}</DetailField>
          <DetailField label="FX rate">{order.fx_rate}</DetailField>
          <DetailField label="Order total">
            {order.total_amount ?? "—"} {currency?.code ?? ""}
          </DetailField>
          <DetailField label="Base-currency total">
            {order.base_total_amount ?? "—"}
          </DetailField>
        </DetailSection>
        <DetailSection
          title="Lines"
          description="Ordered quantities and commercial values are immutable after issue."
          fullWidth
        >
          <div className="overflow-hidden rounded-md border sm:col-span-2">
            <Table scrollRegionLabel="Order lines">
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
                  const fullyReceived = Number(line.outstanding_stock_qty) <= 0;
                  return (
                    <TableRow key={line.id}>
                      <TableCell>{line.line_number}</TableCell>
                      <TableCell>
                        {line.item_code ?? `#${line.item_id}`}
                      </TableCell>
                      <TableCell className={compact ? "whitespace-normal" : undefined}>
                        {line.description}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {line.ordered_qty}{" "}
                        <span className="text-muted-foreground" title={purchaseUomLabel(line.purchase_uom_id)}>
                          {compact
                            ? purchaseUomLabel(line.purchase_uom_id).split(" — ")[0]
                            : purchaseUomLabel(line.purchase_uom_id)}
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
                        {isIssued && !closed && !fullyReceived && (
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

      {/* Quick Print — a dedicated print-only layout, hidden until react-to-print clones it. */}
      <div className="hidden">
        <div ref={printRef}>
          <DetailPrintLayout
            title="Purchase order"
            subtitle={order.number ? `No. ${order.number} · ${order.status}` : `Draft #${order.id} · ${order.status}`}
            printedAt={new Date()}
            sections={printSections}
          >
            <PrintableTable
              columns={printLineColumns}
              rows={order.lines ?? []}
              getRowKey={(line) => line.id}
              footer={printTotalsFooter}
            />
          </DetailPrintLayout>
        </div>
      </div>
    </>,
    {
      title: order.number ? `Purchase order ${order.number}` : `Draft purchase order #${order.id}`,
      description: supplierLabel,
      breadcrumbs: [
        { label: "Purchase Orders", to: "/purchase-orders" },
        { label: order.number ?? `Draft #${order.id}` },
      ],
      actions,
    },
  );
}
