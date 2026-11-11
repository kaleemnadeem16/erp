import { useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AccessDenied } from "../../routes/permission-route";
import { DetailField } from "../../components/detail/detail-field";
import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
import { PageHeader } from "../../components/page/page-header";
import { RecordSheet } from "../../components/page/record-sheet";
import { ScreenTemplate } from "../../components/page/screen-template";
import { Button } from "../../components/ui/button";
import { Skeleton } from "../../components/ui/skeleton";
import { StatusBadge } from "../../components/ui/status-badge";
import {
  describeLoadError,
  isApiError,
  shouldRetryQuery,
} from "../../lib/api-errors";
import { formatDate, formatDateTime } from "../../lib/format-date";
import { useAuthStore } from "../../stores/auth-store";
import { IntentKey } from "../quotations/request-key";
import { customerDecisionAccess } from "./customer-decision-access";
import {
  AUTHORITY_LABELS,
  PRECISION_LABELS,
  SOURCE_LABELS,
  isStale,
  type AuthorityBasis,
  type CaseDetail,
  type CustomerDecisionGateway,
  type Observation,
  type ObservationInput,
  type Precision,
  type SourceKind,
} from "./customer-decision-contract";
import { liveCustomerDecisionGateway } from "./customer-decision-gateway";
import { NoConsentNotice, ObservationForm } from "./customer-decision-forms";

function label<T extends string>(map: Record<T, string>, key: string): string {
  return (map as Record<string, string>)[key] ?? key;
}

/** When it happened, as truthfully as it is known: an unknown time says so; a day shows no time. */
export function occurrenceText(o: Observation): string {
  if (o.occurrence_precision === "UNKNOWN" || !o.occurred_at)
    return "Not known";
  if (o.occurrence_precision === "DATE")
    return formatDate(o.occurred_at) ?? "Not known";
  const from = formatDateTime(o.occurred_at) ?? "";
  return o.occurrence_precision === "INTERVAL" && o.occurred_until
    ? `${from} to ${formatDateTime(o.occurred_until) ?? ""}`
    : from;
}

/** The read-only case: header, the linked quotations, and the observation chain oldest first. Presentational, so tests and previews render the same. */
export function CustomerDecisionCaseView({
  detail,
  onAddObservation,
}: {
  detail: CaseDetail;
  /** Present only for a person who may record. */
  onAddObservation?: () => void;
}) {
  const { case: header, components, observations } = detail;
  return (
    <ScreenTemplate kind="detail" className="space-y-4 p-4 md:p-6">
      <PageHeader
        title={`Customer message ${header.intake_identity}`}
        description={`From ${header.party_name ?? `customer reference ${header.party_id}`}. A record of what was sent, not an agreement.`}
        backTo="/customer-decisions"
        actions={
          onAddObservation ? (
            <Button
              className="min-h-11"
              type="button"
              onClick={onAddObservation}
            >
              Add an observation
            </Button>
          ) : undefined
        }
      />
      <NoConsentNotice />
      <DetailPageLayout>
        <DetailSection title="Case">
          <DetailField label="Customer’s reference">
            {header.intake_identity}
          </DetailField>
          <DetailField label="Customer">
            {header.party_name ?? `Customer reference ${header.party_id}`}
          </DetailField>
          <DetailField label="Opened">
            {formatDateTime(header.recorded_at)}
            {header.recorded_by_name ? ` by ${header.recorded_by_name}` : ""}
          </DetailField>
          <DetailField label="Consent">
            <StatusBadge tone="draft">No consent recorded</StatusBadge>
          </DetailField>
        </DetailSection>
        <DetailSection
          title="Quotations this is about"
          description="Linked when the case was opened. Linking holds nothing."
        >
          {components.length === 0 && detail.hidden_component_count === 0 && (
            <p className="col-span-full text-sm text-muted-foreground">
              No quotation is linked to this case.
            </p>
          )}
          {components.map((c) => (
            <DetailField key={c.id} label={c.intent_identity}>
              Quotation family {c.family_id}, scope {c.original_scope_anchor_id}
            </DetailField>
          ))}
          {detail.hidden_component_count > 0 && (
            <p className="col-span-full text-sm text-muted-foreground">
              {detail.hidden_component_count === 1
                ? "1 more quotation is linked that you cannot see."
                : `${detail.hidden_component_count} more quotations are linked that you cannot see.`}
            </p>
          )}
        </DetailSection>
        <DetailSection
          title="What was sent"
          description="Oldest first. A later observation can correct an earlier one; the earlier one stays on record."
          fullWidth
        >
          {observations.length === 0 ? (
            <p className="col-span-full text-sm text-muted-foreground">
              Nothing has been recorded yet.
            </p>
          ) : (
            <ol className="col-span-full space-y-3" aria-label="Observations">
              {observations.map((o) => (
                <li
                  key={o.id}
                  className="space-y-2 rounded-md border border-border p-3"
                >
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-semibold">
                      {label<SourceKind>(SOURCE_LABELS, o.source_kind)}
                    </span>
                    {o.external_reference && (
                      <span className="text-sm text-muted-foreground">
                        {o.external_reference}
                      </span>
                    )}
                    {!o.is_current && (
                      <StatusBadge tone="pending">
                        Corrected by a later observation
                      </StatusBadge>
                    )}
                    {o.predecessor_id !== null && (
                      <StatusBadge tone="progress">
                        {`Corrects #${o.predecessor_id}`}
                      </StatusBadge>
                    )}
                  </div>
                  <p className="whitespace-pre-wrap break-words text-sm">
                    {o.factual_description}
                  </p>
                  <dl className="grid gap-2 text-xs sm:grid-cols-2">
                    <div>
                      <dt className="text-muted-foreground">
                        When it happened
                      </dt>
                      <dd>
                        {occurrenceText(o)}
                        {o.source_timezone ? ` (${o.source_timezone})` : ""}
                        {` · ${label<Precision>(PRECISION_LABELS, o.occurrence_precision)}`}
                      </dd>
                    </div>
                    <div>
                      <dt className="text-muted-foreground">
                        Sender’s authority
                      </dt>
                      <dd>
                        {label<AuthorityBasis>(
                          AUTHORITY_LABELS,
                          o.authority_verification_basis,
                        )}
                      </dd>
                    </div>
                    {o.customer_representative_snapshot && (
                      <div>
                        <dt className="text-muted-foreground">Sent by</dt>
                        <dd>{o.customer_representative_snapshot}</dd>
                      </div>
                    )}
                    <div>
                      <dt className="text-muted-foreground">Recorded</dt>
                      <dd>
                        {formatDateTime(o.recorded_at)}
                        {o.recorded_by_name ? ` by ${o.recorded_by_name}` : ""}
                      </dd>
                    </div>
                  </dl>
                </li>
              ))}
            </ol>
          )}
        </DetailSection>
      </DetailPageLayout>
    </ScreenTemplate>
  );
}

