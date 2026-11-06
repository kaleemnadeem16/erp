import { useEffect, useState } from "react";
import { Link } from "react-router";
import { useQuotationsShow, useQuotationsPreview } from "@erp/core-api";
import { RecordSheet } from "../../components/page/record-sheet";
import { Button } from "../../components/ui/button";
import { Skeleton } from "../../components/ui/skeleton";
import { useAuthStore } from "../../stores/auth-store";
import { quotationAccess } from "./quotation-access";
import {
  QuotationFamilyAccessPanel,
  useFamilyAccessData,
} from "./quotation-family-access-panel";
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
  if (!review && detail.data)
    return (
      <>
        <QuotationMetadata draft={detail.data} />
      </>
    );
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
  const header = useQuotationsShow(revisionId, {
    query: {
      enabled: access.detail,
      select: (response) =>
        response.status === 200 ? readDraft(response.data.data) : undefined,
    },
  });
  const scope = useFamilyAccessData(
    header.data?.family_id ?? null,
    {},
    undefined,
    access.detail,
  );
  useEffect(() => {
    if (scope.data?.allowed_actions.prepare === false) setTab("details");
  }, [scope.data?.allowed_actions.prepare]);

  if (!access.detail) return null;
  return (
    <RecordSheet
      title={`Quotation ${revisionId}`}
      description="Quotation details"
      size="lg"
      onClose={onClose}
      actions={
        <>
          {access.review &&
            scope.isSuccess &&
            !scope.isFetching &&
            scope.data.allowed_actions.prepare && (
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
      {header.isError || scope.isError ? (
        <QuotationFailure error={header.error ?? scope.error} />
      ) : header.isPending || scope.isPending || scope.isFetching ? (
        <div role="status">Loading current quotation access…</div>
      ) : tab === "review" && !scope.data.allowed_actions.prepare ? (
        <QuotationFailure error={Object.assign(new Error(), { status: 404 })} />
      ) : (
        <QuotationPanelBody revisionId={revisionId} tab={tab} />
      )}
      {header.data && (
        <div hidden={tab === "review" || !scope.isSuccess || scope.isFetching}>
          <QuotationFamilyAccessPanel familyId={header.data.family_id} />
        </div>
      )}
    </RecordSheet>
  );
}
