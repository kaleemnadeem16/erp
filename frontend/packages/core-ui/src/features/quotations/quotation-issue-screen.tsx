import { useRef, useState } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { DetailField } from "../../components/detail/detail-field";
import {
  DetailPageLayout,
  DetailSection,
} from "../../components/detail/detail-page-layout";
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
import { formatDate, formatDateTime } from "../../lib/format-date";
import {
  getApiReasonCode,
  isApiError,
  shouldRetryQuery,
} from "../../lib/api-errors";
import { IntentKey } from "./request-key";
import { liveIssueGateway } from "./quotation-live-gateways";
import { AccessDenied } from "../../routes/permission-route";
import { useAuthStore } from "../../stores/auth-store";
import { QuotationFailure } from "./quotation-display";
import {
  groupBlockers,
  ISSUE_REFUSALS,
  type IssuedTerms,
  type IssueOutcome,
  type IssueReadiness,
  type QuotationIssueGateway,
} from "./quotation-issue-contract";
import { quotationMilestoneAccess } from "./quotation-milestone-access";

/** The reason shown for a refused issue; never echoes server text beyond the stable code's own wording. */
export function issueRefusalMessage(error: unknown): string {
  const code = getApiReasonCode(error);
  if (code && ISSUE_REFUSALS[code]) return ISSUE_REFUSALS[code];
  if (isApiError(error) && error.status === 403)
    return "You don’t have permission to issue this quotation.";
  if (isApiError(error) && error.status === 404)
    return "This quotation is not available.";
  return "The quotation could not be issued. Try again.";
}

export function QuotationReadinessView({
  readiness,
  mayIssue,
  onIssue,
  onRecheck,
  rechecking = false,
}: {
  readiness: IssueReadiness;
  mayIssue: boolean;
  onIssue: () => void;
  onRecheck?: () => void;
  rechecking?: boolean;
}) {
  const groups = groupBlockers(readiness.blockers);
  return (
    <DetailPageLayout>
      <DetailSection
        title="Issue readiness"
        description="Checked by the server each time. Anything unknown counts as a blocker."
        fullWidth
      >
        <DetailField label="Result">
          {readiness.issue_ready ? (
            <StatusBadge tone="success">Ready to issue</StatusBadge>
          ) : (
            <StatusBadge tone="danger">Not ready</StatusBadge>
          )}
        </DetailField>
        <DetailField label="Draft generation reviewed">
          {readiness.input_generation}
        </DetailField>
        <div className="col-span-full flex flex-wrap gap-3">
          {onRecheck && (
            <Button
              className="min-h-11"
              type="button"
              variant="outline"
              onClick={onRecheck}
              disabled={rechecking}
            >
              Check again
            </Button>
          )}
          {mayIssue && (
            <Button
              className="min-h-11"
              type="button"
              onClick={onIssue}
              disabled={!readiness.issue_ready}
              aria-describedby="issue-hint"
            >
              Issue quotation
            </Button>
          )}
        </div>
        {mayIssue && !readiness.issue_ready && (
          <p id="issue-hint" className="col-span-full text-sm">
            Resolve the items below before issuing.
          </p>
        )}
        {!mayIssue && (
          <p className="col-span-full text-sm">
            You can review readiness, but issuing needs a separate permission.
          </p>
        )}
      </DetailSection>
      {groups.map((group) => (
        <DetailSection key={group.id} title={group.title} fullWidth>
          <ul className="col-span-full list-inside list-disc space-y-1 text-sm">
            {group.items.map((item) => (
              <li key={item.label} className="break-words">
                {item.label}
                {item.lineCount > 1 ? ` (${item.lineCount} lines)` : ""}
                {item.lineCount === 1 ? " (1 line)" : ""}
              </li>
            ))}
          </ul>
        </DetailSection>
      ))}
    </DetailPageLayout>
  );
}

