import { useEffect, useState } from "react";
import { useItemsById, useLocationsById, useUomsById } from "./use-line-lookups";
import {
  QaPortionStep,
  decidesPackages,
  type QaPortion,
} from "../package-stock/qa-portion-step";
import { RejectedStockActions } from "../rejected-exit/rejected-stock-actions";
import { useTrackingState } from "../package-stock/use-tracking-state";
import {
  ReceiptReversalPanel,
  type ReversalDraws,
} from "../package-stock/receipt-reversal-panel";
import { useLocation, useNavigate, useParams } from "react-router";
import { RecordSheet } from "../../components/page/record-sheet";
import { ScreenTemplate } from "../../components/page/screen-template";
import type { Presentation } from "../../lib/presentation";
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
  useUomsShow,
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
import { AlertCircle } from "lucide-react";
import { GoodsReceiptStatusBadge } from "./goods-receipt-columns";
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
  // A grouped item states which held packages the decision applies to (ADR 0094); a quantity-only item shows nothing extra.
  const tracking = useTrackingState(line.item_id, enabled);
  const grouped = tracking?.mode === "PACKAGE_BALANCE";
  const uomQuery = useUomsShow(tracking?.stock_uom_id ?? 0, {
    query: { enabled: !!tracking },
  });
  const unit =
    uomQuery.data?.status === 200 ? uomQuery.data.data.data.code : "";
  const needsPortion = grouped && decidesPackages(outcome);
  const [portion, setPortion] = useState<QaPortion>({
    portion: [],
    ready: false,
  });
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
          {needsPortion && (
            <QaPortionStep
              lineId={line.id}
              outcome={outcome}
              quantity={qty}
              unit={unit}
              onChange={setPortion}
            />
          )}
          {error && (
            <p className="text-sm text-destructive md:col-span-4">{error}</p>
          )}
          <div className="md:col-span-4">
            <Button
              disabled={
                !qty ||
                !reason.trim() ||
                create.isPending ||
                (needsPortion && !portion.ready)
              }
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
                      ...(needsPortion
                        ? { package_portion: portion.portion }
                        : {}),
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

export interface GoodsReceiptDetailScreenProps {
  presentation?: Presentation;
}

