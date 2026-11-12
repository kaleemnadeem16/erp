import { useParams, Link, useLocation, useNavigate } from "react-router";
import { useQuotationsShow, useQuotationsPreview } from "@erp/core-api";
import { useAuthStore } from "../../stores/auth-store";
import { AccessDenied } from "../../routes/permission-route";
import { ScreenTemplate } from "../../components/page/screen-template";
import { PageHeader } from "../../components/page/page-header";
import { RecordSheet } from "../../components/page/record-sheet";
import type { Presentation } from "../../lib/presentation";
import { Skeleton } from "../../components/ui/skeleton";
import { quotationAccess } from "./quotation-access";
import {
  QuotationFamilyAccessPanel,
  useFamilyAccessData,
} from "./quotation-family-access-panel";
import { quotationApprovalAccess } from "./quotation-approval-access";
import { quotationMilestoneAccess } from "./quotation-milestone-access";
import { readDraft, readPreview, revisionId } from "./quotation-contract";
import {
  QuotationFailure,
  QuotationMetadata,
  QuotationReview,
} from "./quotation-display";

export function QuotationDetailScreen({
  review = false,
  presentation = "page",
}: {
  review?: boolean;
  presentation?: Presentation;
}) {
  const user = useAuthStore((state) => state.user);
  const access = quotationAccess(
    new Set(
      (user?.permissions ?? []).filter(
        (permission): permission is string => typeof permission === "string",
      ),
    ),
  );
  // Guard before mounting queries, including when this component is used outside ProtectedRoute.
  if (!(review ? access.review : access.detail)) return <AccessDenied />;
  return (
    <QuotationRecord
      review={review}
      mayDetail={access.detail}
      mayReview={access.review}
      presentation={presentation}
    />
  );
}

/** Internal renderer below the production gate; exported for isolated fixtures, not the package barrel. */
export function QuotationRecord({
  review,
  mayDetail,
  mayReview,
  presentation = "page",
}: {
  review: boolean;
  mayDetail: boolean;
  mayReview: boolean;
  presentation?: Presentation;
}) {
  const id = revisionId(useParams<{ revision: string }>().revision);
  const { search } = useLocation();
  const navigate = useNavigate();
  const user = useAuthStore((state) => state.user);
  // Issue and PDF screens deny on their own; the links only appear for people who can use them.
  const held = new Set(
    (user?.permissions ?? []).filter(
      (permission): permission is string => typeof permission === "string",
    ),
  );
  const milestone = quotationMilestoneAccess(held);
  const approvalAccess = quotationApprovalAccess(held);
  const detail = useQuotationsShow(id ?? 0, {
    query: {
      enabled: id !== null && !review,
      select: (response) =>
        response.status === 200 ? readDraft(response.data.data) : undefined,
    },
  });
  const preview = useQuotationsPreview(id ?? 0, {
    query: {
      enabled: id !== null && review,
      select: (response) =>
        response.status === 200 ? readPreview(response.data.data) : undefined,
    },
  });
  const liveAccess = quotationAccess(
    new Set(
      (user?.permissions ?? []).filter(
        (permission): permission is string => typeof permission === "string",
      ),
    ),
  );
  const family = (review ? preview.data : detail.data)?.family_id ?? null;
  const familyAccess = useFamilyAccessData(
    family,
    {},
    undefined,
    liveAccess.detail,
  );
  const freshScope = familyAccess.isSuccess && !familyAccess.isFetching;
  const recordMayReview = liveAccess.detail
    ? freshScope && familyAccess.data.allowed_actions.prepare
    : mayReview;
  const recordMaySpecial =
    freshScope && familyAccess.data.record_scope !== "DELEGATED";
  const query = review ? preview : detail;
  const title = `Quotation${id ? ` #${id}` : ""}${review ? " review" : ""}`;
  const actions = (
    <div className="flex flex-wrap gap-3">
      {id && mayDetail && review && (
        <Link
          className="ui-link-action inline-flex min-h-11 items-center"
          to={`/quotations/${id}${search}`}
        >
          Quotation details
        </Link>
      )}
      {id && recordMayReview && !review && (
        <Link
          className="ui-link-action inline-flex min-h-11 items-center"
          to={`/quotations/${id}/review${search}`}
        >
          Review quotation
        </Link>
      )}
      {id && recordMaySpecial && milestone.readiness && (
        <Link
          className="ui-link-action inline-flex min-h-11 items-center"
          to={`/quotations/${id}/issue`}
        >
          Issue readiness
        </Link>
      )}
      {id && recordMaySpecial && !milestone.readiness && milestone.issue && (
        // Issuing needs the readiness check, which needs the prepare duty: say so instead of offering a dead end.
        <span
          className="inline-flex min-h-11 items-center text-sm text-muted-foreground"
          role="note"
        >
          Issuing needs the duty to prepare quotations as well.
        </span>
      )}
      {id && recordMaySpecial && approvalAccess.read && (
        <Link
          className="ui-link-action inline-flex min-h-11 items-center"
          to={`/quotations/${id}/approval`}
        >
          Discount approval
        </Link>
      )}
      {id && recordMaySpecial && milestone.documents && (
        <Link
          className="ui-link-action inline-flex min-h-11 items-center"
          to={`/quotations/${id}/document`}
        >
          Official PDF
        </Link>
      )}
      {presentation === "page" && (
        <Link
          className="ui-link-action inline-flex min-h-11 items-center"
          to="/"
        >
          Back to work area
        </Link>
      )}
    </div>
  );
  const content = (
    <>
      {id === null ? (
        <QuotationFailure error={Object.assign(new Error(), { status: 404 })} />
      ) : query.isError ? (
        <QuotationFailure
          error={query.error}
          retry={() => {
            void query.refetch();
          }}
        />
      ) : query.isPending ? (
        <div role="status">
          <Skeleton className="h-48 w-full" />
          <span className="sr-only">Loading quotation…</span>
        </div>
      ) : liveAccess.detail && (!freshScope || (review && !recordMayReview)) ? (
        familyAccess.isPending || familyAccess.isFetching ? (
          <div role="status">Loading current quotation access…</div>
        ) : (
          <QuotationFailure
            error={Object.assign(new Error(), { status: 404 })}
          />
        )
      ) : review && preview.data ? (
        <QuotationReview preview={preview.data} />
      ) : detail.data && !review ? (
        <>
          <QuotationMetadata draft={detail.data} />
        </>
      ) : (
        <QuotationFailure
          error={
            new Error("The server returned an unsupported quotation response.")
          }
          retry={() => {
            void query.refetch();
          }}
        />
      )}
      {family !== null && liveAccess.detail && (
        <div hidden={review || !freshScope}>
          <QuotationFamilyAccessPanel familyId={family} />
        </div>
      )}
    </>
  );
  if (presentation === "sheet") {
    return (
      <RecordSheet
        title={title}
        description="Draft quotation workspace"
        size="xl"
        actions={actions}
        onClose={() => navigate(`/quotations${search}`)}
      >
        <div
          role="region"
          aria-label="Quotation content"
          tabIndex={0}
          className="space-y-4 pb-6"
        >
          {content}
        </div>
      </RecordSheet>
    );
  }
  return (
    <ScreenTemplate kind="detail" className="space-y-4 p-4 md:p-6">
      <PageHeader
        title={title}
        description="Draft quotation workspace"
        actions={actions}
      />
      {content}
    </ScreenTemplate>
  );
}