function CaseFailure({ error, retry }: { error: unknown; retry: () => void }) {
  const missing = isApiError(error) && error.status === 404;
  return (
    <div
      role="alert"
      className="m-4 space-y-3 rounded-lg border border-border bg-card p-4 md:m-6"
    >
      <h2 className="font-semibold">
        {missing ? "Customer message unavailable" : "Could not load this case"}
      </h2>
      <p className="text-sm">
        {missing
          ? "This customer message is not available."
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

/** The gated case screen. Denied people (and every production build) issue no request. */
export function CustomerDecisionCaseScreen({
  caseId,
  gateway = liveCustomerDecisionGateway,
}: {
  caseId: number;
  gateway?: CustomerDecisionGateway;
}) {
  const user = useAuthStore((s) => s.user);
  const permissions = new Set(
    (user?.permissions ?? []).filter((p): p is string => typeof p === "string"),
  );
  const access = customerDecisionAccess(permissions);
  if (!access.view) return <AccessDenied />;
  return (
    <GatedCase caseId={caseId} gateway={gateway} mayRecord={access.record} />
  );
}

function GatedCase({
  caseId,
  gateway,
  mayRecord,
}: {
  caseId: number;
  gateway: CustomerDecisionGateway;
  mayRecord: boolean;
}) {
  const client = useQueryClient();
  const detail = useQuery({
    queryKey: ["customer-decision-case", caseId],
    queryFn: () => gateway.detail(caseId),
    retry: shouldRetryQuery,
  });
  const [adding, setAdding] = useState(false);
  const addKey = useRef(new IntentKey());
  const add = useMutation({
    mutationFn: (body: ObservationInput) => {
      const expectedGeneration = detail.data?.case.generation ?? 0;
      return gateway.addObservation({
        caseId,
        expectedGeneration,
        body,
        requestKey: addKey.current.for({ caseId, expectedGeneration, body }),
      });
    },
    onSuccess: () => {
      addKey.current.settle();
      setAdding(false);
      void client.invalidateQueries({
        queryKey: ["customer-decision-case", caseId],
      });
      void client.invalidateQueries({ queryKey: ["customer-decision-cases"] });
    },
    onError: (e) => {
      addKey.current.settle(e);
      // A moved case is refreshed so the next attempt quotes the generation the person can now see.
      if (isStale(e))
        void client.invalidateQueries({
          queryKey: ["customer-decision-case", caseId],
        });
    },
  });
  if (detail.isError)
    return (
      <CaseFailure error={detail.error} retry={() => void detail.refetch()} />
    );
  if (detail.isPending)
    return (
      <div role="status" className="p-4 md:p-6">
        <Skeleton className="h-48 w-full" />
        <span className="sr-only">Loading customer message…</span>
      </div>
    );
  return (
    <>
      <CustomerDecisionCaseView
        detail={detail.data}
        onAddObservation={
          mayRecord
            ? () => {
                add.reset();
                setAdding(true);
              }
            : undefined
        }
      />
      {mayRecord && adding && (
        <RecordSheet
          title="Add an observation"
          description={`To ${detail.data.case.intake_identity}. Say what arrived, in plain facts.`}
          size="md"
          dismissOnOutsideClick={false}
          onClose={() => setAdding(false)}
        >
          <ObservationForm
            correctable={detail.data.observations.filter((o) => o.is_current)}
            onSubmit={(body) => add.mutate(body)}
            onCancel={() => setAdding(false)}
            pending={add.isPending}
            error={add.error}
          />
        </RecordSheet>
      )}
    </>
  );
}
