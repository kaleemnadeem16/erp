import { useState } from 'react';
import { useApprovalRequestsShow, usePurchaseOrdersSubmit } from '@erp/core-api';
import { AlertTriangle, ShieldCheck } from 'lucide-react';
import { Button } from '../../components/ui/button';
import { getApiErrorMessage } from '../../lib/api-errors';
import { ApprovalRequestPanel } from '../approvals/approval-request-panel';
import { REQUEST_STATUS_LABELS } from '../approvals/approval-labels';

/** The approval block the purchase-order API returns on the screens that act on one order (ADR 0070). */
export interface PurchaseOrderApproval {
  mode: string;
  required: boolean;
  status: string;
  request_id: number | null;
  changed_since_approval: boolean;
}

interface Props {
  orderId: number;
  approval: PurchaseOrderApproval | undefined;
  isDraft: boolean;
  maySubmit: boolean;
  onChanged: () => void;
}

/**
 * Where an order stands with approval, and the one thing to do next: submit it, wait, or — if the earlier approval no longer
 * matches the order — submit again. Nothing is shown to a company that has approvals switched off.
 */
export function PurchaseOrderApprovalSection({ orderId, approval, isDraft, maySubmit, onChanged }: Props) {
  const submit = usePurchaseOrdersSubmit();
  const [error, setError] = useState<string | null>(null);
  const request = useApprovalRequestsShow(approval?.request_id ?? 0, { query: { enabled: !!approval?.request_id, retry: false } });
  const detail = request.data?.status === 200 ? request.data.data.data : undefined;

  if (!approval || approval.mode === 'NONE') return null;
  const status = approval.status;
  if (!isDraft && !approval.request_id) return null;

  function send() {
    setError(null);
    submit.mutate({ purchaseOrder: orderId }, { onSuccess: onChanged, onError: (e) => setError(getApiErrorMessage(e) ?? 'Could not submit this for approval.') });
  }

  const needsSubmit = isDraft && approval.required && ['NOT_SUBMITTED', 'REJECTED', 'WITHDRAWN', 'INVALIDATED', 'EXPIRED'].includes(status);

  return (
    <section className="space-y-3 rounded-md border p-4" aria-label="Approval">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 font-semibold"><ShieldCheck className="size-4" aria-hidden="true" />Approval</h2>
        <span className="text-sm text-muted-foreground">{status === 'NOT_SUBMITTED' ? 'Not submitted' : status === 'NOT_REQUIRED' ? 'Not required' : (REQUEST_STATUS_LABELS[status] ?? status)}</span>
      </div>
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}

      {status === 'NOT_REQUIRED' && <p className="text-sm text-muted-foreground">No approval policy applies to this order, so it can be issued directly.</p>}
      {needsSubmit && (
        <div className="space-y-2 text-sm">
          <p>
            {status === 'NOT_SUBMITTED' ? 'This order needs approval before it can be issued.' : approval.changed_since_approval || status === 'INVALIDATED'
              ? <><AlertTriangle className="mr-1 inline size-4" aria-hidden="true" />The order changed after it was approved, so it needs approval again.</>
              : `The last approval was ${REQUEST_STATUS_LABELS[status]?.toLowerCase() ?? status.toLowerCase()}. Submit it again once it is ready.`}
          </p>
          {maySubmit
            ? <Button type="button" onClick={send} disabled={submit.isPending}>{submit.isPending ? 'Submitting…' : 'Submit for approval'}</Button>
            : <p className="text-muted-foreground">You do not have permission to submit orders for approval.</p>}
        </div>
      )}
      {detail && <ApprovalRequestPanel request={detail} onChanged={onChanged} mayWithdraw={maySubmit && isDraft} />}
    </section>
  );
}
