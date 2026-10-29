import { Link } from "react-router";
import { ScreenTemplate } from "../../components/page/screen-template";
import { PageHeader } from "../../components/page/page-header";
import { DetailSection } from "../../components/detail/detail-page-layout";
import { Button } from "../../components/ui/button";
import { usePermission } from "../../hooks/use-permission";
import { AccessDenied } from "../../routes/permission-route";
import type { IssueReadiness } from "./quotation-issue-contract";
import { issuePreviewAccess } from "./quotation-issue-access";

/** Preview only; no route, generated client, production data or issue mutation exists. */
const descriptions: Record<string, string> = {
  RECIPIENT_REQUIRED: "Choose a recipient.",
  RECIPIENT_NOT_ACTIVE: "Choose an active recipient.",
  PRICING_DATE_REQUIRED: "Set a pricing date.",
  VALID_THROUGH_REQUIRED: "Set a valid-through date.",
  VALID_THROUGH_PAST: "The valid-through date has passed.",
  NO_LINES: "Add at least one quotation line.",
  TAX_NOT_DETERMINED: "Tax has not been determined.",
  TAX_NET_UNKNOWN: "The line net is not available for tax.",
  TAX_UNKNOWN_FOR_LINE: "Tax is not determined for this line.",
  CONTROLS_NOT_CONFIGURED: "Financial controls are not configured.",
  CONTROL_UNEVALUABLE: "A required financial control could not be evaluated.",
  PRICE_NOT_ENTERED: "Enter a unit price.",
  FX_EVIDENCE_CHANGED: "The exchange-rate evidence has changed.",
  ROUNDING_POLICY_CHANGED: "The rounding policy has changed.",
  UNIT_PRICE_PRECISION_EXCEEDED:
    "A unit price exceeds the current precision policy.",
};
const scopeLabels: Record<string, string> = {
  HEADER: "Quotation",
  LINE: "Line",
  TAX: "Tax",
  CONTROLS: "Financial controls",
  AUTHORITY: "Authority",
};
type Props = { assessment: IssueReadiness };
export function QuotationReadinessScreen(props: Props) {
  const permitted = usePermission("sales.quotation.prepare");
  return issuePreviewAccess({ prepare: permitted, issue: false, view: false })
    .readiness ? (
    <QuotationReadinessPresentation {...props} />
  ) : (
    <AccessDenied />
  );
}
export function QuotationApprovalScreen(props: Props) {
  const permitted = usePermission("sales.quotation.issue");
  const mayView = usePermission("sales.quotation.view");
  return issuePreviewAccess({ prepare: false, issue: permitted, view: mayView })
    .approval ? (
    <QuotationApprovalPresentation {...props} />
  ) : (
    <AccessDenied />
  );
}

/** Fixture-only rendering, not permission to issue. Unknown blockers remain blocking. */
export function QuotationReadinessPresentation({ assessment }: Props) {
  return (
    <ScreenTemplate kind="detail" className="erp-screen space-y-4">
      <PageHeader
        title={`Quotation #${assessment.revision_id} readiness`}
        description="Checks reflect the saved quotation at the time they were assessed."
        actions={
          <Link
            className="ui-link-action"
            to={`/quotations/${assessment.revision_id}/edit`}
          >
            Return to draft
          </Link>
        }
      />
      <DetailSection
        title={
          assessment.issue_ready
            ? "Checks passed"
            : "Changes needed before issue"
        }
        description="Issuing requires a separate authorised action and fresh checks."
      >
        <div className="col-span-full space-y-3">
          {assessment.issue_ready ? (
            <p>No blockers were reported for this assessment.</p>
          ) : (
            <ul className="space-y-3">
              {assessment.blockers.map((b, index) => (
                <li
                  key={`${b.scope}-${b.line_id}-${b.code}-${index}`}
                  className="rounded-md border p-3"
                >
                  <p className="font-medium">
                    {scopeLabels[b.scope]}
                    {b.line_id !== null
                      ? ` · line reference #${b.line_id}`
                      : ""}
                  </p>
                  <p className="text-sm">
                    {descriptions[b.code] ??
                      "This check needs attention before the quotation can be issued."}
                  </p>
                </li>
              ))}
            </ul>
          )}
          <p className="text-sm text-muted-foreground">
            Results may change if the draft or its evidence changes.
          </p>
        </div>
      </DetailSection>
    </ScreenTemplate>
  );
}
/** No approval or issue API is available; the control cannot send a request. */
export function QuotationApprovalPresentation({ assessment }: Props) {
  return (
    <ScreenTemplate kind="detail" className="erp-screen space-y-4">
      <PageHeader
        title={`Quotation #${assessment.revision_id} approval`}
        description="Review the saved quotation before issuing."
        actions={
          <Link
            className="ui-link-action"
            to={`/quotations/${assessment.revision_id}/review`}
          >
            Review saved quotation
          </Link>
        }
      />
      <DetailSection title="Issue approval">
        <div className="col-span-full space-y-3">
          <p>
            {assessment.issue_ready
              ? "The recorded checks have no blockers; issue must repeat them with current evidence."
              : "The recorded checks have blockers. Resolve them before issue."}
          </p>
          <p>Approval and issue are not available in this preview.</p>
          <p className="text-sm text-muted-foreground">
            Issue requires both quotation view and issue authority and rechecks
            the saved generation.
          </p>
          <Button type="button" disabled>
            Issue quotation
          </Button>
        </div>
      </DetailSection>
    </ScreenTemplate>
  );
}
