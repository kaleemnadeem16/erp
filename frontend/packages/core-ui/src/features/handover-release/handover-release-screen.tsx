import { useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router";
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
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../../components/ui/dialog";
import { Skeleton } from "../../components/ui/skeleton";
import { StatusBadge } from "../../components/ui/status-badge";
import {
  describeLoadError,
  isApiError,
  shouldRetryQuery,
} from "../../lib/api-errors";
import { formatDateTime } from "../../lib/format-date";
import { useAuthStore } from "../../stores/auth-store";
import { SOURCE_LABELS } from "../customer-decisions/customer-decision-contract";
import type { Observation } from "../customer-decisions/customer-decision-contract";
import { IntentKey } from "../quotations/request-key";
import { handoverReleaseAccess } from "./handover-release-access";
import {
  APPLICABILITY_LABELS,
  LOCATION_LABELS,
  SCOPE_LABELS,
  alreadyReleased,
  blockerText,
  evidenceProblem,
  needsFreshReview,
  refusalMessage,
  type AcceptanceFacts,
  type HandoverPlan,
  type HandoverReleaseGateway,
  type ReleaseOutcome,
  type ReleasePreview,
} from "./handover-release-contract";
import { liveHandoverReleaseGateway } from "./handover-release-gateway";

type Access = ReturnType<typeof handoverReleaseAccess>;

/* ---------------------------------------------------------------- prepared handover (read only) */

export function PreparedHandoverSection({ plan }: { plan: HandoverPlan }) {
  const o = plan.operational;
  return (
    <DetailSection
      title="Prepared handover"
      description="What was prepared for this accepted service. It cannot be edited here."
      fullWidth
    >
      <DetailField label="Service" fullWidth>
        {o.description}
      </DetailField>
      <DetailField label="Delivered">
        {LOCATION_LABELS[o.location_kind] ?? "Not recorded"}
      </DetailField>
      <DetailField label="Prepared">{formatDateTime(plan.recorded_at)}</DetailField>
      <DetailField label="Contact">
        {o.contact
          ? [o.contact.name, o.contact.email, o.contact.phone]
              .filter(Boolean)
              .join(" · ")
          : "Not recorded"}
      </DetailField>
      <DetailField label="Site">
        {o.site
          ? [o.site.name, o.site.address_line1, o.site.city]
              .filter(Boolean)
              .join(", ")
          : "Not recorded"}
      </DetailField>
      {o.instructions && (
        <DetailField label="Instructions" fullWidth>
          {o.instructions}
        </DetailField>
      )}
    </DetailSection>
  );
}

/* ---------------------------------------------------------------- completion conditions */

export function CompletionConditionsSection({
  facts,
  preview,
}: {
  facts: AcceptanceFacts;
  preview: ReleasePreview | undefined;
}) {
  return (
    <DetailSection
      title="Customer-agreed completion conditions"
      description="What the customer agreed the service must achieve. Each recording is a new immutable version."
      fullWidth
    >
      <DetailField label="Issued service description" fullWidth>
        <span data-testid="issued-description">{facts.issuedDescription}</span>
      </DetailField>
      {preview === undefined ? null : preview.basis_id === null ? (
        <p className="col-span-full text-sm" role="status" data-testid="no-basis">
          No completion conditions are recorded yet. A service cannot be
          released until they are.
        </p>
      ) : (
        <>
          <DetailField label="Recorded conditions">
            <StatusBadge tone="success">{`Version ${preview.basis_version}`}</StatusBadge>
          </DetailField>
          <DetailField label="Materials">
            {SCOPE_LABELS[preview.applicability.materials] ?? "Not stated"}
          </DetailField>
          <DetailField label="Subcontractors">
            {SCOPE_LABELS[preview.applicability.subcontract] ?? "Not stated"}
          </DetailField>
          <DetailField label="Source check">
            <StatusBadge
              tone={
                preview.applicability.status === "NOT_APPLICABLE"
                  ? "success"
                  : "pending"
              }
            >
              {APPLICABILITY_LABELS[preview.applicability.status] ??
                "Not proven"}
            </StatusBadge>
          </DetailField>
        </>
      )}
      <p className="col-span-full text-sm text-muted-foreground" role="note">
        Whether materials or subcontractors are included can only come from the
        customer’s own written, authority-checked message. It cannot be stated
        or ticked here, and missing evidence is never taken as “none”.
      </p>
    </DetailSection>
  );
}

/* ---------------------------------------------------------------- record a basis */

type BasisMode = "issued" | "message";

export function RecordBasisSection({
  gateway,
  acceptanceId,
  facts,
  onRecorded,
}: {
  gateway: HandoverReleaseGateway;
  acceptanceId: number;
  facts: AcceptanceFacts;
  onRecorded: () => void;
}) {
  const [mode, setMode] = useState<BasisMode>("issued");
  const [chosen, setChosen] = useState<number | null>(null);
  const keys = useRef(new IntentKey());
  const evidence = useQuery({
    queryKey: ["handover-release-evidence", facts.caseId],
    queryFn: () => gateway.evidence(facts.caseId),
    retry: shouldRetryQuery,
  });
  const messages: Observation[] = (evidence.data ?? []).filter(
    (o) => o.id !== facts.consentEvidenceId,
  );
  const picked = messages.find((o) => o.id === chosen);
  const record = useMutation({
    mutationFn: () => {
      const input =
        mode === "issued"
          ? { agreedText: null, evidenceId: facts.consentEvidenceId }
          : { agreedText: picked!.factual_description, evidenceId: picked!.id };
      return gateway.recordBasis({
        acceptanceId,
        ...input,
        requestKey: keys.current.for(input),
      });
    },
    onSuccess: () => {
      keys.current.settle();
      onRecorded();
    },
    onError: (error) => keys.current.settle(error),
  });
  const canSubmit = mode === "issued" || (picked && !evidenceProblem(picked));
  return (
    <DetailSection
      title="Record completion conditions"
      description="Cite genuine customer evidence that already exists. Nothing you type here becomes the agreement."
      fullWidth
    >
      <fieldset className="col-span-full space-y-3">
        <legend className="text-sm font-medium">Where do the conditions come from?</legend>
        <label className="flex min-h-11 items-start gap-2 text-sm">
          <input
            type="radio"
            name="basis-mode"
            className="mt-1"
            checked={mode === "issued"}
            onChange={() => setMode("issued")}
          />
          <span>
            <span className="font-medium">The issued service description, as accepted.</span>
            <span className="block text-muted-foreground">
              Uses the customer’s own acceptance message. It states no
              materials or subcontractor scope, so those stay unproven.
            </span>
          </span>
        </label>
        <label className="flex min-h-11 items-start gap-2 text-sm">
          <input
            type="radio"
            name="basis-mode"
            className="mt-1"
            checked={mode === "message"}
            onChange={() => setMode("message")}
          />
          <span>
            <span className="font-medium">A later written customer message.</span>
            <span className="block text-muted-foreground">
              For new or extra conditions, or when the customer stated what
              materials and subcontractors are included or excluded.
            </span>
          </span>
        </label>
      </fieldset>
      {mode === "message" &&
        (evidence.isError ? (
          <div role="alert" className="col-span-full space-y-2 text-sm">
            <p>{describeLoadError(evidence.error)}</p>
            <Button type="button" variant="outline" className="min-h-11" onClick={() => void evidence.refetch()}>
              Try again
            </Button>
          </div>
        ) : evidence.isPending ? (
          <div role="status" className="col-span-full">
            <Skeleton className="h-16 w-full" />
            <span className="sr-only">Loading customer messages…</span>
          </div>
        ) : messages.length === 0 ? (
          <p className="col-span-full text-sm" role="status" data-testid="no-messages">
            This customer has no other written message on this case. Ask the
            customer to confirm the conditions in writing, then record that
            message as customer evidence first.
          </p>
        ) : (
          <fieldset className="col-span-full space-y-2">
            <legend className="text-sm font-medium">Choose the customer message</legend>
            {messages.map((o) => {
              const problem = evidenceProblem(o);
              return (
                <label
                  key={o.id}
                  className="flex min-h-11 items-start gap-2 rounded-md border border-border p-3 text-sm"
                >
                  <input
                    type="radio"
                    name="basis-message"
                    className="mt-1"
                    disabled={problem !== null}
                    checked={chosen === o.id}
                    onChange={() => setChosen(o.id)}
                  />
                  <span className="min-w-0">
                    <span className="font-medium">
                      {`${SOURCE_LABELS[o.source_kind as keyof typeof SOURCE_LABELS] ?? o.source_kind} · ${formatDateTime(o.recorded_at)}`}
                      {o.customer_representative_snapshot
                        ? ` · ${o.customer_representative_snapshot}`
                        : ""}
                    </span>
                    <span className="block whitespace-pre-wrap break-words">
                      {o.factual_description}
                    </span>
                    {problem && (
                      <span className="block text-muted-foreground">{problem}</span>
                    )}
                  </span>
                </label>
              );
            })}
            <p className="text-sm text-muted-foreground" role="note">
              The message text is recorded exactly as written. The system
              rejects it if it was recorded before the acceptance, does not
              verify the sender’s authority, or states a scope in any form
              other than the exact four-line declaration.
            </p>
          </fieldset>
        ))}
      {record.isError && (
        <p role="alert" className="col-span-full text-sm" data-testid="basis-error">
          {refusalMessage(record.error)}
        </p>
      )}
      {record.isSuccess && (
        <p role="status" className="col-span-full text-sm" data-testid="basis-recorded">
          {record.data.replayed
            ? `These conditions were already recorded (version ${record.data.version}); nothing was recorded twice.`
            : `Conditions recorded as version ${record.data.version}. Review the release again below.`}
        </p>
      )}
      <div className="col-span-full">
        <Button
          type="button"
          className="min-h-11"
          disabled={!canSubmit || record.isPending}
          onClick={() => record.mutate()}
        >
          {record.isPending ? "Recording…" : "Record these conditions"}
        </Button>
      </div>
    </DetailSection>
  );
}

/* ---------------------------------------------------------------- release readiness and release */

export function ReleaseSection({
  gateway,
  stageId,
  access,
  preview,
  previewError,
  isFetching,
  onRetry,
  onReleased,
}: {
  gateway: HandoverReleaseGateway;
  stageId: number;
  access: Access;
  preview: ReleasePreview | undefined;
  previewError: unknown;
  isFetching: boolean;
  onRetry: () => void;
  onReleased: () => void;
}) {
  const [confirming, setConfirming] = useState(false);
  const [result, setResult] = useState<ReleaseOutcome | null>(null);
  const keys = useRef(new IntentKey());
  const release = useMutation({
    mutationFn: (p: ReleasePreview) => {
      const input = { stageId, basisId: p.basis_id!, fingerprint: p.fingerprint };
      return gateway.release({ ...input, requestKey: keys.current.for(input) });
    },
    onSuccess: (outcome) => {
      keys.current.settle();
      setConfirming(false);
      setResult(outcome);
      onReleased();
    },
    onError: (error) => {
      keys.current.settle(error);
      setConfirming(false);
      if (needsFreshReview(error)) onRetry();
    },
  });

  if (result)
    return (
      <DetailSection title="Release" fullWidth>
        <div className="col-span-full space-y-2 text-sm" role="status" data-testid="release-result">
          <p>
            <StatusBadge tone="success">Released</StatusBadge>
          </p>
          <p>
            {result.replayed
              ? "This release had already been recorded; nothing was created twice."
              : "The service was released and one Job was created."}
          </p>
          <p>
            Job number: <strong data-testid="job-code">{result.job_code}</strong>
          </p>
          <Link className="ui-link-action inline-flex min-h-11 items-center" to={`/jobs/${result.job_id}`}>
            Open the Job
          </Link>
          <p className="text-muted-foreground">
            A released service has exactly one Job. It cannot be released again.
          </p>
        </div>
      </DetailSection>
    );

  const closed = release.isError && alreadyReleased(release.error);
  return (
    <DetailSection
      title="Release to a Job"
      description="Release is offered only when the system confirms the service is ready."
      fullWidth
    >
      {!access.review ? (
        <p className="col-span-full text-sm" role="note">
          Reviewing a release needs a separate permission, which your access does
          not include. Ask an administrator.
        </p>
      ) : previewError ? (
        <div role="alert" className="col-span-full space-y-2 text-sm">
          <p>{describeLoadError(previewError)}</p>
          <Button type="button" variant="outline" className="min-h-11" onClick={onRetry}>
            Try again
          </Button>
        </div>
      ) : !preview ? (
        <div role="status" className="col-span-full">
          <Skeleton className="h-20 w-full" />
          <span className="sr-only">Reviewing release readiness…</span>
        </div>
      ) : (
        <>
          <DetailField label="Result">
            <StatusBadge tone={preview.ready ? "success" : "pending"}>
              {preview.ready ? "Ready to release" : "Not ready"}
            </StatusBadge>
            {isFetching && (
              <span className="ml-2 text-xs text-muted-foreground">Updating…</span>
            )}
          </DetailField>
          {preview.cost.required && (
            <DetailField label="Cost control">
              {preview.cost.status === "UNKNOWN"
                ? "Required, and no cost evidence is available"
                : "Required"}
            </DetailField>
          )}
          {preview.blockers.length > 0 && (
            <div className="col-span-full space-y-2" data-testid="blockers">
              <h3 className="text-sm font-medium">What blocks the release</h3>
              <ul className="list-inside list-disc space-y-1 text-sm">
                {preview.blockers.map((code) => (
                  <li key={code}>{blockerText(code)}</li>
                ))}
              </ul>
            </div>
          )}
          {preview.next_actions.length > 0 && (
            <div className="col-span-full space-y-2" data-testid="next-actions">
              <h3 className="text-sm font-medium">What needs to happen</h3>
              <ul className="list-inside list-disc space-y-1 text-sm">
                {preview.next_actions.map((a, i) => (
                  <li key={`${a.fact}-${i}`}>{`${a.owner}: ${a.action}`}</li>
                ))}
              </ul>
            </div>
          )}
          {release.isError && (
            <p role="alert" className="col-span-full text-sm" data-testid="release-error">
              {refusalMessage(release.error)}
            </p>
          )}
          {closed ? null : !access.release ? (
            <p className="col-span-full text-sm" role="note">
              Releasing needs the release permission, which your access does not
              include.
            </p>
          ) : (
            <div className="col-span-full">
              <Button
                type="button"
                className="min-h-11"
                disabled={!preview.ready || release.isPending}
                onClick={() => setConfirming(true)}
              >
                Release to a Job
              </Button>
              {!preview.ready && (
                <p className="mt-2 text-sm text-muted-foreground">
                  The button unlocks when the system says the service is ready.
                </p>
              )}
            </div>
          )}
          <Dialog open={confirming} onOpenChange={setConfirming}>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Release this service?</DialogTitle>
                <DialogDescription>
                  This creates one numbered Job from the reviewed conditions
                  (version {preview.basis_version}). It cannot be undone and the
                  service cannot be released a second time.
                </DialogDescription>
              </DialogHeader>
              <DialogFooter>
                <Button type="button" variant="outline" className="min-h-11" onClick={() => setConfirming(false)}>
                  Cancel
                </Button>
                <Button type="button" className="min-h-11" disabled={release.isPending} onClick={() => release.mutate(preview)}>
                  {release.isPending ? "Releasing…" : "Release"}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        </>
      )}
    </DetailSection>
  );
}

/* ---------------------------------------------------------------- screen */

function Failure({ error, retry }: { error: unknown; retry: () => void }) {
  const missing = isApiError(error) && error.status === 404;
  return (
    <div role="alert" className="m-4 space-y-3 rounded-lg border border-border bg-card p-4 md:m-6">
      <h2 className="font-semibold">
        {missing ? "Handover unavailable" : "Could not load this handover"}
      </h2>
      <p className="text-sm">
        {missing ? "This handover is not available." : describeLoadError(error)}
      </p>
      {!missing && (
        <Button variant="outline" className="min-h-11" onClick={retry}>
          Try again
        </Button>
      )}
    </div>
  );
}

/** The gated screen. Denied people (and every production build) issue no request. */
export function HandoverReleaseScreen({
  planId,
  gateway = liveHandoverReleaseGateway,
}: {
  planId: number;
  gateway?: HandoverReleaseGateway;
}) {
  const user = useAuthStore((s) => s.user);
  const permissions = new Set(
    (user?.permissions ?? []).filter((p): p is string => typeof p === "string"),
  );
  const access = handoverReleaseAccess(permissions);
  if (!access.read) return <AccessDenied />;
  return <GatedHandover planId={planId} gateway={gateway} access={access} />;
}

function GatedHandover({
  planId,
  gateway,
  access,
}: {
  planId: number;
  gateway: HandoverReleaseGateway;
  access: Access;
}) {
  const client = useQueryClient();
  const plan = useQuery({
    queryKey: ["handover-plan", planId],
    queryFn: () => gateway.plan(planId),
    retry: shouldRetryQuery,
  });
  const acceptanceId = plan.data?.acceptance_id;
  const facts = useQuery({
    queryKey: ["handover-release-acceptance", acceptanceId],
    queryFn: () => gateway.acceptance(acceptanceId!),
    enabled: acceptanceId !== undefined,
    retry: shouldRetryQuery,
  });
  const stageId = plan.data?.stage_id;
  const preview = useQuery({
    queryKey: ["handover-release-preview", stageId],
    queryFn: () => gateway.preview(stageId!),
    enabled: stageId !== undefined && access.review,
    retry: shouldRetryQuery,
    // A review is a moment-in-time statement: never reuse a stale one silently.
    gcTime: 0,
    staleTime: 0,
    refetchOnWindowFocus: false,
    placeholderData: (previous) => previous,
  });
  if (plan.isError)
    return <Failure error={plan.error} retry={() => void plan.refetch()} />;
  if (facts.isError)
    return <Failure error={facts.error} retry={() => void facts.refetch()} />;
  if (plan.isPending || facts.isPending)
    return (
      <div role="status" className="p-4 md:p-6">
        <Skeleton className="h-48 w-full" />
        <span className="sr-only">Loading handover…</span>
      </div>
    );
  const refresh = () => {
    void client.invalidateQueries({ queryKey: ["handover-release-preview", stageId] });
    void preview.refetch();
  };
  return (
    <ScreenTemplate kind="detail" className="space-y-4 p-4 md:p-6">
      <PageHeader
        title="Handover and release"
        description="Turn this accepted service into one Job, once the agreed conditions are on record and the system confirms it is ready."
        backTo={`/customer-acceptances/${plan.data.acceptance_id}`}
      />
      <DetailPageLayout>
        <PreparedHandoverSection plan={plan.data} />
        <CompletionConditionsSection facts={facts.data} preview={preview.data} />
        {access.recordBasis ? (
          <RecordBasisSection
            gateway={gateway}
            acceptanceId={plan.data.acceptance_id}
            facts={facts.data}
            onRecorded={refresh}
          />
        ) : (
          <DetailSection title="Record completion conditions" fullWidth>
            <p className="col-span-full text-sm" role="note">
              Recording completion conditions needs a separate permission, which
              your access does not include. Ask an administrator.
            </p>
          </DetailSection>
        )}
        <ReleaseSection
          gateway={gateway}
          stageId={plan.data.stage_id}
          access={access}
          preview={preview.data}
          previewError={preview.isError ? preview.error : null}
          isFetching={preview.isFetching}
          onRetry={refresh}
          onReleased={() => void client.invalidateQueries({ queryKey: ["handover-release-preview", stageId] })}
        />
      </DetailPageLayout>
    </ScreenTemplate>
  );
}
