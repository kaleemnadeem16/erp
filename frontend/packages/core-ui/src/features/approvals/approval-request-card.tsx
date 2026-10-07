import type { ApprovalRequestResource } from "@erp/core-api";
import { ArrowRight, Clock, FileText } from "lucide-react";
import { Button } from "../../components/ui/button";
import { StatusBadge, type StatusTone } from "../../components/ui/status-badge";
import { formatDateTime } from "../../lib/format-date";
import {
  REQUEST_STATUS_LABELS,
  readableApprovalAmount,
} from "./approval-labels";

export function approvalSummary(request: ApprovalRequestResource) {
  return request.summary as unknown as Record<string, string | undefined>;
}
export function subjectLabel(code: string) {
  return code
    .toLowerCase()
    .replace(/_/g, " ")
    .replace(/^./, (letter) => letter.toUpperCase());
}
export function isOverdue(request: ApprovalRequestResource) {
  return (
    request.status === "PENDING" &&
    request.steps.some((step) => step.status === "OPEN" && step.overdue)
  );
}
export function requestTone(status: string): StatusTone {
  return status === "PENDING"
    ? "pending"
    : status === "APPROVED"
      ? "success"
      : status === "REJECTED"
        ? "danger"
        : "draft";
}

/** Compact scan view; the complete workflow and submitted evidence remain in the review sheet. */
export function ApprovalRequestCard({
  request,
  onOpen,
}: {
  request: ApprovalRequestResource;
  onOpen: () => void;
}) {
  const summary = approvalSummary(request);
  const title = summary.number ?? `#${request.subject_id}`;
  const step =
    request.steps.find((item) => item.id === Number(request.my_step_id)) ??
    request.steps.find((item) => item.status === "OPEN");
  const completed = request.steps.filter(
    (item) => item.status === "APPROVED",
  ).length;
  return (
    <article className="approval-card" aria-label={`Approval of ${title}`}>
      <div className="approval-card-heading flex flex-wrap items-start justify-between gap-3">
        <div className="flex min-w-0 items-center gap-3">
          <div className="approval-card-icon">
            <FileText className="size-4" aria-hidden="true" />
          </div>
          <div className="min-w-0">
            <h2 className="text-sm font-bold">{title}</h2>
            <p className="mt-0.5 text-xs text-muted-foreground">
              {subjectLabel(request.subject_type)}
            </p>
          </div>
        </div>
        <div className="flex flex-wrap gap-1">
          <StatusBadge tone={requestTone(request.status)}>
            {REQUEST_STATUS_LABELS[request.status] ?? request.status}
          </StatusBadge>
          {isOverdue(request) && (
            <StatusBadge tone="danger">
              <Clock className="mr-1 size-3" aria-hidden="true" />
              Overdue
            </StatusBadge>
          )}
        </div>
      </div>
      <div className="approval-card-facts flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <h3 className="text-sm font-semibold break-words">
            {summary.supplier ?? subjectLabel(request.subject_type)}
          </h3>
          <p
            className="approval-card-policy mt-1 text-xs text-muted-foreground"
            title={`${request.policy.name} · Version ${request.policy.version_no}`}
          >
            {request.policy.name} · v{request.policy.version_no}
          </p>
        </div>
        {request.amount_base != null && (
          <div className="approval-card-amount text-right">
            <p className="text-xs text-muted-foreground">
              Amount · base currency
            </p>
            <p className="mt-1 text-base font-bold tabular-nums">
              {readableApprovalAmount(String(request.amount_base))}
            </p>
          </div>
        )}
      </div>
      <div className="approval-card-stage">
        <p className="min-w-0 text-xs">
          <span className="font-semibold">
            {step?.name ??
              (request.status === "APPROVED" ? "Complete" : "No open step")}
          </span>
          {request.can_decide && (
            <span className="ml-2 text-primary-soft-foreground">
              Your decision
            </span>
          )}
        </p>
        <span className="shrink-0 text-xs text-muted-foreground">
          {completed}/{request.steps.length} steps
        </span>
      </div>
      {step?.deadline_at && (
        <p className="text-xs text-muted-foreground">
          Due {formatDateTime(step.deadline_at)}
        </p>
      )}
      <div className="approval-card-footer flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3">
        <div className="text-xs">
          <p className="font-medium">{request.submitted_by.name}</p>
          <p className="mt-0.5 text-muted-foreground">
            {formatDateTime(request.submitted_at)}
          </p>
        </div>
        <Button
          type="button"
          size="sm"
          variant="link"
          className="approval-review-button px-0"
          data-approval-open={request.id}
          onClick={onOpen}
        >
          {request.can_decide ? "Review & decide" : "View details"}
          <ArrowRight className="size-3.5" aria-hidden="true" />
        </Button>
      </div>
    </article>
  );
}