export function GoodsReceiptDetailScreen({
  presentation = "sheet",
}: GoodsReceiptDetailScreenProps) {
  const mayViewCommercialCost = usePurchasingCommercialCost();
  const mayEdit = usePermission("purchasing.goods-receipt.create");
  const mayPost = usePermission("purchasing.goods-receipt.post");
  const mayReverse = usePermission("purchasing.goods-receipt.reverse");
  const { id } = useParams<{ id: string }>();
  const receiptId = Number(id);
  const navigate = useNavigate();
  const { search } = useLocation();
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
  const itemList =
    itemsQuery.data?.status === 200 ? itemsQuery.data.data.data : [];
  const receiptLines = receipt?.lines ?? [];
  // A list page is not a lookup table: fetch whatever the lines name that the page does not hold.
  const items = useItemsById(receiptLines.map((line) => line.item_id), itemList);
  const locationsQuery = useLocationsIndex({ per_page: 100, sort: "code" });
  const locations = useLocationsById(
    receiptLines.map((line) => line.location_id),
    locationsQuery.data?.status === 200 ? locationsQuery.data.data.data : [],
  );
  const uomsQuery = useUomsIndex({ per_page: 100, sort: "name" });
  const uoms = useUomsById(
    [...receiptLines.flatMap((line) => [line.received_uom_id, line.stock_uom_id]), ...items.map((item) => item.stock_uom_id)],
    uomsQuery.data?.status === 200 ? uomsQuery.data.data.data : [],
  );
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
  const [packageReversal, setPackageReversal] = useState<ReversalDraws>({
    draws: [],
    blocked: false,
  });
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const postHeadingRef = useFocusOnOpen<HTMLHeadingElement>(confirmPost);
  const reverseHeadingRef = useFocusOnOpen<HTMLHeadingElement>(showReverse);
  const { contentRef: printRef, print } = useQuickPrint({
    documentTitle: receipt?.number
      ? `Goods Receipt ${receipt.number}`
      : `Draft Goods Receipt #${receiptId}`,
  });

  if (query.isLoading) {
    if (presentation === "sheet") {
      return (
        <RecordSheet
          title="Loading receipt…"
          size="xl"
          className="erp-record"
          dismissOnOutsideClick={false}
          onClose={() => navigate({ pathname: "/goods-receipts", search })}
        >
          <div className="space-y-4 p-4">
            <Skeleton className="h-9 w-64" />
            <Skeleton className="h-64 w-full" />
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="detail" className="erp-screen">
        <div className="space-y-4">
          <Skeleton className="h-9 w-64" />
          <Skeleton className="h-64 w-full" />
        </div>
      </ScreenTemplate>
    );
  }

  if (!receipt || query.isError) {
    if (presentation === "sheet") {
      return (
        <RecordSheet
          title="Error"
          size="xl"
          className="erp-record"
          dismissOnOutsideClick={false}
          onClose={() => navigate({ pathname: "/goods-receipts", search })}
        >
          <div className="space-y-4 p-4">
            <p className="text-sm text-destructive">
              Couldn't load this goods receipt.
            </p>
            <Button
              variant="outline"
              onClick={() => navigate({ pathname: "/goods-receipts", search })}
            >
              Back to receipts
            </Button>
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="detail" className="erp-screen">
        <div className="space-y-4">
          <p className="text-sm text-destructive">
            Couldn't load this goods receipt.
          </p>
          <Button
            variant="outline"
            onClick={() => navigate({ pathname: "/goods-receipts", search })}
          >
            Back to receipts
          </Button>
        </div>
      </ScreenTemplate>
    );
  }
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

  const headerActions = (
    <>
      <PrintTrigger onPrint={() => print()} />
      {receipt.status === "DRAFT" && mayEdit && (
        <Button
          variant="outline"
          onClick={() =>
            navigate({ pathname: `/goods-receipts/${receipt.id}/edit`, search })
          }
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
      )}
    </>
  );

  const detailBody = (
    <>
      {error && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
          {error}
        </div>
      )}
      {receipt.status === "POSTED" && hasQaDisposition && (
        <div className="flex items-start gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3.5 text-sm text-amber-900 dark:text-amber-200">
          <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
          <div>
            <div className="font-semibold">
              Reversal locked by QA disposition
            </div>
            <p className="mt-0.5 text-xs text-muted-foreground">
              A quality disposition has already been recorded against lines in
              this receipt. Receipts with active QA dispositions cannot be
              reversed.
            </p>
          </div>
        </div>
      )}
      {confirmPost && receipt.status === "DRAFT" && (
        <section className="space-y-3 rounded-lg border bg-card p-4 shadow-xs">
          <h2
            ref={postHeadingRef}
            tabIndex={-1}
            className="text-sm font-semibold text-foreground outline-none"
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
        <section className="space-y-3 rounded-lg border border-destructive/40 bg-card p-4 shadow-xs">
          <h2
            ref={reverseHeadingRef}
            tabIndex={-1}
            className="text-sm font-semibold text-foreground outline-none"
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
              const unit =
                uoms.find((candidate) => candidate.id === item?.stock_uom_id)
                  ?.code ?? "";

              return {
                label: item ? `${item.code} — ${item.name}` : `Item #${itemId}`,
                unit,
              };
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
              disabled={
                !reason.trim() || reverse.isPending || packageReversal.blocked
              }
              onClick={() => {
                setError(null);
                reverse.mutate(
                  {
                    goodsReceipt: receipt.id,
                    data: {
                      reason: reason.trim(),
                      ...(packageReversal.draws.length > 0
                        ? { package_draws: packageReversal.draws }
                        : {}),
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
      <DetailPageLayout equalHeight>
        <DetailSection title="Receipt">
          <DetailField label="Status">
            <GoodsReceiptStatusBadge status={receipt.status} />
          </DetailField>
          <DetailField label="Receipt number">
            <span className="font-semibold text-foreground">
              {receipt.number ?? `Draft #${receipt.id}`}
            </span>
          </DetailField>
          <DetailField label="Physical arrival">
            {formatDateTime(receipt.physical_arrival_at)}
          </DetailField>
          <DetailField label="Posting date">{receipt.posting_date}</DetailField>
          {receipt.reversal_of_id && (
            <DetailField label="Reversal of">
              Receipt #{receipt.reversal_of_id}
            </DetailField>
          )}
          {receipt.correction_reason && (
            <DetailField label="Correction reason" fullWidth>
              {receipt.correction_reason}
            </DetailField>
          )}
        </DetailSection>

        <DetailSection title="Supplier">
          <DetailField label="Supplier">{supplierLabel}</DetailField>
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
              <a
                href={supplier.party.website}
                target="_blank"
                rel="noreferrer"
                className="text-primary hover:underline"
              >
                {supplier.party.website}
              </a>
            </DetailField>
          )}
        </DetailSection>

        {mayViewCommercialCost && (
          <DetailSection title="Commercial">
            <DetailField label="Currency">
              {receipt.currency_id &&
              currencies.find((c) => c.id === Number(receipt.currency_id))
                ? `${currencies.find((c) => c.id === Number(receipt.currency_id))?.code} — ${currencies.find((c) => c.id === Number(receipt.currency_id))?.name}`
                : receipt.currency_id
                  ? `Currency #${receipt.currency_id}`
                  : "—"}
            </DetailField>
            <DetailField label="Total value">
              <span className="tabular-nums font-semibold text-foreground">
                {receipt.total_amount
                  ? `${(receipt.currency_id && currencies.find((c) => c.id === Number(receipt.currency_id))?.code) ?? ""} ${Number(receipt.total_amount).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 4 })}`
                  : "—"}
              </span>
            </DetailField>
            <DetailField label="Total lines">
              <span className="tabular-nums font-medium">
                {receipt.lines?.length ?? receipt.lines_count ?? 0}
              </span>
            </DetailField>
          </DetailSection>
        )}

        <DetailSection title="Record information">
          <DetailField label="Recorded at">
            {formatDateTime(receipt.recorded_at)}
          </DetailField>
          {receipt.posted_at && (
            <DetailField label="Posted at">
              {formatDateTime(receipt.posted_at)}
            </DetailField>
          )}
          {receipt.reversed_at && (
            <DetailField label="Reversed at">
              {formatDateTime(receipt.reversed_at)}
            </DetailField>
          )}
          {receipt.created_at && (
            <DetailField label="Created at">
              {formatDateTime(receipt.created_at)}
            </DetailField>
          )}
        </DetailSection>
      </DetailPageLayout>

      <section className="space-y-4">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-base font-semibold text-foreground">
              Receipt lines
            </h2>
            <p className="text-xs text-muted-foreground">
              Posted quantities are on the stock-UoM basis; inspection-required
              lines remain held until terminal dispositions cover them.
            </p>
          </div>
          <Badge variant="secondary" className="text-xs font-normal">
            {receipt.lines?.length ?? 0}{" "}
            {receipt.lines?.length === 1 ? "line" : "lines"}
          </Badge>
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
          const lineCurrency = currencies.find(
            (candidate) =>
              candidate.id === Number(line.currency_id ?? receipt.currency_id),
          );
          return (
            <article
              key={line.id}
              className="space-y-3 rounded-lg border bg-card p-4 shadow-xs"
            >
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h3 className="font-semibold text-foreground">
                    {item
                      ? `${item.code} — ${item.name}`
                      : `Item #${line.item_id}`}
                  </h3>
                  <p className="text-xs text-muted-foreground mt-0.5">
                    {line.purchase_order_line_id
                      ? `PO line #${line.purchase_order_line_id} · `
                      : ""}
                    {location
                      ? formatLocationOption(location)
                      : `Location #${line.location_id}`}
                  </p>
                </div>
                {(line.batch_no || line.serial_no) && (
                  <Badge variant="outline" className="text-xs font-mono">
                    {line.batch_no
                      ? `Batch ${line.batch_no}`
                      : `Serial ${line.serial_no}`}
                  </Badge>
                )}
              </div>
              <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4 rounded-md bg-muted/40 p-3">
                <div>
                  <span className="text-xs font-medium text-muted-foreground">
                    Received
                  </span>
                  <div className="tabular-nums font-semibold mt-0.5">
                    {line.received_qty} {line.package_name ?? receivedUom?.code}
                  </div>
                </div>
                <div>
                  <span className="text-xs font-medium text-muted-foreground">
                    Stock quantity
                  </span>
                  <div className="tabular-nums font-semibold mt-0.5">
                    {line.stock_qty} {stockUom?.code}
                  </div>
                </div>
                {mayViewCommercialCost && (
                  <div>
                    <span className="text-xs font-medium text-muted-foreground">
                      Provisional unit cost
                    </span>
                    <div className="tabular-nums font-semibold mt-0.5">
                      {line.provisional_unit_cost !== undefined &&
                      line.provisional_unit_cost !== null
                        ? `${Number(line.provisional_unit_cost).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 4 })} ${lineCurrency?.code ?? ""}`
                        : "—"}
                    </div>
                  </div>
                )}
                {mayViewCommercialCost && (
                  <div>
                    <span className="text-xs font-medium text-muted-foreground">
                      Provisional line amount
                    </span>
                    <div className="tabular-nums font-semibold mt-0.5">
                      {line.provisional_amount !== undefined &&
                      line.provisional_amount !== null
                        ? `${Number(line.provisional_amount).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 4 })} ${lineCurrency?.code ?? ""}`
                        : "—"}
                    </div>
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
              <RejectedStockActions
                receiptId={receipt.id}
                lineId={line.id}
                enabled={receipt.status === "POSTED"}
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
    </>
  );

  if (presentation === "sheet") {
    return (
      <RecordSheet
        title={label}
        description={supplierLabel}
        actions={headerActions}
        size="xl"
        className="erp-record"
        onClose={() => navigate({ pathname: "/goods-receipts", search })}
      >
        <div className="space-y-4 pb-6">{detailBody}</div>
      </RecordSheet>
    );
  }

  return (
    <ScreenTemplate kind="detail" className="erp-screen">
      <div className="space-y-4">
        <PageHeader
          title={label}
          description={supplierLabel}
          breadcrumbs={[
            { label: "Goods Receipts", to: "/goods-receipts" },
            { label: receipt.number ?? `Draft #${receipt.id}` },
          ]}
          actions={headerActions}
        />
        {detailBody}
      </div>
    </ScreenTemplate>
  );
}
