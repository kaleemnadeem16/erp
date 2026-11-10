import { useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router";
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
import { Label } from "../../components/ui/label";
import { Skeleton } from "../../components/ui/skeleton";
import { StatusBadge } from "../../components/ui/status-badge";
import { Textarea } from "../../components/ui/textarea";
import { formatDateTime } from "../../lib/format-date";
import {
  getApiReasonCode,
  isApiError,
  shouldRetryQuery,
} from "../../lib/api-errors";
import { AccessDenied } from "../../routes/permission-route";
import { useAuthStore } from "../../stores/auth-store";
import { IntentKey } from "./request-key";
import { QuotationScreenShell } from "./quotation-screen-shell";
import { QuotationFailure } from "./quotation-display";
import { QuotationDiscountAuthorityPanel } from "../discount-authority/quotation-discount-authority-panel";
import { liveApprovalGateway } from "./quotation-live-gateways";
import { quotationApprovalAccess } from "./quotation-approval-access";
import {
  APPROVAL_REFUSALS,
  APPROVAL_STATE_VIEW,
  CONCESSION_LABEL,
  type ApprovalDecisionKind,
  type QuotationApproval,
  type QuotationApprovalGateway,
} from "./quotation-approval-contract";

/** The reason shown for a refused request, decision or withdrawal; never echoes server text beyond the stable code's own wording. */
export function approvalRefusalMessage(error: unknown): string {
  const code = getApiReasonCode(error);
  if (code && APPROVAL_REFUSALS[code]) return APPROVAL_REFUSALS[code];
  if (isApiError(error) && error.status === 403)
    return "You don’t have permission to do this.";
  if (isApiError(error) && error.status === 404)
    return "This quotation is not available.";
  return "That could not be done. Try again.";
}

type Action = "request" | "approve" | "reject" | "withdraw";

const COPY: Record<
  Action,
  {
    title: string;
    description: string;
    confirm: string;
    reasonLabel: string | null;
    reasonRequired: boolean;
  }
> = {
  request: {
    title: "Ask for approval?",
    description:
      "An approver will be asked to approve the discount or price override on this draft exactly as it is now. If you change the draft afterwards, the request is cancelled.",
    confirm: "Ask for approval",
    reasonLabel: null,
    reasonRequired: false,
  },
  approve: {
    title: "Approve this discount?",
    description:
      "You approve the discount or price override on this draft exactly as it is now. If it is changed afterwards, the approval no longer counts. Approving does not issue the quotation.",
    confirm: "Approve",
    reasonLabel: "Note (optional)",
    reasonRequired: false,
  },
  reject: {
    title: "Reject this discount?",
    description:
      "The preparer will see your reason and has to change the draft before asking again.",
    confirm: "Reject",
    reasonLabel: "Reason",
    reasonRequired: true,
  },
  withdraw: {
    title: "Withdraw the request?",
    description:
      "The approval request is taken back so the draft can be changed. Ask again when you are ready.",
    confirm: "Withdraw request",
    reasonLabel: "Reason",
    reasonRequired: true,
  },
};

export function ApprovalActionDialog({
  action,
  onClose,
  onConfirm,
  pending,
  error,
}: {
  action: Action | null;
  onClose: () => void;
  onConfirm: (reason: string) => void;
  pending: boolean;
  error: unknown;
}) {
  const [reason, setReason] = useState("");
  const copy = action === null ? null : COPY[action];
  const trimmed = reason.trim();
  const missing = copy?.reasonRequired === true && trimmed === "";
  return (
    <Dialog
      open={action !== null}
      onOpenChange={(open) => {
        if (!open && !pending) {
          setReason("");
          onClose();
        }
      }}
    >
      <DialogContent>
        {copy && (
          <>
            <DialogHeader>
              <DialogTitle>{copy.title}</DialogTitle>
              <DialogDescription>{copy.description}</DialogDescription>
            </DialogHeader>
            {copy.reasonLabel && (
              <div className="space-y-2">
                <Label htmlFor="approval-reason">{copy.reasonLabel}</Label>
                <Textarea
                  id="approval-reason"
                  value={reason}
                  maxLength={1000}
                  onChange={(event) => setReason(event.target.value)}
                  aria-required={copy.reasonRequired}
                />
              </div>
            )}
            {error !== null && error !== undefined && (
              <p role="alert" className="text-sm text-destructive">
                {approvalRefusalMessage(error)}
              </p>
            )}
            <DialogFooter>
              <Button
                className="min-h-11"
                type="button"
                variant="outline"
                onClick={() => {
                  setReason("");
                  onClose();
                }}
                disabled={pending}
              >
                Cancel
              </Button>
              <Button
                className="min-h-11"
                type="button"
                variant={action === "reject" ? "destructive" : "default"}
                onClick={() => onConfirm(trimmed)}
                disabled={pending || missing}
              >
                {pending ? "Saving…" : copy.confirm}
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}

/** Where the draft stands with its approval and what this person may do about it; presentational, driven only by the server's `can`. */
export function QuotationApprovalView({
  approval,
  onAction,
  busy = false,
  backTo,
}: {
  approval: QuotationApproval;
  onAction?: (action: Action) => void;
  busy?: boolean;
  backTo?: string;
}) {
  const view = APPROVAL_STATE_VIEW[approval.state];
  const request = approval.request;
  const waitingOnOthers =
    approval.state === "PENDING" &&
    !approval.can.decide &&
    !approval.can.withdraw;
  return (
    <DetailPageLayout>
      <DetailSection
        title="Discount approval"
        description="A discount or price override above what is allowed has to be approved by someone other than the person who prepared it."
        fullWidth
      >
        <DetailField label="Status">
          <StatusBadge tone={view.tone}>{view.label}</StatusBadge>
        </DetailField>
        <p className="col-span-full text-sm" role="note">
          {approval.is_draft
            ? !approval.approval_needed && approval.state === "NOT_REQUESTED"
              ? "This draft has no discount or price override that needs approval."
              : view.note
            : "Only a draft quotation has an approval to decide."}
        </p>
        {approval.superseded && (
          <p className="col-span-full text-sm" role="note">
            An earlier approval request was for a previous version of this
            draft and no longer counts.
          </p>
        )}
        {request && (
          <>
            <DetailField label="Asked for">
              {CONCESSION_LABEL[request.concessions ?? ""] ??
                "a discount or price override"}
            </DetailField>
            <DetailField label="Requested by">
              {request.requested_by?.name ?? "Unknown person"}
            </DetailField>
            <DetailField label="Requested at">
              {formatDateTime(request.requested_at)}
            </DetailField>
            {request.status === "PENDING" && request.expires_at && (
              <DetailField label="Request valid until">
                {request.expired ? (
                  <StatusBadge tone="danger">Expired</StatusBadge>
                ) : (
                  formatDateTime(request.expires_at)
                )}
              </DetailField>
            )}
          </>
        )}
        {request?.status === "PENDING" && request.expired === true && (
          <p className="col-span-full text-sm" role="alert">
            This approval request has expired. It can no longer be decided;
            ask for approval again to get a fresh deadline.
          </p>
        )}
        {waitingOnOthers && (
          <p className="col-span-full text-sm" role="note">
            Waiting for an approver. You can’t approve this yourself.
          </p>
        )}
        {onAction && (
          <div className="col-span-full flex flex-wrap gap-3">
            {approval.can.request && (
              <Button
                className="min-h-11"
                type="button"
                onClick={() => onAction("request")}
                disabled={busy}
              >
                Ask for approval
              </Button>
            )}
            {approval.can.decide && (
              <>
                <Button
                  className="min-h-11"
                  type="button"
                  onClick={() => onAction("approve")}
                  disabled={busy}
                >
                  Approve
                </Button>
                <Button
                  className="min-h-11"
                  type="button"
                  variant="outline"
                  onClick={() => onAction("reject")}
                  disabled={busy}
                >
                  Reject
                </Button>
              </>
            )}
            {approval.can.withdraw && (
              <Button
                className="min-h-11"
                type="button"
                variant="outline"
                onClick={() => onAction("withdraw")}
                disabled={busy}
              >
                Withdraw request
              </Button>
            )}
          </div>
        )}
        {backTo && (
          <div className="col-span-full">
            <Link
              className="ui-link-action inline-flex min-h-11 items-center"
              to={backTo}
            >
              Issue readiness
            </Link>
          </div>
        )}
      </DetailSection>
      {request && request.decisions.length > 0 && (
        <DetailSection title="Decisions" fullWidth>
          <ul className="col-span-full space-y-2 text-sm">
            {request.decisions.map((decision) => (
              <li key={`${decision.decided_at}-${decision.decision}`}>
                <span className="font-semibold">
                  {decision.decision === "APPROVED" ? "Approved" : "Rejected"}
                </span>{" "}
                by {decision.decided_by?.name ?? "an approver"} on{" "}
                {formatDateTime(decision.decided_at)}
                {decision.reason ? (
                  <span className="block break-words">
                    “{decision.reason}”
                  </span>
                ) : null}
              </li>
            ))}
          </ul>
        </DetailSection>
      )}
      {request && request.status === "WITHDRAWN" && request.closing_reason && (
        <DetailSection title="Withdrawn" fullWidth>
          <p className="col-span-full break-words text-sm">
            “{request.closing_reason}”
          </p>
        </DetailSection>
      )}
    </DetailPageLayout>
  );
}

/** The approval panel for one revision. Denies before any query unless the person holds a duty for it (behind the same gate as the other quotation screens). */
export function QuotationApprovalScreen({
  revisionId,
  gateway = liveApprovalGateway,
}: {
  revisionId: number;
  gateway?: QuotationApprovalGateway;
}) {
  const user = useAuthStore((s) => s.user);
  const access = quotationApprovalAccess(
    new Set(
      (user?.permissions ?? []).filter(
        (p): p is string => typeof p === "string",
      ),
    ),
  );
  if (!access.read) return <AccessDenied />;
  return (
    <QuotationScreenShell
      title="Discount approval"
      description="Where this draft stands with its discount or price override approval."
      revisionId={revisionId}
    >
      <GatedApproval revisionId={revisionId} gateway={gateway} />
    </QuotationScreenShell>
  );
}

function GatedApproval({
  revisionId,
  gateway,
}: {
  revisionId: number;
  gateway: QuotationApprovalGateway;
}) {
  const client = useQueryClient();
  const [action, setAction] = useState<Action | null>(null);
  // One key per write intent (action + reviewed generation + reason): an uncertain failure keeps it so a retry replays; success or a
  // definitive refusal ends it, and any change of intent mints a new key.
  const keys = useRef(new IntentKey());
  const approval = useQuery({
    queryKey: ["quotation-approval", revisionId],
    queryFn: () => gateway.read(revisionId),
    retry: shouldRetryQuery,
  });
  const done = () => {
    keys.current.settle();
    setAction(null);
    void client.invalidateQueries({
      queryKey: ["quotation-approval", revisionId],
    });
    void client.invalidateQueries({
      queryKey: ["quotation-issue-readiness", revisionId],
    });
  };
  const write = useMutation({
    mutationFn: ({
      what,
      generation,
      reason,
    }: {
      what: Action;
      generation: number;
      reason: string;
    }) => {
      const requestKey = keys.current.for({
        revisionId,
        what,
        generation,
        reason,
      });
      if (what === "request")
        return gateway.request({
          revisionId,
          expectedGeneration: generation,
          requestKey,
        });
      if (what === "withdraw")
        return gateway.withdraw({
          revisionId,
          expectedGeneration: generation,
          reason,
          requestKey,
        });
      const decision: ApprovalDecisionKind =
        what === "approve" ? "APPROVED" : "REJECTED";
      return gateway.decide({
        revisionId,
        expectedGeneration: generation,
        decision,
        reason: reason === "" ? null : reason,
        requestKey,
      });
    },
    onSuccess: done,
    onError: (error) => {
      keys.current.settle(error);
      // A moved draft or a closed request: show the truth.
      if (!isApiError(error) || error.status < 500)
        void client.invalidateQueries({
          queryKey: ["quotation-approval", revisionId],
        });
    },
  });
  if (approval.isError)
    return (
      <QuotationFailure
        error={approval.error}
        retry={() => void approval.refetch()}
      />
    );
  if (!approval.data)
    return (
      <div role="status">
        <Skeleton className="h-48 w-full" />
        <span className="sr-only">Loading approval…</span>
      </div>
    );
  const reviewed = approval.data;
  return (
    <>
      <QuotationApprovalView
        approval={reviewed}
        onAction={(next) => {
          write.reset();
          setAction(next);
        }}
        busy={write.isPending}
        backTo={`/quotations/${revisionId}/issue`}
      />
      <QuotationDiscountAuthorityPanel revisionId={revisionId} />
      <ApprovalActionDialog
        action={action}
        onClose={() => setAction(null)}
        // The generation the person reviewed is the one sent; a refetch that changes it changes the intent and the key.
        onConfirm={(reason) =>
          action &&
          write.mutate({
            what: action,
            generation: reviewed.input_generation,
            reason,
          })
        }
        pending={write.isPending}
        error={write.error}
      />
    </>
  );
}
