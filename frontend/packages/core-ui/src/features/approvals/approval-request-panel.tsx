import { ApprovalDocumentReview } from './approval-document-review';
import { useState } from 'react';
import { useApprovalRequestsDecide, useApprovalRequestsWithdraw, type ApprovalRequestResource } from '@erp/core-api';
import { AlertTriangle, Check, Clock, X } from 'lucide-react';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Textarea } from '../../components/ui/textarea';
import { formatDateTime } from '../../lib/format-date';
import { getApiErrorMessage } from '../../lib/api-errors';
import { REQUEST_STATUS_LABELS, REQUEST_STATUS_VARIANT, STEP_STATUS_LABELS, trimZeros, readableApprovalAmount } from './approval-labels';

interface Evidence {
  original_amount?: string;
  currency_code?: string;
  fx_rate?: string;
  fx_rate_date?: string;
  fx_rate_source?: string;
  base_amount?: string;
}

interface ApprovalRequestPanelProps {
  request: ApprovalRequestResource;
  /** Called after a decision or a withdrawal so the caller can refetch whatever it shows. */
  onChanged?: () => void;
  /** The submitter (or a manager) may take a pending request back. */
  mayWithdraw?: boolean;
  /** The owning document already supplies identity and the outer card. */
  embedded?: boolean;
}

/**
 * One approval in full (ADR 0070): what is being approved and for how much — with the pinned currency evidence the amount
 * was routed on — the steps and who decided each, and, for someone who can decide right now, the decision form. A rejection
 * needs a reason. Deciding as a delegate is shown as such; nobody is ever shown a button they cannot use.
 */
