import { useState, type ReactNode } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link, useLocation } from "react-router";
import { AccessDenied } from "../../routes/permission-route";
import { DetailField } from "../../components/detail/detail-field";
import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
import { PageHeader } from "../../components/page/page-header";
import { ScreenTemplate } from "../../components/page/screen-template";
import { Button } from "../../components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "../../components/ui/select";
import { Skeleton } from "../../components/ui/skeleton";
import { StatusBadge } from "../../components/ui/status-badge";
import {
  describeLoadError,
  isApiError,
  shouldRetryQuery,
} from "../../lib/api-errors";
import { formatDateTime } from "../../lib/format-date";
import { useAuthStore } from "../../stores/auth-store";
import { customerDecisionAccess } from "../customer-decisions/customer-decision-access";
import { customerAcceptanceAccess } from "./customer-acceptance-access";
import {
  READINESS_PATHS,
  READINESS_PATH_LABELS,
  missingText,
  type Acceptance,
  type AcceptanceReadiness,
  type CustomerAcceptanceGateway,
  type ReadinessPath,
} from "./customer-acceptance-contract";
import { liveCustomerAcceptanceGateway } from "./customer-acceptance-gateway";
import { AcceptanceTermsList } from "./customer-acceptance-review";

/** The readiness panel. It is shown exactly as assessed: not ready is not an error, and it never weakens the acceptance above it. */
export function AcceptanceReadinessPanel({
  readiness,
  path,
  onPath,
  isFetching,
  error,
  onRetry,
}: {
  readiness: AcceptanceReadiness | undefined;
  path: ReadinessPath;
  onPath: (path: ReadinessPath) => void;
  isFetching: boolean;
  error: unknown;
  onRetry: () => void;
}) {
  const ready = readiness?.status === "READY";
  return (
    <DetailSection
      title="Readiness for handover"
      description="Assessed separately. An accepted scope stays accepted while this is outstanding."
      fullWidth
    >
      <div className="space-y-2">
        <label className="text-sm font-medium" htmlFor="readiness-path">
          Handover path
        </label>
        <Select value={path} onValueChange={(v) => onPath(v as ReadinessPath)}>
          <SelectTrigger id="readiness-path" className="min-h-11">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {READINESS_PATHS.map((p) => (
              <SelectItem key={p} value={p}>
                {READINESS_PATH_LABELS[p]}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      {error ? (
        <div role="alert" className="col-span-full space-y-2 text-sm">
          <p>{describeLoadError(error)}</p>
          <Button
            className="min-h-11"
            type="button"
            variant="outline"
            onClick={onRetry}
          >
            Try again
          </Button>
        </div>
      ) : !readiness ? (
        <div role="status" className="col-span-full">
          <Skeleton className="h-16 w-full" />
          <span className="sr-only">Assessing readiness…</span>
        </div>
      ) : (
        <>
          <DetailField label="Result">
            <StatusBadge tone={ready ? "success" : "pending"}>
              {ready ? "Ready" : "Not ready"}
            </StatusBadge>
            {isFetching && (
              <span className="ml-2 text-xs text-muted-foreground">
                Updating…
              </span>
            )}
          </DetailField>
          <DetailField label="Assessment">
            {readiness.assessment_version}
          </DetailField>
          {readiness.missing.length > 0 && (
            <ul
              className="col-span-full list-inside list-disc space-y-1 text-sm"
              aria-label="What is outstanding"
            >
              {readiness.missing.map((code) => (
                <li key={code}>{missingText(code)}</li>
              ))}
            </ul>
          )}
          <p className="col-span-full text-sm" role="note">
            This assessment does not check the site, contacts, warehouse, cost
            or financial clearance. Those checks arrive with the handover steps,
            which are not delivered yet.
          </p>
        </>
      )}
    </DetailSection>
  );
}

/** The read-only acceptance: immutable terms, who recorded it and when, its evidence and claims, then readiness. Presentational. */
export function CustomerAcceptanceView({
  acceptance,
  mayOpenMessage,
  banner,
  readiness,
}: {
  acceptance: Acceptance;
  /** The case link is offered only to people who may read customer messages. */
  mayOpenMessage: boolean;
  banner?: string | null;
  readiness: ReactNode;
}) {
  const s = acceptance.scope;
  return (
    <ScreenTemplate kind="detail" className="space-y-4 p-4 md:p-6">
      <PageHeader
        title={`Customer acceptance ${acceptance.id}`}
        description="The customer’s consent to one full scope, on the exact terms issued. It is not an order."
        backTo={`/quotations/${acceptance.revision_id}/issue`}
      />
      {banner && (
        <p
          role="status"
          className="rounded-md border border-border bg-muted/40 p-3 text-sm"
          data-testid="acceptance-banner"
        >
          {banner}
        </p>
      )}
      <DetailPageLayout>
        <DetailSection
          title="Agreed scope"
          description="Fixed when the acceptance was recorded. It cannot be edited."
          fullWidth
        >
          <div className="col-span-full">
            <AcceptanceTermsList terms={s} />
          </div>
          <DetailField label="Terms">
            <StatusBadge tone="success">
              {acceptance.unchanged_terms ? "Accepted unchanged" : "Changed"}
            </StatusBadge>
          </DetailField>
          <DetailField label="Scope rule">
            {acceptance.acceptance_policy === "FULL_ONLY"
              ? "The full scope only: no part of it is accepted."
              : acceptance.acceptance_policy}
          </DetailField>
        </DetailSection>
        <DetailSection title="Recorded">
          <DetailField label="Recorded at">
            {formatDateTime(acceptance.recorded_at)}
          </DetailField>
          <DetailField label="Recorded by">
            Person reference #{acceptance.recorded_by}
          </DetailField>
          <DetailField label="Quotation">
            <Link
              className="ui-link-action inline-flex min-h-11 items-center"
              to={`/quotations/${acceptance.revision_id}/issue`}
            >
              Open the issued quotation
            </Link>
          </DetailField>
          <DetailField label="Issued line">
            {`Scope ${s.logical_scope_id}, line ${s.issued_line_id}`}
          </DetailField>
        </DetailSection>
        <DetailSection
          title="Evidence"
          description="The written customer message this consent rests on."
        >
          <DetailField label="Consent observation">
            {`Observation #${acceptance.consent_evidence_id}`}
          </DetailField>
          <DetailField label="Customer message">
            {mayOpenMessage ? (
              <Link
                className="ui-link-action inline-flex min-h-11 items-center"
                to={`/customer-decisions/${acceptance.case_id}`}
              >
                Open the customer message
              </Link>
            ) : (
              "Shown to people who may see customer messages."
            )}
          </DetailField>
          <DetailField label="Evidence rule">
            {`Version ${acceptance.policy_version_id}: written, from a named sender whose authority was checked.`}
          </DetailField>
        </DetailSection>
        <DetailSection
          title="Claims"
          description="What this acceptance uses up. A claim is never partly released."
          fullWidth
        >
          <p className="col-span-full text-sm">
            {`The whole of scope ${s.logical_scope_id} is claimed by this acceptance. It cannot be accepted again, by anyone.`}
          </p>
          {s.choice_group_id !== null && (
            <p className="col-span-full text-sm">
              {`It is one of a set of alternatives (set ${s.choice_group_id}): the other alternatives in the set can no longer be accepted.`}
            </p>
          )}
        </DetailSection>
        {readiness}
      </DetailPageLayout>
    </ScreenTemplate>
  );
}

function Failure({ error, retry }: { error: unknown; retry: () => void }) {
  const missing = isApiError(error) && error.status === 404;
  return (
    <div
      role="alert"
      className="m-4 space-y-3 rounded-lg border border-border bg-card p-4 md:m-6"
    >
      <h2 className="font-semibold">
        {missing
          ? "Customer acceptance unavailable"
          : "Could not load this acceptance"}
      </h2>
      <p className="text-sm">
        {missing
          ? "This customer acceptance is not available."
          : describeLoadError(error)}
      </p>
      {!missing && (
        <Button variant="outline" onClick={retry}>
          Try again
        </Button>
      )}
    </div>
  );
}

/** The gated acceptance screen. Denied people (and every production build) issue no request. */
export function CustomerAcceptanceScreen({
  acceptanceId,
  gateway = liveCustomerAcceptanceGateway,
}: {
  acceptanceId: number;
  gateway?: CustomerAcceptanceGateway;
}) {
  const user = useAuthStore((s) => s.user);
  const permissions = new Set(
    (user?.permissions ?? []).filter((p): p is string => typeof p === "string"),
  );
  if (!customerAcceptanceAccess(permissions).read) return <AccessDenied />;
  return (
    <GatedAcceptance
      acceptanceId={acceptanceId}
      gateway={gateway}
      mayOpenMessage={customerDecisionAccess(permissions).view}
    />
  );
}

function GatedAcceptance({
  acceptanceId,
  gateway,
  mayOpenMessage,
}: {
  acceptanceId: number;
  gateway: CustomerAcceptanceGateway;
  mayOpenMessage: boolean;
}) {
  const state = useLocation().state as {
    recorded?: boolean;
    replayed?: boolean;
  } | null;
  const [path, setPath] = useState<ReadinessPath>("SERVICE_JOB");
  const acceptance = useQuery({
    queryKey: ["customer-acceptance", acceptanceId],
    queryFn: () => gateway.show(acceptanceId),
    retry: shouldRetryQuery,
  });
  const readiness = useQuery({
    queryKey: ["customer-acceptance-readiness", acceptanceId, path],
    queryFn: () => gateway.readiness(acceptanceId, path),
    enabled: acceptance.isSuccess,
    retry: shouldRetryQuery,
    placeholderData: (previous) => previous,
  });
  if (acceptance.isError)
    return (
      <Failure
        error={acceptance.error}
        retry={() => void acceptance.refetch()}
      />
    );
  if (acceptance.isPending)
    return (
      <div role="status" className="p-4 md:p-6">
        <Skeleton className="h-48 w-full" />
        <span className="sr-only">Loading customer acceptance…</span>
      </div>
    );
  return (
    <CustomerAcceptanceView
      acceptance={acceptance.data}
      mayOpenMessage={mayOpenMessage}
      banner={
        state?.replayed
          ? "This acceptance had already been recorded by this request; nothing was recorded twice."
          : state?.recorded
            ? "Acceptance recorded. Nothing else has happened: no order, job, reservation or invoice was created."
            : null
      }
      readiness={
        <AcceptanceReadinessPanel
          readiness={readiness.data}
          path={path}
          onPath={setPath}
          isFetching={readiness.isFetching}
          error={readiness.isError ? readiness.error : null}
          onRetry={() => void readiness.refetch()}
        />
      }
    />
  );
}
