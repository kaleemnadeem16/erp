import { useEffect, useState } from "react";
import { ReceiptReversalPanel, type ReversalDraws } from "../package-stock/receipt-reversal-panel";
import { useNavigate, useParams } from "react-router";
import {
  useCurrenciesIndex,
  useGoodsReceiptsPost,
  useGoodsReceiptsReverse,
  useGoodsReceiptsShow,
  useItemsIndex,
  useLocationsIndex,
  useQualityDispositionsIndex,
  useQualityDispositionsStore,
  useSupplierAccountsShow,
  useUomsIndex,
  type GoodsReceiptLineResource,
  type StoreQualityDispositionRequestDisposition,
} from "@erp/core-api";

import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import type { DataColumn } from "../../components/data-table/types";
import { DetailField } from "../../components/detail/detail-field";
import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
import { Input } from "../../components/ui/input";
import { PageHeader } from "../../components/page/page-header";
import {
  DetailPrintLayout,
  PrintableTable,
  PrintTrigger,
  useQuickPrint,
  type DetailPrintSection,
} from "../../components/print";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "../../components/ui/select";
import { Skeleton } from "../../components/ui/skeleton";
import { Textarea } from "../../components/ui/textarea";
import { getApiErrorMessage } from "../../lib/api-errors";
import { formatDateTime } from "../../lib/format-date";
import { useFocusOnOpen } from "../../lib/use-focus-on-open";
import { formatLocationOption } from "../../lib/format-location";
import {
  ReceiptCompositionPanel,
  type ObservedRow,
} from "../package-stock/receipt-composition-panel";
import { usePermission } from "../../hooks/use-permission";
import { usePurchasingCommercialCost } from "../../hooks/use-purchasing-commercial-cost";

const OUTCOMES = [
  "PASS",
  "ACCEPT_WITH_CONCESSION",
  "RETURN_TO_SUPPLIER",
  "REWORK",
  "SCRAP",
  "PENDING_DECISION",
] as const;
const TERMINAL = new Set([
  "PASS",
  "ACCEPT_WITH_CONCESSION",
  "RETURN_TO_SUPPLIER",
  "SCRAP",
]);