export function ApprovalRequestPanel({
  request,
  onChanged,
  mayWithdraw = false,
  embedded = false,
}: ApprovalRequestPanelProps) {
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const decide = useApprovalRequestsDecide();
  const withdraw = useApprovalRequestsWithdraw();
  const summary = request.summary as unknown as Record<string, string | undefined>;
  const evidence = (request.evidence ?? {}) as Evidence;
  const foreign =
    evidence.currency_code !== undefined &&
    evidence.original_amount !== undefined &&
    evidence.original_amount !== evidence.base_amount;

  function submitDecision(decision: 'APPROVED' | 'REJECTED') {
    if (request.document_review_required && !request.document_review?.available) {
      setError('The submitted document must be available before you can decide.');
      return;
    }
    if (decision === 'REJECTED' && reason.trim() === '') {
      setError('Say why you are rejecting this.');
      return;
    }
    setError(null);
    decide.mutate(
      {
        approvalRequest: request.id,
        data: {
          decision,
          reason: reason.trim() === '' ? null : reason.trim(),
          step_id: request.my_step_id == null ? undefined : Number(request.my_step_id),
        },
      },
      {
        onSuccess: () => {
          setReason('');
          onChanged?.();
        },
        onError: (e) => setError(getApiErrorMessage(e) ?? 'Could not record your decision.'),
      },
    );
  }

  function takeBack() {
    if (reason.trim() === '') {
      setError('Say why you are withdrawing this.');
      return;
    }
    setError(null);
    withdraw.mutate(
      { approvalRequest: request.id, data: { reason: reason.trim() } },
      {
        onSuccess: () => {
          setReason('');
          onChanged?.();
        },
        onError: (e) => setError(getApiErrorMessage(e) ?? 'Could not withdraw this.'),
      },
    );
  }

  return (
    <section
      className={embedded ? 'space-y-3' : 'space-y-3 rounded-lg border p-4'}
      aria-label={`Approval of ${summary.number ?? `#${request.subject_id}`}`}
    >
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          {!embedded && (
            <h3 className="font-semibold">
              {summary.number ?? `#${request.subject_id}`}
              {summary.supplier ? ` — ${summary.supplier}` : ''}
            </h3>
          )}
          <p className="text-sm text-muted-foreground">
            Submitted by {request.submitted_by.name} on {formatDateTime(request.submitted_at)} · policy{' '}
            {request.policy.name} (v{request.policy.version_no})
          </p>
        </div>
        <Badge variant={REQUEST_STATUS_VARIANT[request.status] ?? 'outline'}>
          {REQUEST_STATUS_LABELS[request.status] ?? request.status}
        </Badge>
      </div>

      {request.amount_base !== null && request.amount_base !== undefined && (
        <div className="text-sm">
          <span className="text-muted-foreground">Amount routed (base currency): </span>
          <span className="font-medium">{readableApprovalAmount(String(request.amount_base))}</span>
          {foreign && (
            <p className="text-xs text-muted-foreground">
              {trimZeros(evidence.original_amount)} {evidence.currency_code} at {trimZeros(evidence.fx_rate)} on{' '}
              {evidence.fx_rate_date} ({evidence.fx_rate_source}) — fixed when it was submitted, so the amount does not
              move with the rate.
            </p>
          )}
        </div>
      )}

      {request.evidence_visible === false && (
        <p className="text-sm text-muted-foreground" data-testid="approval-amount-hidden">
          The amount is not shown: it comes from figures you do not have access to. The approval's status and decisions
          are.
        </p>
      )}

      <ApprovalDocumentReview key={request.id} request={request} />
      <ol className="space-y-2">
        {request.steps.map((step) => (
          <li key={step.id} className="rounded-md border p-3 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="font-medium">{step.name}</span>
              <span className="flex flex-wrap items-center gap-2">
                <span className="text-muted-foreground">
                  {step.approved_count} of {step.required_approvals} approvals
                </span>
                <Badge variant="outline">{STEP_STATUS_LABELS[step.status] ?? step.status}</Badge>
                {step.overdue && (
                  <Badge variant="warning">
                    <Clock className="mr-1 size-3" aria-hidden="true" />
                    Overdue
                  </Badge>
                )}
              </span>
            </div>
            {step.deadline_at && step.status === 'OPEN' && (
              <p className="mt-2 text-xs text-muted-foreground">Due {formatDateTime(step.deadline_at)}</p>
            )}
            {step.decisions.length > 0 && (
              <ul className="mt-2 space-y-1 text-muted-foreground">
                {step.decisions.map((d) => (
                  <li key={d.id}>
                    {d.decision === 'APPROVED' ? (
                      <Check className="mr-1 inline size-4" aria-hidden="true" />
                    ) : (
                      <X className="mr-1 inline size-4" aria-hidden="true" />
                    )}
                    {d.decision === 'APPROVED' ? 'Approved' : 'Rejected'} by {d.decided_by.name}
                    {d.acting_for !== null ? ' (acting as a delegate)' : ''} on {formatDateTime(d.decided_at)}
                    {d.reason ? ` — “${d.reason}”` : ''}
                    {d.reason_visible === false && <span className="italic"> — reason not shown to you</span>}
                  </li>
                ))}
              </ul>
            )}
          </li>
        ))}
      </ol>

      {request.closing_reason && request.status !== 'APPROVED' && (
        <p className="text-sm text-muted-foreground">
          <AlertTriangle className="mr-1 inline size-4" aria-hidden="true" />
          {request.closing_reason}
        </p>
      )}
      {request.closing_reason_visible === false && (
        <p className="text-sm italic text-muted-foreground">
          <AlertTriangle className="mr-1 inline size-4" aria-hidden="true" />
          The reason this request was closed is not shown to you.
        </p>
      )}

      {(request.can_decide || mayWithdraw) && request.status === 'PENDING' && (
        <div className="space-y-2 border-t pt-3">
          {error && (
            <p role="alert" className="text-sm text-destructive">
              {error}
            </p>
          )}
          <label className="block text-sm font-medium" htmlFor={`reason-${request.id}`}>
            Reason {request.can_decide ? '(required to reject)' : '(required to withdraw)'}
          </label>
          <Textarea
            id={`reason-${request.id}`}
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            rows={2}
          />
          <div className="flex flex-wrap justify-end gap-2">
            {mayWithdraw && (
              <Button type="button" variant="ghost" onClick={takeBack} disabled={withdraw.isPending}>
                Withdraw
              </Button>
            )}
            {request.can_decide && (
              <>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => submitDecision('REJECTED')}
                  disabled={
                    decide.isPending || (request.document_review_required && !request.document_review?.available)
                  }
                >
                  Reject
                </Button>
                <Button
                  type="button"
                  onClick={() => submitDecision('APPROVED')}
                  disabled={
                    decide.isPending || (request.document_review_required && !request.document_review?.available)
                  }
                >
                  Approve
                </Button>
              </>
            )}
          </div>
        </div>
      )}
    </section>
  );
}
