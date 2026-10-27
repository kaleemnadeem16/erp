import { useParams, Link, useLocation } from "react-router";
import { useQuotationsShow, useQuotationsPreview } from "@erp/core-api";
import { useAuthStore } from "../../stores/auth-store";
import { AccessDenied } from "../../routes/permission-route";
import { ScreenTemplate } from "../../components/page/screen-template";
import { PageHeader } from "../../components/page/page-header";
import { Skeleton } from "../../components/ui/skeleton";
import { quotationAccess } from "./quotation-access";
import { readDraft, readPreview, revisionId } from "./quotation-contract";
import {
  QuotationFailure,
  QuotationMetadata,
  QuotationReview,
} from "./quotation-display";

export function QuotationDetailScreen({
  review = false,
}: {
  review?: boolean;
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
    />
  );
}

function QuotationRecord({
  review,
  mayDetail,
  mayReview,
}: {
  review: boolean;
  mayDetail: boolean;
  mayReview: boolean;
}) {
  const id = revisionId(useParams<{ revision: string }>().revision);
  const { search } = useLocation();
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
  const query = review ? preview : detail;
  return (
    <ScreenTemplate kind="detail" className="space-y-4 p-4 md:p-6">
      <PageHeader
        title={`Quotation${id ? ` #${id}` : ""}${review ? " review" : ""}`}
        description="Draft quotation workspace"
        actions={
          <div className="flex flex-wrap gap-3">
            {id && mayDetail && review && (
              <Link
                className="ui-link-action inline-flex min-h-11 items-center"
                to={`/quotations/${id}${search}`}
              >
                Quotation details
              </Link>
            )}
            {id && mayReview && !review && (
              <Link
                className="ui-link-action inline-flex min-h-11 items-center"
                to={`/quotations/${id}/review${search}`}
              >
                Review quotation
              </Link>
            )}
            <Link className="ui-link-action inline-flex min-h-11 items-center" to="/">
              Back to work area
            </Link>
          </div>
        }
      />
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
        <div role="status" aria-label="Loading quotation">
          <Skeleton className="h-48 w-full" />
          <span className="sr-only">Loading quotation…</span>
        </div>
      ) : review && preview.data ? (
        <QuotationReview preview={preview.data} />
      ) : detail.data && !review ? (
        <QuotationMetadata draft={detail.data} />
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
    </ScreenTemplate>
  );
}