function QualityPanel({
  line,
  enabled,
  onChanged,
}: {
  line: GoodsReceiptLineResource;
  enabled: boolean;
  onChanged: () => void;
}) {
  const mayPostDisposition = usePermission(
    "purchasing.quality-disposition.post",
  );
  const query = useQualityDispositionsIndex(
    line.id,
    { per_page: 100, sort: "disposed_at" },
    { query: { enabled } },
  );
  const dispositions = query.data?.status === 200 ? query.data.data.data : [];
  const create = useQualityDispositionsStore();
  const [source, setSource] = useState("root");
  const [outcome, setOutcome] =
    useState<StoreQualityDispositionRequestDisposition>("PASS");
  const [qty, setQty] = useState(line.qa_unallocated_qty ?? "");
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const parents = dispositions.filter(
    (item) =>
      !TERMINAL.has(item.disposition) && item.remaining_qty !== "0.0000",
  );
  const available =
    source === "root"
      ? line.qa_unallocated_qty
      : dispositions.find((item) => item.id === Number(source))?.remaining_qty;

  useEffect(() => {
    if (
      source === "root" &&
      line.qa_unallocated_qty === "0.0000" &&
      parents[0]
    ) {
      setSource(String(parents[0].id));
      setQty(parents[0].remaining_qty ?? "");
    }
  }, [line.qa_unallocated_qty, parents, source]);

  if (!enabled || line.qa_unallocated_qty === null) return null;
  return (
    <div className="space-y-3 border-t pt-4">
      <div className="flex flex-wrap gap-2 text-sm">
        <Badge
          variant={
            line.qa_unresolved_qty === "0.0000" ? "success" : "secondary"
          }
        >
          QA unresolved {line.qa_unresolved_qty}
        </Badge>
        <span className="text-muted-foreground">
          Unallocated {line.qa_unallocated_qty}
        </span>
      </div>
      {dispositions.length > 0 && (
        <div className="space-y-2">
          {dispositions.map((item) => (
            <div
              key={item.id}
              className="flex flex-wrap justify-between gap-2 rounded-md bg-muted p-2 text-sm"
            >
              <span>
                {item.disposition} · {item.qty}
                {item.source_disposition_id
                  ? ` from #${item.source_disposition_id}`
                  : ""}
              </span>
              <span className="text-muted-foreground">
                {item.reason}
                {item.remaining_qty !== null
                  ? ` · ${item.remaining_qty} open`
                  : ""}
              </span>
            </div>
          ))}
        </div>
      )}
      {line.qa_unresolved_qty !== "0.0000" && mayPostDisposition && (
        <div className="grid gap-3 rounded-md border p-3 md:grid-cols-4">
          <Select
            value={source}
            onValueChange={(value) => {
              setSource(value);
              const next =
                value === "root"
                  ? line.qa_unallocated_qty
                  : dispositions.find((item) => item.id === Number(value))
                      ?.remaining_qty;
              setQty(next ?? "");
            }}
          >
            <SelectTrigger aria-label="Disposition source">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {line.qa_unallocated_qty !== "0.0000" && (
                <SelectItem value="root">New root decision</SelectItem>
              )}
              {parents.map((parent) => (
                <SelectItem key={parent.id} value={String(parent.id)}>
                  Resolve #{parent.id} ({parent.remaining_qty})
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select
            value={outcome}
            onValueChange={(value) =>
              setOutcome(value as StoreQualityDispositionRequestDisposition)
            }
          >
            <SelectTrigger aria-label="Disposition outcome">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {OUTCOMES.map((value) => (
                <SelectItem key={value} value={value}>
                  {value.replaceAll("_", " ")}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Input
            aria-label="Disposition quantity"
            inputMode="decimal"
            value={qty}
            onChange={(event) => setQty(event.target.value)}
            placeholder={available ?? "Quantity"}
          />
          <Input
            aria-label="Disposition reason"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            placeholder="Reason / evidence"
          />
          {error && (
            <p className="text-sm text-destructive md:col-span-4">{error}</p>
          )}
          <div className="md:col-span-4">
            <Button
              disabled={!qty || !reason.trim() || create.isPending}
              onClick={() => {
                setError(null);
                create.mutate(
                  {
                    goodsReceiptLine: line.id,
                    data: {
                      disposition: outcome,
                      qty,
                      reason: reason.trim(),
                      source_disposition_id:
                        source === "root" ? null : Number(source),
                    },
                  },
                  {
                    onSuccess: () => {
                      setReason("");
                      query.refetch();
                      onChanged();
                    },
                    onError: (failure) => setError(getApiErrorMessage(failure)),
                  },
                );
              }}
            >
              Post QA disposition
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

export function GoodsReceiptDetailScreen() {
  const mayViewCommercialCost = usePurchasingCommercialCost();
  const mayEdit = usePermission("purchasing.goods-receipt.create");
  const mayPost = usePermission("purchasing.goods-receipt.post");
  const mayReverse = usePermission("purchasing.goods-receipt.reverse");
  const { id } = useParams<{ id: string }>();
  const receiptId = Number(id);
  const navigate = useNavigate();
  const query = useGoodsReceiptsShow(receiptId);
  const receipt = query.data?.status === 200 ? query.data.data.data : undefined;
  const supplierQuery = useSupplierAccountsShow(
    receipt?.supplier_account_id ?? 0,
    { include: "party" },
    { query: { enabled: !!receipt } },
  );
  const supplier =
    supplierQuery.data?.status === 200
      ? supplierQuery.data.data.data
      : undefined;
  const itemsQuery = useItemsIndex({ per_page: 100, sort: "name" });
  const items =
    itemsQuery.data?.status === 200 ? itemsQuery.data.data.data : [];
  const locationsQuery = useLocationsIndex({ per_page: 100, sort: "code" });
  const locations =
    locationsQuery.data?.status === 200 ? locationsQuery.data.data.data : [];
  const uomsQuery = useUomsIndex({ per_page: 100, sort: "name" });
  const uoms = uomsQuery.data?.status === 200 ? uomsQuery.data.data.data : [];
  const currenciesQuery = useCurrenciesIndex({ per_page: 100, sort: "code" });
  const currencies =
    currenciesQuery.data?.status === 200 ? currenciesQuery.data.data.data : [];
  const post = useGoodsReceiptsPost();
  const reverse = useGoodsReceiptsReverse();
  const [confirmPost, setConfirmPost] = useState(false);
  // A package-tracked line cannot post until what physically arrived has been recorded (the server enforces this too).
  const [needsComposition, setNeedsComposition] = useState<
    Record<number, boolean>
  >({});
  const missingComposition = Object.values(needsComposition).some(Boolean);

  const [showReverse, setShowReverse] = useState(false);
  const [packageReversal, setPackageReversal] = useState<ReversalDraws>({ draws: [], blocked: false });
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const postHeadingRef = useFocusOnOpen<HTMLHeadingElement>(confirmPost);
  const reverseHeadingRef = useFocusOnOpen<HTMLHeadingElement>(showReverse);
  const { contentRef: printRef, print } = useQuickPrint({
    documentTitle: receipt?.number
      ? `Goods Receipt ${receipt.number}`
      : `Draft Goods Receipt #${receiptId}`,
  });

  if (query.isLoading)
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  if (!receipt || query.isError)
    return (
      <div className="space-y-4 p-6">
        <p className="text-sm text-destructive">
          Couldn't load this goods receipt.
        </p>
        <Button variant="outline" onClick={() => navigate("/goods-receipts")}>
          Back to receipts
        </Button>
      </div>
    );
  const supplierLabel = supplier
    ? `${supplier.code}${supplier.party?.name ? ` — ${supplier.party.name}` : ""}`
    : `Supplier #${receipt.supplier_account_id}`;
  const label = receipt.number
    ? `Goods receipt ${receipt.number}`
    : `Draft receipt #${receipt.id}`;
  const refetch = () => query.refetch();
  // A line's qa_unallocated_qty only drops below its stock_qty once at least
  // one QualityDisposition has been posted against it (qa_root_allocated_qty
  // > 0) — mirrors ReverseGoodsReceipt's own per-line existence check without
  // a separate query, since qa aggregates are already loaded with every line.
  const hasQaDisposition = (receipt.lines ?? []).some(
    (line) =>
      line.qa_unallocated_qty !== null &&
      Number(line.qa_unallocated_qty) < Number(line.stock_qty),
  );

  const printSections: DetailPrintSection[] = [
    {
      title: "Receipt",
      fields: [
        { label: "Status", value: receipt.status },
        { label: "Supplier", value: supplierLabel },
        {
          label: "Physical arrival",
          value: formatDateTime(receipt.physical_arrival_at) ?? "",
        },
        { label: "Posting date", value: receipt.posting_date },
        { label: "Correction reason", value: receipt.correction_reason ?? "" },
      ],
    },
  ];
  const printLineColumns: DataColumn<GoodsReceiptLineResource>[] = [
    {
      key: "item",
      header: "Item",
      value: (line) => {
        const item = items.find((candidate) => candidate.id === line.item_id);
        return item ? `${item.code} — ${item.name}` : `Item #${line.item_id}`;
      },
    },
    {
      key: "location",
      header: "Location",
      value: (line) => {
        const location = locations.find(
          (candidate) => candidate.id === line.location_id,
        );
        return location
          ? formatLocationOption(location)
          : `Location #${line.location_id}`;
      },
    },
    {
      key: "received_qty",
      header: "Received",
      value: (line) =>
        `${line.received_qty} ${line.package_name ?? uoms.find((uom) => uom.id === line.received_uom_id)?.code ?? ""}`,
      align: "right",
    },
    {
      key: "stock_qty",
      header: "Stock qty",
      value: (line) =>
        `${line.stock_qty} ${uoms.find((uom) => uom.id === line.stock_uom_id)?.code ?? ""}`,
      align: "right",
    },
    ...(mayViewCommercialCost
      ? [
          {
            key: "provisional_unit_cost",
            header: "Provisional cost",
            value: (line) =>
              `${line.provisional_unit_cost} ${currencies.find((currency) => currency.id === line.currency_id)?.code ?? ""}`,
            align: "right",
          } satisfies DataColumn<GoodsReceiptLineResource>,
        ]
      : []),
    {
      key: "identity",
      header: "Batch / Serial",
      value: (line) => line.batch_no ?? line.serial_no ?? "",
    },
  ];

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={label}
        description={supplierLabel}
        breadcrumbs={[
          { label: "Goods Receipts", to: "/goods-receipts" },
          { label: receipt.number ?? `Draft #${receipt.id}` },
        ]}
        actions={
          <>
            <PrintTrigger onPrint={() => print()} />
            {receipt.status === "DRAFT" && mayEdit && (
              <Button
                variant="outline"
                onClick={() => navigate(`/goods-receipts/${receipt.id}/edit`)}
              >
                Edit draft
              </Button>
            )}
            {receipt.status === "DRAFT" && mayPost && (
              <Button
                onClick={() => setConfirmPost(true)}
                disabled={missingComposition}
                title={
                  missingComposition
                    ? "Record what arrived for each package-tracked line first"
                    : undefined
                }
              >
                Post receipt
              </Button>
            )}
            {receipt.status === "POSTED" && mayReverse && (
              <div className="flex flex-col items-end gap-1">
                <Button
                  variant="outline"
                  onClick={() => setShowReverse((value) => !value)}
                  disabled={hasQaDisposition}
                  title={
                    hasQaDisposition
                      ? "This receipt has a posted QA disposition and cannot be reversed"
                      : undefined
                  }
                >
                  Reverse receipt
                </Button>
                {hasQaDisposition && (
                  <span className="text-xs text-muted-foreground">
                    Blocked by a posted QA disposition
                  </span>
                )}
              </div>
            )}
          </>
        }
      />
      {error && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
          {error}
        </div>
      )}
      {confirmPost && receipt.status === "DRAFT" && (
        <section className="space-y-3 rounded-md border p-4">
          <h2
            ref={postHeadingRef}
            tabIndex={-1}
            className="font-medium outline-none"
          >
            Post this physical receipt?
          </h2>
          <p className="text-sm text-muted-foreground">
            Posting allocates the receipt number, freezes every line and creates
            the stock and valuation movements. Corrections require reversal.
          </p>
          <div className="flex gap-2">
            <Button
              disabled={post.isPending}
              onClick={() => {
                setError(null);
                post.mutate(
                  { goodsReceipt: receipt.id },
                  {
                    onSuccess: () => {
                      setConfirmPost(false);
                      refetch();
                    },
                    onError: (failure) => setError(getApiErrorMessage(failure)),
                  },
                );
              }}
            >
              Confirm posting
            </Button>
            <Button variant="outline" onClick={() => setConfirmPost(false)}>
              Keep draft
            </Button>
          </div>
        </section>
      )}
      {showReverse && receipt.status === "POSTED" && (
        <section className="space-y-3 rounded-md border border-destructive/40 p-4">
          <h2
            ref={reverseHeadingRef}
            tabIndex={-1}
            className="font-medium outline-none"
          >
            Reverse this receipt?
          </h2>
          <p className="text-sm text-muted-foreground">
            Reversal posts opposite inventory effects. It is blocked after
            downstream consumption or any QA disposition.
          </p>
          <ReceiptReversalPanel
            receiptId={receipt.id}
            describe={(itemId) => {
              const item = items.find((candidate) => candidate.id === itemId);
              const unit = uoms.find((candidate) => candidate.id === item?.stock_uom_id)?.code ?? "";

              return { label: item ? `${item.code} — ${item.name}` : `Item #${itemId}`, unit };
            }}
            onChange={setPackageReversal}
          />
          <Textarea
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            placeholder="Correction reason"
          />
          <div className="flex gap-2">
            <Button
              variant="destructive"
              disabled={!reason.trim() || reverse.isPending || packageReversal.blocked}
              onClick={() => {
                setError(null);
                reverse.mutate(
                  {
                    goodsReceipt: receipt.id,
                    data: {
                      reason: reason.trim(),
                      ...(packageReversal.draws.length > 0 ? { package_draws: packageReversal.draws } : {}),
                    },
                  },
                  {
                    onSuccess: () => {
                      setShowReverse(false);
                      refetch();
                    },
                    onError: (failure) => setError(getApiErrorMessage(failure)),
                  },
                );
              }}
            >
              Confirm reversal
            </Button>
            <Button variant="outline" onClick={() => setShowReverse(false)}>
              Keep receipt
            </Button>
          </div>
        </section>
      )}
      <DetailPageLayout>
        <DetailSection title="Receipt">
          <DetailField label="Status">
            <Badge
              variant={
                receipt.status === "REVERSED"
                  ? "destructive"
                  : receipt.status === "POSTED"
                    ? "default"
                    : "secondary"
              }
            >
              {receipt.status}
            </Badge>
          </DetailField>
          <DetailField label="Supplier">{supplierLabel}</DetailField>
          <DetailField label="Physical arrival">
            {formatDateTime(receipt.physical_arrival_at)}
          </DetailField>
          <DetailField label="Posting date">{receipt.posting_date}</DetailField>
          {receipt.correction_reason && (
            <DetailField label="Correction reason">
              {receipt.correction_reason}
            </DetailField>
          )}
        </DetailSection>
      </DetailPageLayout>
      <section className="space-y-4">
        <div>
          <h2 className="text-lg font-semibold">Receipt lines</h2>
          <p className="text-sm text-muted-foreground">
            Posted quantities are on the stock-UoM basis; inspection-required
            lines remain held until terminal dispositions cover them.
          </p>
        </div>
        {(receipt.lines ?? []).map((line) => {
          const item = items.find((candidate) => candidate.id === line.item_id);
          const location = locations.find(
            (candidate) => candidate.id === line.location_id,
          );
          const receivedUom = uoms.find(
            (candidate) => candidate.id === line.received_uom_id,
          );
          const stockUom = uoms.find(
            (candidate) => candidate.id === line.stock_uom_id,
          );
          const currency = currencies.find(
            (candidate) => candidate.id === line.currency_id,
          );
          return (
            <article key={line.id} className="space-y-3 rounded-md border p-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h3 className="font-medium">
                    {item
                      ? `${item.code} — ${item.name}`
                      : `Item #${line.item_id}`}
                  </h3>
                  <p className="text-sm text-muted-foreground">
                    PO line #{line.purchase_order_line_id} ·{" "}
                    {location
                      ? formatLocationOption(location)
                      : `Location #${line.location_id}`}
                  </p>
                </div>
                {(line.batch_no || line.serial_no) && (
                  <Badge variant="outline">
                    {line.batch_no
                      ? `Batch ${line.batch_no}`
                      : `Serial ${line.serial_no}`}
                  </Badge>
                )}
              </div>
              <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                  <span className="text-muted-foreground">Received</span>
                  <div className="tabular-nums">
                    {line.received_qty} {line.package_name ?? receivedUom?.code}
                  </div>
                </div>
                <div>
                  <span className="text-muted-foreground">Stock quantity</span>
                  <div className="tabular-nums">
                    {line.stock_qty} {stockUom?.code}
                  </div>
                </div>
                {mayViewCommercialCost && (
                  <div>
                    <span className="text-muted-foreground">
                      Provisional cost
                    </span>
                    <div className="tabular-nums">
                      {line.provisional_unit_cost} {currency?.code}
                    </div>
                  </div>
                )}
                {mayViewCommercialCost && (
                  <div>
                    <span className="text-muted-foreground">FX rate</span>
                    <div className="tabular-nums">{line.fx_rate}</div>
                  </div>
                )}
              </div>
              <ReceiptCompositionPanel
                lineId={line.id}
                itemId={line.item_id}
                stockQty={line.stock_qty}
                unit={stockUom?.code ?? ""}
                editable={receipt.status === "DRAFT" && mayEdit}
                observed={
                  (line as unknown as { package_composition?: ObservedRow[] })
                    .package_composition ?? []
                }
                commercialPackagingId={line.item_packaging_id ?? null}
                commercialPackageCount={line.received_package_qty ?? null}
                onChanged={refetch}
                onNeedsComposition={(lineId, needs) =>
                  setNeedsComposition((current) =>
                    current[lineId] === needs
                      ? current
                      : { ...current, [lineId]: needs },
                  )
                }
              />
              <QualityPanel
                line={line}
                enabled={receipt.status === "POSTED"}
                onChanged={refetch}
              />
            </article>
          );
        })}
      </section>

      {/* Quick Print — a dedicated print-only layout, hidden until react-to-print clones it. */}
      <div className="hidden">
        <div ref={printRef}>
          <DetailPrintLayout
            title={
              receipt.number
                ? `GOODS RECEIPT ${receipt.number}`
                : `DRAFT GOODS RECEIPT #${receipt.id}`
            }
            printedAt={new Date()}
            sections={printSections}
          >
            <PrintableTable
              columns={printLineColumns}
              rows={receipt.lines ?? []}
              getRowKey={(line) => line.id}
            />
          </DetailPrintLayout>
        </div>
      </div>
    </div>
  );
}
