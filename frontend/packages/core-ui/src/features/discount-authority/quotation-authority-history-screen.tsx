import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { DetailField } from "../../components/detail/detail-field";
import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
import { Button } from "../../components/ui/button";
import { Skeleton } from "../../components/ui/skeleton";
import { StatusBadge } from "../../components/ui/status-badge";
import { describeLoadError, isApiError, shouldRetryQuery } from "../../lib/api-errors";
import { formatDateTime } from "../../lib/format-date";
import { AccessDenied } from "../../routes/permission-route";
import { useAuthStore } from "../../stores/auth-store";
import { QuotationScreenShell } from "../quotations/quotation-screen-shell";
import { discountAuthorityAccess } from "./discount-authority-access";
import { liveQuotationAuthorityGateway } from "./discount-authority-gateway";
import {
  MODE_VIEW,
  historyState,
  isDemoOnly,
  reasonText,
  type AuthorityHistoryPage,
  type AuthorityHistoryRecord,
  type QuotationAuthorityGateway,
} from "./discount-authority-contract";
import { bandLimits } from "./quotation-discount-authority-panel";

function HistoryRow({ record }: { record: AuthorityHistoryRecord }) {
  const state = historyState(record);
  const mode = MODE_VIEW[record.mode];
  const ev = record.evaluation;
  const band = ev.required_band;
  const figures =
    ev.reference_amount !== null &&
    (state.key === "WITHIN_AUTHORITY" || state.key === "NEEDS_APPROVAL" || state.key === "CANNOT_EVALUATE");
  return (
    <li className="min-w-0 space-y-2 rounded-lg border border-border p-3 text-sm">
      <div className="flex flex-wrap items-center gap-2">
        <h3 className="font-semibold">{formatDateTime(record.recorded_at)}</h3>
        <StatusBadge tone={state.tone}>{state.label}</StatusBadge>
        <StatusBadge tone={mode.tone}>Setting then: {mode.label}</StatusBadge>
      </div>
      <p>
        Recorded for draft version {record.input_generation} · by person #{record.actor_id}
      </p>
      {figures && (
        <dl className="grid gap-x-4 gap-y-1 sm:grid-cols-2">
          <div><dt className="inline text-muted-foreground">Trusted reference: </dt><dd className="inline tabular-nums">{ev.reference_amount}</dd></div>
          <div><dt className="inline text-muted-foreground">Final net: </dt><dd className="inline tabular-nums">{ev.final_net_amount}</dd></div>
          <div><dt className="inline text-muted-foreground">Reduction: </dt><dd className="inline tabular-nums">{ev.reduction_amount}</dd></div>
          {ev.document_percent_exact !== null && (
            <div><dt className="inline text-muted-foreground">Document discount: </dt><dd className="inline tabular-nums">{ev.document_percent_exact}%</dd></div>
          )}
        </dl>
      )}
      {state.key === "NEEDS_APPROVAL" && (
        <p className="break-words">
          Approver needed: {band ? `“${band.label}”${isDemoOnly(band.label) ? " (demonstration data)" : ""}${bandLimits(band).length > 0 ? `, up to ${bandLimits(band).join(", ")}` : ""}` : "no authority level could approve this"}
        </p>
      )}
      {ev.reasons.length > 0 && (
        <ul className="list-inside list-disc space-y-1">
          {ev.reasons.map((code) => (
            <li key={code} className="break-words">{reasonText(code)}</li>
          ))}
        </ul>
      )}
    </li>
  );
}

/** Presentational: one page of recorded evaluations, newest first, with plain paging. */
export function AuthorityHistoryView({
  history,
  onPage,
  loading = false,
}: {
  history: AuthorityHistoryPage;
  onPage?: (page: number) => void;
  loading?: boolean;
}) {
  const pages = Math.max(1, Math.ceil(history.total / history.per_page));
  return (
    <DetailPageLayout>
      <DetailSection
        title="Recorded results"
        description="Each time this quotation was checked against discount authority, exactly as it was recorded. Past results are never recalculated."
        fullWidth
      >
        <DetailField label="Company setting now">
          <StatusBadge tone={MODE_VIEW[history.mode].tone}>{MODE_VIEW[history.mode].label}</StatusBadge>
        </DetailField>
        <DetailField label="Recorded results">{history.total}</DetailField>
        {history.records.length === 0 ? (
          <p className="col-span-full text-sm">No result has been recorded for this quotation.</p>
        ) : (
          <ul className="col-span-full space-y-3" aria-label="Recorded discount authority results">
            {history.records.map((record) => (
              <HistoryRow key={record.id} record={record} />
            ))}
          </ul>
        )}
        {pages > 1 && onPage && (
          <nav className="col-span-full flex flex-wrap items-center gap-3" aria-label="Pages of recorded results">
            <Button className="min-h-11" type="button" variant="outline" disabled={loading || history.page <= 1} onClick={() => onPage(history.page - 1)}>
              Newer
            </Button>
            <span className="text-sm">Page {history.page} of {pages}</span>
            <Button className="min-h-11" type="button" variant="outline" disabled={loading || history.page >= pages} onClick={() => onPage(history.page + 1)}>
              Older
            </Button>
          </nav>
        )}
      </DetailSection>
    </DetailPageLayout>
  );
}

/**
 * Protected evaluation history of one quotation. Needs the evidence duty with quotation view (and the quotation UI gate);
 * denies before any query otherwise. A person without access to this quotation gets the same "not available" as a missing one.
 */
export function QuotationAuthorityHistoryScreen({
  revisionId,
  gateway = liveQuotationAuthorityGateway,
}: {
  revisionId: number;
  gateway?: QuotationAuthorityGateway;
}) {
  const user = useAuthStore((s) => s.user);
  const access = discountAuthorityAccess(
    new Set((user?.permissions ?? []).filter((p): p is string => typeof p === "string")),
  );
  if (!access.evidence) return <AccessDenied />;
  return (
    <QuotationScreenShell
      title="Discount authority history"
      description="Every recorded discount authority result for this quotation."
      revisionId={revisionId}
    >
      <HistoryQuery key={`${user?.id}:${revisionId}`} revisionId={revisionId} gateway={gateway} actorId={user?.id} />
    </QuotationScreenShell>
  );
}

function HistoryQuery({
  revisionId,
  gateway,
  actorId,
}: {
  revisionId: number;
  gateway: QuotationAuthorityGateway;
  actorId: number | string | undefined;
}) {
  const [page, setPage] = useState(1);
  const query = useQuery({
    queryKey: ["quotation-discount-authority-history", actorId ?? "fixture", revisionId, page],
    queryFn: () => gateway.history(revisionId, page),
    retry: shouldRetryQuery,
    placeholderData: (previous) => previous,
  });
  if (query.isError) {
    const status = isApiError(query.error) ? query.error.status : 0;
    return (
      <div className="space-y-2" role="alert">
        <p className="text-sm">
          {status === 403 || status === 404
            ? "The discount authority history is not available to you for this quotation."
            : describeLoadError(query.error)}
        </p>
        {status !== 403 && status !== 404 && (
          <Button className="min-h-11" type="button" variant="outline" onClick={() => void query.refetch()}>
            Try again
          </Button>
        )}
      </div>
    );
  }
  if (!query.data)
    return (
      <div role="status">
        <Skeleton className="h-48 w-full" />
        <span className="sr-only">Loading discount authority history…</span>
      </div>
    );
  return <AuthorityHistoryView history={query.data} onPage={setPage} loading={query.isFetching} />;
}
