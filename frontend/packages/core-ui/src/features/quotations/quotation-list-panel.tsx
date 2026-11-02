import { useState } from "react";
import { Link } from "react-router";
import { useQuotationsShow, useQuotationsPreview } from "@erp/core-api";
import { RecordSheet } from "../../components/page/record-sheet";
import { Button } from "../../components/ui/button";
import { Skeleton } from "../../components/ui/skeleton";
import { useAuthStore } from "../../stores/auth-store";
import { quotationAccess } from "./quotation-access";
import { readDraft, readPreview } from "./quotation-contract";
import {
  QuotationFailure,
  QuotationMetadata,
  QuotationReview,
} from "./quotation-display";

type PanelTab = "details" | "review";

/**
 * Side panel body: reuses the merged detail/review components and generated hooks.
 * A 404 renders the same non-disclosing "unavailable" state as the standalone route;
 * the review tab exists only for people who may prepare.
 */
export function QuotationPanelBody({
  revisionId,
  tab,
}: {
  revisionId: number;
  tab: PanelTab;
}) {
  const review = tab === "review";
  const detail = useQuotationsShow(revisionId, {
    query: {
      enabled: !review,
      select: (response) =>
        response.status === 200 ? readDraft(response.data.data) : undefined,
    },
  });
  const preview = useQuotationsPreview(revisionId, {
    query: {
      enabled: review,
      select: (response) =>
        response.status === 200 ? readPreview(response.data.data) : undefined,
    },
  });
  const query = review ? preview : detail;
  if (query.isError)
    return (
      <QuotationFailure
        error={query.error}
        retry={() => void query.refetch()}
      />
    );
  if (query.isPending)
    return (
      <div role="status">
        <Skeleton className="h-48 w-full" />
        <span className="sr-only">Loading quotation…</span>
      </div>
    );
  if (review && preview.data) return <QuotationReview preview={preview.data} />;
  if (!review && detail.data) return <QuotationMetadata draft={detail.data} />;
  return (
    <QuotationFailure
      error={
        new Error("The server returned an unsupported quotation response.")
      }
      retry={() => void query.refetch()}
    />
  );
}

export function QuotationListPanel({
  revisionId,
  onClose,
}: {
  revisionId: number;
  onClose: () => void;
}) {
  const user = useAuthStore((s) => s.user);
  const access = quotationAccess(
    new Set(
      (user?.permissions ?? []).filter(
        (p): p is string => typeof p === "string",
      ),
    ),
  );
  const [tab, setTab] = useState<PanelTab>("details");
  if (!access.detail) return null;
  return (
    <RecordSheet
      title={`Quotation ${revisionId}`}
      description="Quotation details"
      size="lg"
      onClose={onClose}
      actions={
        <>
          {access.review && (
            <Button
              type="button"
              variant="outline"
              aria-pressed={tab === "review"}
              onClick={() => setTab(tab === "review" ? "details" : "review")}
            >
              {tab === "review" ? "Show details" : "Review quotation"}
            </Button>
          )}
          <Link
            className="ui-link-action inline-flex min-h-11 items-center"
            to={`/quotations/${revisionId}`}
          >
            Open full page
          </Link>
        </>
      }
    >
      <QuotationPanelBody revisionId={revisionId} tab={tab} />
    </RecordSheet>
  );
}
