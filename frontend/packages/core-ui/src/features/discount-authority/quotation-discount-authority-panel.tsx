import { useQuery } from "@tanstack/react-query";
import { Link } from "react-router";
import { DetailField } from "../../components/detail/detail-field";
import { DetailSection } from "../../components/detail/detail-page-layout";
import { Button } from "../../components/ui/button";
import { Skeleton } from "../../components/ui/skeleton";
import { StatusBadge } from "../../components/ui/status-badge";
import { isApiError, shouldRetryQuery } from "../../lib/api-errors";
import { useAuthStore } from "../../stores/auth-store";
import { discountAuthorityAccess } from "./discount-authority-access";
import { liveQuotationAuthorityGateway } from "./discount-authority-gateway";
import {
  MODE_VIEW,
  authorityState,
  isDemoOnly,
  reasonText,
  type AuthorityEvaluation,
  type DiscountBand,
  type QuotationAuthority,
  type QuotationAuthorityGateway,
} from "./discount-authority-contract";

/** The limits of the authority level an approver needs, listed only where one is set. */
function bandLimits(
  band: NonNullable<AuthorityEvaluation["required_band"]> | DiscountBand,
): string[] {
  return [
    band.line_percent && `${band.line_percent}% on a line`,
    band.line_base_amount && `${band.line_base_amount} on a line`,
    band.document_percent && `${band.document_percent}% on the document`,
    band.document_base_amount && `${band.document_base_amount} on the document`,
    band.input_percent && `${band.input_percent}% of input`,
    band.input_base_amount && `${band.input_base_amount} of input`,
  ].filter((text): text is string => Boolean(text));
}
export { bandLimits };

/** Presentational: the plain state, and the figures only because the caller already passed the evidence duty. */
export function AuthorityResultView({
  authority,
  historyTo,
}: {
  authority: QuotationAuthority;
  /** Where the recorded history of this quotation is, when this person may open it. */
  historyTo?: string;
}) {
  const state = authorityState(authority);
  const mode = MODE_VIEW[authority.mode];
  const current = authority.current;
  const showFigures =
    current !== null &&
    (state.key === "WITHIN_AUTHORITY" ||
      state.key === "NEEDS_APPROVAL" ||
      state.key === "CANNOT_EVALUATE") &&
    current.reference_amount !== null;
  const reasons = current?.reasons ?? [];
  const band = current?.required_band ?? null;
  const currency = current?.currency_id
    ? ` · Currency reference #${current.currency_id}`
    : "";
  return (
    <DetailSection
      title="Discount authority"
      description="Whether the discount on this quotation is within the limits of the person who entered it. Shown only to people who may review this evidence."
      fullWidth
    >
      <DetailField label="Result">
        <StatusBadge tone={state.tone}>{state.label}</StatusBadge>
      </DetailField>
      <DetailField label="Company setting">
        <StatusBadge tone={mode.tone}>{mode.label}</StatusBadge>
      </DetailField>
      <p className="col-span-full text-sm" role="note">
        {state.note}
      </p>
      {authority.mode === "SHADOW" && (
        <p className="col-span-full text-sm" role="note">
          This company only records the result for now; it does not stop the
          quotation.
        </p>
      )}
      {showFigures && current && (
        <>
          <DetailField label="Trusted reference amount">
            <span className="tabular-nums">
              {current.reference_amount}
              {currency}
            </span>
          </DetailField>
          <DetailField label="Final net amount">
            <span className="tabular-nums">
              {current.final_net_amount}
              {currency}
            </span>
          </DetailField>
          <DetailField label="Reduction">
            <span className="tabular-nums">
              {current.reduction_amount}
              {currency}
            </span>
          </DetailField>
          {current.document_percent_exact !== null && (
            <DetailField label="Document discount">
              <span className="tabular-nums">
                {current.document_percent_exact}%
              </span>
            </DetailField>
          )}
        </>
      )}
      {state.key === "NEEDS_APPROVAL" && (
        <div className="col-span-full space-y-1 text-sm">
          <p className="font-semibold">Approver needed</p>
          {band ? (
            <p className="break-words">
              Someone assigned to “{band.label}”
              {isDemoOnly(band.label) && " (demonstration data)"}
              {bandLimits(band).length > 0 &&
                `, who may approve up to ${bandLimits(band).join(", ")}`}
              {band.exception_only &&
                ", an independent exception level with no routine limit"}
              {band.allow_higher_approver
                ? ". A higher level may also approve."
                : "."}
            </p>
          ) : (
            <p>
              No authority level is configured that could approve this, so it
              cannot be cleared yet. Ask an administrator.
            </p>
          )}
        </div>
      )}
      {historyTo && (
        <div className="col-span-full">
          <Link className="ui-link-action inline-flex min-h-11 items-center" to={historyTo}>
            Recorded results for this quotation
          </Link>
        </div>
      )}
      {reasons.length > 0 && state.key !== "INACTIVE" && (
        <div className="col-span-full">
          <p className="text-sm font-semibold">Why</p>
          <ul className="list-inside list-disc space-y-1 text-sm">
            {reasons.map((code) => (
              <li key={code} className="break-words">
                {reasonText(code)}
              </li>
            ))}
          </ul>
        </div>
      )}
    </DetailSection>
  );
}

/**
 * The discount authority section on the quotation issue and approval screens. Renders nothing at all unless the person holds
 * the evidence duty with quotation view (and the quotation UI gate is open), so it can never leak an amount or a limit.
 */
export function QuotationDiscountAuthorityPanel({
  revisionId,
  gateway = liveQuotationAuthorityGateway,
}: {
  revisionId: number;
  gateway?: QuotationAuthorityGateway;
}) {
  const user = useAuthStore((s) => s.user);
  const permissions = new Set(
    (user?.permissions ?? []).filter((p): p is string => typeof p === "string"),
  );
  if (!discountAuthorityAccess(permissions).evidence) return null;
  return (
    <PanelQuery
      key={`${user?.id}:${revisionId}`}
      revisionId={revisionId}
      gateway={gateway}
      actorId={user?.id}
    />
  );
}

function PanelQuery({
  revisionId,
  gateway,
  actorId,
}: {
  revisionId: number;
  gateway: QuotationAuthorityGateway;
  actorId: number | string | undefined;
}) {
  const query = useQuery({
    queryKey: ["quotation-discount-authority", actorId ?? "fixture", revisionId],
    queryFn: () => gateway.read(revisionId),
    retry: shouldRetryQuery,
  });
  if (query.isPending)
    return (
      <div role="status">
        <Skeleton className="h-32 w-full" />
        <span className="sr-only">Loading discount authority…</span>
      </div>
    );
  if (query.isError) {
    const status = isApiError(query.error) ? query.error.status : 0;
    return (
      <DetailSection title="Discount authority" fullWidth>
        <div className="col-span-full space-y-2">
          <p role="alert" className="text-sm">
            {status === 403 || status === 404
              ? "The discount authority result is not available to you for this quotation."
              : status === 422
                ? "The evidence this check needs has not been captured for this quotation yet."
                : "The discount authority result could not be loaded."}
          </p>
          {status !== 403 && status !== 404 && (
            <Button
              className="min-h-11"
              type="button"
              variant="outline"
              onClick={() => void query.refetch()}
            >
              Try again
            </Button>
          )}
        </div>
      </DetailSection>
    );
  }
  return (
    <AuthorityResultView
      authority={query.data}
      historyTo={`/quotations/${revisionId}/discount-authority-history`}
    />
  );
}