export function IssueConfirmDialog({
  open,
  onOpenChange,
  onConfirm,
  pending,
  error,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
  pending: boolean;
  error: unknown;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Issue this quotation?</DialogTitle>
          <DialogDescription>
            Issuing gives the quotation its official number and freezes its
            lines, prices and totals. It cannot be edited afterwards; changes
            need a new revision.
          </DialogDescription>
        </DialogHeader>
        {error !== null && error !== undefined && (
          <p role="alert" className="text-sm text-destructive">
            {issueRefusalMessage(error)}
          </p>
        )}
        <DialogFooter>
          <Button
            className="min-h-11"
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={pending}
          >
            Cancel
          </Button>
          <Button
            className="min-h-11"
            type="button"
            onClick={onConfirm}
            disabled={pending}
          >
            {pending ? "Issuing…" : "Issue quotation"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function money(value: string | null, currency: number) {
  return value === null ? "None" : `${value} · Currency reference #${currency}`;
}

/** The issued record with its frozen terms, read only (GET .../issued); totals only when the server includes them for this person. */
export function QuotationIssuedView({ terms }: { terms: IssuedTerms }) {
  const same = terms.prepared_by === terms.issued_by;
  return (
    <DetailPageLayout>
      <DetailSection title="Issued quotation" fullWidth>
        <DetailField label="Official number">
          <span className="font-semibold">{terms.official_number}</span>
        </DetailField>
        <DetailField label="Status">
          <StatusBadge tone="success">Issued</StatusBadge>
        </DetailField>
        <DetailField label="Issued at">
          {formatDateTime(terms.issued_at)}
        </DetailField>
        <DetailField label="Prepared by (maker)">
          Person reference #{terms.prepared_by}
        </DetailField>
        <DetailField label="Issued by (checker)">
          Person reference #{terms.issued_by}
        </DetailField>
        {same && (
          <p className="col-span-full text-sm" role="note">
            The same person prepared and issued this quotation.
          </p>
        )}
      </DetailSection>
      <DetailSection
        title="Frozen terms"
        description="Read only. These were fixed when the quotation was issued."
        fullWidth
      >
        <DetailField label="Pricing date">
          {formatDate(terms.pricing_date)}
        </DetailField>
        <DetailField label="Valid through">
          {formatDate(terms.valid_through_local_date)}
        </DetailField>
        <DetailField label="Expires">
          {formatDateTime(terms.expires_at)}
        </DetailField>
        {terms.totals === null ? (
          <p className="col-span-full text-sm" role="note">
            Totals are shown to people who prepare or issue this quotation.
          </p>
        ) : (
          <>
            <DetailField label="Net total">
              {money(terms.totals.net_total, terms.currency_id)}
            </DetailField>
            <DetailField label="Optional net total">
              {money(terms.totals.optional_net_total, terms.currency_id)}
            </DetailField>
            <DetailField label="Tax">
              {money(terms.totals.tax_total, terms.currency_id)}
            </DetailField>
            <DetailField label="Total including tax">
              {money(terms.totals.gross_total, terms.currency_id)}
            </DetailField>
          </>
        )}
      </DetailSection>
    </DetailPageLayout>
  );
}

/**
 * Readiness, the confirmed Issue action and the issued state for one revision. Unregistered in
 * Phase 1; contract pending (no HTTP routes yet), so the default gateway fails closed.
 */
export function QuotationIssueScreen({
  revisionId,
  gateway = liveIssueGateway,
}: {
  revisionId: number;
  gateway?: QuotationIssueGateway;
}) {
  const user = useAuthStore((s) => s.user);
  const access = quotationMilestoneAccess(
    new Set(
      (user?.permissions ?? []).filter(
        (p): p is string => typeof p === "string",
      ),
    ),
  );
  // Reading readiness needs prepare; issuing needs issue. Either alone may see its part.
  if (!access.readiness && !access.issue) return <AccessDenied />;
  return (
    <GatedIssue
      revisionId={revisionId}
      gateway={gateway}
      mayIssue={access.issue}
      mayReadiness={access.readiness}
    />
  );
}

function GatedIssue({
  revisionId,
  gateway,
  mayIssue,
  mayReadiness,
}: {
  revisionId: number;
  gateway: QuotationIssueGateway;
  mayIssue: boolean;
  mayReadiness: boolean;
}) {
  const [confirming, setConfirming] = useState(false);
  const [outcome, setOutcome] = useState<IssueOutcome | null>(null);
  // One key per issue intent (revision + reviewed generation): an uncertain failure keeps it so a retry replays,
  // success or a definitive refusal ends it, and a newer generation always gets a new key.
  const keys = useRef(new IntentKey());
  const readiness = useQuery({
    queryKey: ["quotation-issue-readiness", revisionId],
    queryFn: () => gateway.readiness(revisionId),
    enabled: mayReadiness && outcome === null,
    retry: shouldRetryQuery,
  });
  const issue = useMutation({
    mutationFn: (expectedGeneration: number) =>
      gateway.issue({
        revisionId,
        expectedGeneration,
        requestKey: keys.current.for({ revisionId, expectedGeneration }),
      }),
    onSuccess: (result) => {
      keys.current.settle();
      setConfirming(false);
      setOutcome(result);
    },
    onError: (error) => keys.current.settle(error),
  });
  // Not a draft any more: issued, or a cancelled draft. Only the issued-record read can tell (a cancelled draft is a 404 there).
  const notDraft =
    getApiReasonCode(readiness.error) === "QUOTATION_NOT_EDITABLE";
  const record = useQuery({
    queryKey: ["quotation-issued", revisionId],
    queryFn: () => gateway.issued(revisionId),
    enabled: outcome !== null || notDraft,
    retry: shouldRetryQuery,
  });
  if (outcome !== null || notDraft) {
    if (record.data) return <QuotationIssuedView terms={record.data} />;
    if (outcome !== null)
      return <QuotationIssueOutcomeView outcome={outcome} />;
    if (record.isPending) return <Loading />;
    return (
      <p role="status" className="p-4 text-sm">
        Only a draft quotation can be issued. This quotation is no longer a
        draft.
      </p>
    );
  }
  if (!mayReadiness)
    return (
      <p className="p-4 text-sm">
        Issuing needs the readiness result, which your access does not include.
      </p>
    );
  if (readiness.isError)
    return (
      <QuotationFailure
        error={readiness.error}
        retry={() => void readiness.refetch()}
      />
    );
  if (!readiness.data) return <Loading />;
  const reviewed = readiness.data;
  return (
    <>
      <QuotationReadinessView
        readiness={reviewed}
        mayIssue={mayIssue}
        onIssue={() => {
          issue.reset();
          setConfirming(true);
        }}
        onRecheck={() => void readiness.refetch()}
        rechecking={readiness.isFetching}
      />
      {mayIssue && (
        <IssueConfirmDialog
          open={confirming}
          onOpenChange={(open) => {
            if (!open && !issue.isPending) setConfirming(false);
          }}
          // The generation the person reviewed is the one sent; a refetch that changes it changes the intent and the key.
          onConfirm={() => issue.mutate(reviewed.input_generation)}
          pending={issue.isPending}
          error={issue.error}
        />
      )}
    </>
  );
}

/** What the server confirmed when the quotation was issued. Only fields `IssueQuotation` returns; nothing monetary. */
export function QuotationIssueOutcomeView({
  outcome,
}: {
  outcome: IssueOutcome;
}) {
  return (
    <DetailPageLayout>
      <DetailSection title="Quotation issued" fullWidth>
        <DetailField label="Official number">
          <span className="font-semibold">{outcome.official_number}</span>
        </DetailField>
        <DetailField label="Status">
          <StatusBadge tone="success">Issued</StatusBadge>
        </DetailField>
        <DetailField label="Issued at">
          {formatDateTime(outcome.issued_at)}
        </DetailField>
        {outcome.replayed && (
          <p role="note" className="col-span-full text-sm">
            This quotation had already been issued by this request; nothing was
            issued twice.
          </p>
        )}
      </DetailSection>
    </DetailPageLayout>
  );
}

function Loading() {
  return (
    <div role="status">
      <Skeleton className="h-48 w-full" />
      <span className="sr-only">Loading quotation…</span>
    </div>
  );
}
