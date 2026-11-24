import { useEffect, useRef, useState } from "react";
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
  type Conditions,
  type HandoverNavigationGateway,
  type HandoverPlan,
  type HandoverReleaseGateway,
  type ReleaseOutcome,
  type ReleasePreview,
  type ReleaseStatus,
} from "./handover-release-contract";
import {
  liveHandoverNavigationGateway,
  liveHandoverReleaseGateway,
} from "./handover-release-gateway";

type Access = ReturnType<typeof handoverReleaseAccess>;

/** The release evidence already existed when a release was about to be requested. */
class AlreadyReleasedElsewhere extends Error {}

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
      <DetailField label="Prepared">
        {formatDateTime(plan.recorded_at)}
      </DetailField>
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
  conditions,
  conditionsError,
  mayReadConditions,
  onRetryConditions,
}: {
  facts: AcceptanceFacts;
  preview: ReleasePreview | undefined;
  /** The current agreed conditions (head version); undefined while loading or when this person may not read them. */
  conditions: Conditions | undefined;
  conditionsError?: unknown;
  mayReadConditions: boolean;
  onRetryConditions?: () => void;
}) {
  const current = conditions?.current ?? null;
  return (
    <DetailSection
      title="Customer-agreed completion conditions"
      description="What the customer agreed the service must achieve. Each recording is a new immutable version."
      fullWidth
    >
      <DetailField label="Issued service description" fullWidth>
        <span data-testid="issued-description">{facts.issuedDescription}</span>
      </DetailField>
      {!mayReadConditions ? (
        <p
          className="col-span-full text-sm"
          role="note"
          data-testid="conditions-hidden"
        >
          The recorded conditions are shown to people who may record or release
          them.
        </p>
      ) : conditionsError ? (
        <div role="alert" className="col-span-full space-y-2 text-sm">
          <p>{describeLoadError(conditionsError)}</p>
          {onRetryConditions && (
            <Button
              type="button"
              variant="outline"
              className="min-h-11"
              onClick={onRetryConditions}
            >
              Try again
            </Button>
          )}
        </div>
      ) : conditions === undefined ? (
        <div role="status" className="col-span-full">
          <Skeleton className="h-12 w-full" />
          <span className="sr-only">Loading the recorded conditions…</span>
        </div>
      ) : current === null ? (
        <p
          className="col-span-full text-sm"
          role="status"
          data-testid="no-basis"
        >
          No completion conditions are recorded yet. A service cannot be
          released until they are.
        </p>
      ) : (
        <>
          <DetailField label="Recorded conditions">
            <StatusBadge tone="success">{`Version ${current.version}`}</StatusBadge>
            <span className="ml-2 text-xs text-muted-foreground">
              {current.terms_source === "ISSUED_LINE"
                ? "the issued description, as accepted"
                : "from a later written customer message"}
            </span>
          </DetailField>
          <DetailField label="Recorded">
            {formatDateTime(current.recorded_at)}
          </DetailField>
          <DetailField label="Agreed text" fullWidth>
            <pre
              className="whitespace-pre-wrap break-words font-sans text-sm"
              data-testid="agreed-text"
            >
              {current.agreed_text}
            </pre>
          </DetailField>
          <DetailField label="Materials">
            {SCOPE_LABELS[current.materials_scope] ?? "Not stated"}
          </DetailField>
          <DetailField label="Subcontractors">
            {SCOPE_LABELS[current.subcontract_scope] ?? "Not stated"}
          </DetailField>
          {!current.evidence_current && (
            <p
              className="col-span-full rounded-md border border-border bg-muted/40 p-3 text-sm"
              role="alert"
              data-testid="evidence-corrected"
            >
              The customer message behind these conditions was later corrected.
              They no longer support a release. Record the conditions again from
              the current message.
            </p>
          )}
        </>
      )}
      {preview !== undefined && preview.basis_id !== null && (
        <DetailField label="Source check">
          <StatusBadge
            tone={
              preview.applicability.status === "NOT_APPLICABLE"
                ? "success"
                : "pending"
            }
          >
            {APPLICABILITY_LABELS[preview.applicability.status] ?? "Not proven"}
          </StatusBadge>
        </DetailField>
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
        <legend className="text-sm font-medium">
          Where do the conditions come from?
        </legend>
        <label className="flex min-h-11 items-start gap-2 text-sm">
          <input
            type="radio"
            name="basis-mode"
            className="mt-1"
            checked={mode === "issued"}
            onChange={() => setMode("issued")}
          />
          <span>
            <span className="font-medium">
              The issued service description, as accepted.
            </span>
            <span className="block text-muted-foreground">
              Uses the customer’s own acceptance message. It states no materials
              or subcontractor scope, so those stay unproven.
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
            <span className="font-medium">
              A later written customer message.
            </span>
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
            <Button
              type="button"
              variant="outline"
              className="min-h-11"
              onClick={() => void evidence.refetch()}
            >
              Try again
            </Button>
          </div>
        ) : evidence.isPending ? (
          <div role="status" className="col-span-full">
            <Skeleton className="h-16 w-full" />
            <span className="sr-only">Loading customer messages…</span>
          </div>
        ) : messages.length === 0 ? (
          <p
            className="col-span-full text-sm"
            role="status"
            data-testid="no-messages"
          >
            This customer has no other written message on this case. Ask the
            customer to confirm the conditions in writing, then record that
            message as customer evidence first.
          </p>
        ) : (
          <fieldset className="col-span-full space-y-2">
            <legend className="text-sm font-medium">
              Choose the customer message
            </legend>
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
                      <span className="block text-muted-foreground">
                        {problem}
                      </span>
                    )}
                  </span>
                </label>
              );
            })}
            <p className="text-sm text-muted-foreground" role="note">
              The message text is recorded exactly as written. The system
              rejects it if it was recorded before the acceptance, does not
              verify the sender’s authority, or states a scope in any form other
              than the exact four-line declaration.
            </p>
          </fieldset>
        ))}
      {record.isError && (
        <p
          role="alert"
          className="col-span-full text-sm"
          data-testid="basis-error"
        >
          {refusalMessage(record.error)}
        </p>
      )}
      {record.isSuccess && (
        <p
          role="status"
          className="col-span-full text-sm"
          data-testid="basis-recorded"
        >
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

/* ---------------------------------------------------------------- an existing release */

/** What the server's release evidence says about a stage that was already released. Never offers to release again. */
export function ExistingReleaseSection({
  release,
}: {
  release: ReleaseStatus;
}) {
  return (
    <DetailSection title="Release" fullWidth>
      <div
        className="col-span-full space-y-2 text-sm"
        role="status"
        data-testid="existing-release"
      >
        <p>
          <StatusBadge tone="success">
            Already released — existing Job
          </StatusBadge>
        </p>
        <p>
          This service was released on {formatDateTime(release.released_at)}. It
          has exactly one Job, and a second one cannot be created.
        </p>
        {release.job_access === "VISIBLE" && release.job ? (
          <>
            <p>
              Job number:{" "}
              <strong data-testid="job-code">{release.job.code}</strong>
            </p>
            <Link
              className="ui-link-action inline-flex min-h-11 items-center"
              to={`/jobs/${release.job.id}`}
            >
              Open the Job
            </Link>
          </>
        ) : release.job_access === "NOT_PERMITTED" ? (
          <p data-testid="job-not-permitted">
            You don’t have permission to see Jobs, so its number is not shown
            here. Ask someone who can see Jobs.
          </p>
        ) : (
          <p data-testid="job-unavailable">
            The Job reference is not available right now. Try again shortly.
          </p>
        )}
      </div>
    </DetailSection>
  );
}

/** The result of a release made in this visit (the server's own answer for it). A later visit shows the existing release instead. */
export function ReleaseResultSection({ result }: { result: ReleaseOutcome }) {
  return (
    <DetailSection title="Release" fullWidth>
      <div
        className="col-span-full space-y-2 text-sm"
        role="status"
        data-testid="release-result"
      >
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
        <Link
          className="ui-link-action inline-flex min-h-11 items-center"
          to={`/jobs/${result.job_id}`}
        >
          Open the Job
        </Link>
        <p className="text-muted-foreground">
          A released service has exactly one Job. It cannot be released again.
        </p>
      </div>
    </DetailSection>
  );
}

/* ---------------------------------------------------------------- release readiness and release */

export function ReleaseSection({
  gateway,
  planId,
  stageId,
  access,
  preview,
  previewError,
  isFetching,
  basisInSync,
  onRetry,
  onReleased,
}: {
  gateway: HandoverReleaseGateway;
  planId: number;
  stageId: number;
  access: Access;
  preview: ReleasePreview | undefined;
  previewError: unknown;
  isFetching: boolean;
  /** False while the recorded conditions have moved on from the reviewed ones: the review is refreshed first. */
  basisInSync: boolean;
  onRetry: () => void;
  onReleased: (outcome?: ReleaseOutcome) => void;
}) {
  const [confirming, setConfirming] = useState(false);
  const keys = useRef(new IntentKey());
  const release = useMutation({
    mutationFn: async (p: ReleasePreview) => {
      // The server's release evidence is the authority, not this screen's memory: if the stage was released since the page loaded
      // (by anyone), say so instead of asking again; a second request would only resolve to the same Job.
      const current = await gateway.plan(planId);
      if (current.release_performed) throw new AlreadyReleasedElsewhere();
      const input = {
        stageId,
        basisId: p.basis_id!,
        fingerprint: p.fingerprint,
      };
      return gateway.release({ ...input, requestKey: keys.current.for(input) });
    },
    onSuccess: (outcome) => {
      keys.current.settle();
      setConfirming(false);
      onReleased(outcome);
    },
    onError: (error) => {
      keys.current.settle(error);
      setConfirming(false);
      if (needsFreshReview(error)) onRetry();
      if (error instanceof AlreadyReleasedElsewhere || alreadyReleased(error))
        onReleased();
    },
  });

  const closed =
    release.isError &&
    (alreadyReleased(release.error) ||
      release.error instanceof AlreadyReleasedElsewhere);
  return (
    <DetailSection
      title="Release to a Job"
      description="Release is offered only when the system confirms the service is ready."
      fullWidth
    >
      {!access.review ? (
        <p className="col-span-full text-sm" role="note">
          Reviewing a release needs a separate permission, which your access
          does not include. Ask an administrator.
        </p>
      ) : previewError ? (
        <div role="alert" className="col-span-full space-y-2 text-sm">
          <p>{describeLoadError(previewError)}</p>
          <Button
            type="button"
            variant="outline"
            className="min-h-11"
            onClick={onRetry}
          >
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
              <span className="ml-2 text-xs text-muted-foreground">
                Updating…
              </span>
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
            <p
              role="alert"
              className="col-span-full text-sm"
              data-testid="release-error"
            >
              {release.error instanceof AlreadyReleasedElsewhere
                ? "This service was already released — it has an existing Job. Nothing new was created."
                : refusalMessage(release.error)}
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
                disabled={!preview.ready || !basisInSync || release.isPending}
                onClick={() => setConfirming(true)}
              >
                Release to a Job
              </Button>
              {!preview.ready && (
                <p className="mt-2 text-sm text-muted-foreground">
                  The button unlocks when the system says the service is ready.
                </p>
              )}
              {preview.ready && !basisInSync && (
                <p
                  className="mt-2 text-sm text-muted-foreground"
                  role="status"
                  data-testid="review-refreshing"
                >
                  The recorded conditions changed. Refreshing the review…
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
                <Button
                  type="button"
                  variant="outline"
                  className="min-h-11"
                  onClick={() => setConfirming(false)}
                >
                  Cancel
                </Button>
                <Button
                  type="button"
                  className="min-h-11"
                  disabled={release.isPending}
                  onClick={() => release.mutate(preview)}
                >
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
    <div
      role="alert"
      className="m-4 space-y-3 rounded-lg border border-border bg-card p-4 md:m-6"
    >
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
  const [justReleased, setJustReleased] = useState<ReleaseOutcome | null>(null);
  const released =
    plan.data?.release_performed === true || justReleased !== null;
  const mayReadConditions = access.recordBasis || access.review;
  const conditions = useQuery({
    queryKey: ["handover-release-conditions", acceptanceId],
    queryFn: () => gateway.conditions(acceptanceId!),
    enabled: acceptanceId !== undefined && mayReadConditions,
    retry: shouldRetryQuery,
    staleTime: 0,
  });
  const preview = useQuery({
    queryKey: ["handover-release-preview", stageId],
    queryFn: () => gateway.preview(stageId!),
    enabled:
      stageId !== undefined &&
      access.review &&
      plan.data !== undefined &&
      !released,
    retry: shouldRetryQuery,
    // A review is a moment-in-time statement: never reuse a stale one silently.
    gcTime: 0,
    staleTime: 0,
    placeholderData: (previous) => previous,
  });
  // A correction or a new basis version elsewhere makes a reviewed release stale: compare the head conditions with what was reviewed.
  const headBasis = conditions.data?.current?.basis_id ?? null;
  const reviewedBasis = preview.data?.basis_id ?? null;
  const basisInSync =
    !mayReadConditions ||
    conditions.data === undefined ||
    preview.data === undefined ||
    headBasis === reviewedBasis;
  const refetchPreview = preview.refetch;
  useEffect(() => {
    if (!basisInSync) void refetchPreview();
  }, [basisInSync, headBasis, reviewedBasis, refetchPreview]);
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
    void client.invalidateQueries({
      queryKey: ["handover-release-preview", stageId],
    });
    void client.invalidateQueries({
      queryKey: ["handover-release-conditions", acceptanceId],
    });
    void preview.refetch();
  };
  const reload = () => {
    void client.invalidateQueries({ queryKey: ["handover-plan", planId] });
    void client.invalidateQueries({
      queryKey: ["handover-release-preview", stageId],
    });
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
        <CompletionConditionsSection
          facts={facts.data}
          preview={preview.data}
          conditions={conditions.data}
          conditionsError={conditions.isError ? conditions.error : null}
          mayReadConditions={mayReadConditions}
          onRetryConditions={() => void conditions.refetch()}
        />
        {released ? null : access.recordBasis ? (
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
        {justReleased ? (
          <ReleaseResultSection result={justReleased} />
        ) : released && plan.data.release ? (
          <ExistingReleaseSection release={plan.data.release} />
        ) : released ? null : (
          <ReleaseSection
            gateway={gateway}
            planId={planId}
            stageId={plan.data.stage_id}
            access={access}
            preview={preview.data}
            previewError={preview.isError ? preview.error : null}
            isFetching={preview.isFetching}
            basisInSync={basisInSync}
            onRetry={refresh}
            onReleased={(outcome) => {
              if (outcome) setJustReleased(outcome);
              reload();
            }}
          />
        )}
      </DetailPageLayout>
    </ScreenTemplate>
  );
}

/* ---------------------------------------------------------------- navigation from the acceptance */

/**
 * The link from a customer acceptance to its prepared handover (Customer Acceptance -> Prepared Handover -> Release Review). It asks the server about THIS
 * acceptance only, under the same reading rules as the handover itself, and offers nothing it cannot open. Absent entirely for people without the reading
 * duties and in production builds, where no request is made.
 */
export function AcceptanceHandoverPanel({
  acceptanceId,
  gateway = liveHandoverNavigationGateway,
}: {
  acceptanceId: number;
  gateway?: HandoverNavigationGateway;
}) {
  const user = useAuthStore((s) => s.user);
  const permissions = new Set(
    (user?.permissions ?? []).filter((p): p is string => typeof p === "string"),
  );
  if (!handoverReleaseAccess(permissions).read) return null;
  return (
    <GatedAcceptanceHandover acceptanceId={acceptanceId} gateway={gateway} />
  );
}

function GatedAcceptanceHandover({
  acceptanceId,
  gateway,
}: {
  acceptanceId: number;
  gateway: HandoverNavigationGateway;
}) {
  const status = useQuery({
    queryKey: ["acceptance-handover", acceptanceId],
    queryFn: () => gateway.status(acceptanceId),
    retry: shouldRetryQuery,
    staleTime: 0,
  });
  const data = status.data;
  return (
    <DetailSection
      title="Handover"
      description="Where this accepted service stands on its way to a Job."
      fullWidth
    >
      {status.isError ? (
        <div
          role="alert"
          className="col-span-full space-y-2 text-sm"
          data-testid="handover-link-error"
        >
          <p>
            {isApiError(status.error) && status.error.status === 404
              ? "The handover for this acceptance is not available."
              : describeLoadError(status.error)}
          </p>
          {!(isApiError(status.error) && status.error.status === 404) && (
            <Button
              type="button"
              variant="outline"
              className="min-h-11"
              onClick={() => void status.refetch()}
            >
              Try again
            </Button>
          )}
        </div>
      ) : !data ? (
        <div role="status" className="col-span-full">
          <Skeleton className="h-12 w-full" />
          <span className="sr-only">Checking for a prepared handover…</span>
        </div>
      ) : !data.prepared || data.plan_id === null ? (
        <p
          className="col-span-full text-sm"
          role="status"
          data-testid="handover-not-prepared"
        >
          No handover has been prepared for this service yet. Release review
          opens once one is prepared.
        </p>
      ) : (
        <div className="col-span-full space-y-2 text-sm">
          <p role="status" data-testid="handover-prepared">
            <StatusBadge tone={data.release_performed ? "success" : "pending"}>
              {data.release_performed ? "Released" : "Prepared, not released"}
            </StatusBadge>
          </p>
          <Link
            className="ui-link-action inline-flex min-h-11 items-center"
            to={`/handover-plans/${data.plan_id}`}
            data-testid="handover-link"
          >
            {data.release_performed
              ? "Open the released handover"
              : "Open the prepared handover and release review"}
          </Link>
        </div>
      )}
    </DetailSection>
  );
}
