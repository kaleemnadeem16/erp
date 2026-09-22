import { useState } from 'react';
import { useParams, useNavigate } from 'react-router';
import {
  useNegativeStockOverrideRequestsShow,
  useNegativeStockOverrideRequestsDecide,
  useNegativeStockOverrideRequestsReview,
  NegativeStockApprovalDecision,
  NegativeStockReviewOutcome,
} from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Textarea } from '../../components/ui/textarea';
import { Badge } from '../../components/ui/badge';
import { Skeleton } from '../../components/ui/skeleton';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { PageHeader } from '../../components/page/page-header';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { DetailField } from '../../components/detail/detail-field';
import { getApiErrorMessage } from '../../lib/api-errors';
import { formatDateTime } from '../../lib/format-date';
import { overrideStatus } from './negative-stock-override-columns';
import { useAuthStore } from '../../stores/auth-store';

/**
 * The workspace for one override request: header/shortage evidence, plus
 * whichever of Decide (PRIOR_APPROVAL) or Review (IMMEDIATE_WITH_REVIEW)
 * applies to this request's authorisation_mode — never both, since a
 * request only ever receives the one its mode was snapshotted under. Every
 * mutation refetches rather than patching local state, matching physical
 * counts and inventory cutover.
 */
export function NegativeStockOverrideDetailScreen() {
  const params = useParams<{ id: string }>();
  const navigate = useNavigate();
  const overrideId = Number(params.id);

  const overrideQuery = useNegativeStockOverrideRequestsShow(overrideId, { include: 'item,location,batch,approval,review' });
  const override = overrideQuery.data?.status === 200 ? overrideQuery.data.data.data : undefined;

  const currentUser = useAuthStore((state) => state.user);

  const [actionError, setActionError] = useState<string | null>(null);
  const [decision, setDecision] = useState('');
  const [outcome, setOutcome] = useState('');
  const [notes, setNotes] = useState('');

  const decide = useNegativeStockOverrideRequestsDecide();
  const review = useNegativeStockOverrideRequestsReview();

  function refetch() {
    overrideQuery.refetch();
  }

  function handleDecide() {
    if (!decision || !notes.trim()) return;
    setActionError(null);
    decide.mutate(
      { negativeStockOverrideRequest: overrideId, data: { decision: decision as NegativeStockApprovalDecision, notes } },
      {
        onSuccess: () => {
          setNotes('');
          setDecision('');
          refetch();
        },
        onError: (error) => setActionError(getApiErrorMessage(error)),
      }
    );
  }

  function handleReview() {
    if (!outcome || !notes.trim()) return;
    setActionError(null);
    review.mutate(
      { negativeStockOverrideRequest: overrideId, data: { outcome: outcome as NegativeStockReviewOutcome, notes } },
      {
        onSuccess: () => {
          setNotes('');
          setOutcome('');
          refetch();
        },
        onError: (error) => setActionError(getApiErrorMessage(error)),
      }
    );
  }

  if (overrideQuery.isLoading) {
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  if (overrideQuery.isError || !override) {
    return (
      <div className="space-y-4 p-6">
        <p className="text-sm text-destructive">Couldn't load this negative-stock override request.</p>
        <Button variant="outline" size="sm" onClick={() => navigate('/negative-stock-overrides')}>
          Back to negative-stock overrides
        </Button>
      </div>
    );
  }

  const status = overrideStatus(override);
  const needsDecision = override.authorisation_mode === 'PRIOR_APPROVAL' && !override.approval;
  const needsReview = override.authorisation_mode === 'IMMEDIATE_WITH_REVIEW' && !override.review;
  const isOwnRequest = !!currentUser && currentUser.id === override.requested_by;
  // Decide has no self-review escape hatch; Review does, via allow_self_review.
  const cannotDecide = isOwnRequest;
  const cannotReview = isOwnRequest && !override.allow_self_review;

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={`Negative-stock override #${override.id}`}
        description={override.item ? `${override.item.code} — ${override.item.name}` : undefined}
        breadcrumbs={[{ label: 'Negative-Stock Overrides', to: '/negative-stock-overrides' }, { label: `#${override.id}` }]}
      />

      {actionError && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{actionError}</div>
      )}

      <DetailPageLayout>
        <DetailSection title="Shortage">
          <DetailField label="Status">
            <Badge variant={status.variant}>{status.label}</Badge>
          </DetailField>
          <DetailField label="Movement class">{override.movement_class}</DetailField>
          <DetailField label="Item">{override.item ? `${override.item.code} — ${override.item.name}` : override.item_id}</DetailField>
          <DetailField label="Location">{override.location?.code ?? override.location_id}</DetailField>
          <DetailField label="Requested / available">{`${override.requested_qty} / ${override.available_qty}`}</DetailField>
          <DetailField label="Shortage quantity">{override.shortage_qty}</DetailField>
          <DetailField label="Reason">{override.reason_code}</DetailField>
          <DetailField label="Authorisation mode">{override.authorisation_mode}</DetailField>
          <DetailField label="Requested">{formatDateTime(override.requested_at)}</DetailField>
          <DetailField label="Review due">{formatDateTime(override.review_due_at)}</DetailField>
          <DetailField label="Source" fullWidth>{`${override.source_type} #${override.source_id}`}</DetailField>
          <DetailField label="Explanation" fullWidth>{override.explanation}</DetailField>
        </DetailSection>

        {override.approval && (
          <DetailSection title="Approval decision">
            <DetailField label="Decision">
              <Badge variant={override.approval.decision === 'APPROVED' ? 'success' : 'destructive'}>{override.approval.decision}</Badge>
            </DetailField>
            <DetailField label="Decided by">{override.approval.decided_by}</DetailField>
            <DetailField label="Decided at">{formatDateTime(override.approval.decided_at)}</DetailField>
            <DetailField label="Notes" fullWidth>{override.approval.notes}</DetailField>
          </DetailSection>
        )}

        {override.review && (
          <DetailSection title="Review outcome">
            <DetailField label="Outcome">
              <Badge variant={override.review.outcome === 'JUSTIFIED' ? 'success' : 'destructive'}>{override.review.outcome}</Badge>
            </DetailField>
            <DetailField label="Reviewed by">{override.review.reviewed_by}</DetailField>
            <DetailField label="Reviewed at">{formatDateTime(override.review.reviewed_at)}</DetailField>
            <DetailField label="Notes" fullWidth>{override.review.notes}</DetailField>
          </DetailSection>
        )}

        {needsDecision && (
          <DetailSection title="Decide" description="Approval must come from a different actor than the request.">
            <div className="flex flex-col gap-1">
              <span className="text-xs font-medium text-muted-foreground">Decision</span>
              <Select value={decision} onValueChange={setDecision}>
                <SelectTrigger className="h-9">
                  <SelectValue placeholder="Select a decision" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="APPROVED">Approve</SelectItem>
                  <SelectItem value="REJECTED">Reject</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="flex flex-col gap-1 sm:col-span-2">
              <span className="text-xs font-medium text-muted-foreground">Notes</span>
              <Textarea value={notes} onChange={(event) => setNotes(event.target.value)} />
            </div>
            <div className="flex flex-col items-end gap-1 sm:col-span-2">
              <Button
                onClick={handleDecide}
                disabled={!decision || !notes.trim() || decide.isPending || cannotDecide}
                title={cannotDecide ? "You can't decide your own request" : undefined}
              >
                {decide.isPending ? 'Submitting…' : 'Submit decision'}
              </Button>
              {cannotDecide && (
                <span className="text-xs text-muted-foreground">You can't decide your own request</span>
              )}
            </div>
          </DetailSection>
        )}

        {needsReview && (
          <DetailSection title="Review" description="Independent review must come from a different actor than the request unless self-review is allowed by policy.">
            <div className="flex flex-col gap-1">
              <span className="text-xs font-medium text-muted-foreground">Outcome</span>
              <Select value={outcome} onValueChange={setOutcome}>
                <SelectTrigger className="h-9">
                  <SelectValue placeholder="Select an outcome" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="JUSTIFIED">Justified</SelectItem>
                  <SelectItem value="FLAGGED">Flagged</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="flex flex-col gap-1 sm:col-span-2">
              <span className="text-xs font-medium text-muted-foreground">Notes</span>
              <Textarea value={notes} onChange={(event) => setNotes(event.target.value)} />
            </div>
            <div className="flex flex-col items-end gap-1 sm:col-span-2">
              <Button
                onClick={handleReview}
                disabled={!outcome || !notes.trim() || review.isPending || cannotReview}
                title={cannotReview ? "You can't review your own request" : undefined}
              >
                {review.isPending ? 'Submitting…' : 'Submit review'}
              </Button>
              {cannotReview && (
                <span className="text-xs text-muted-foreground">You can't review your own request</span>
              )}
            </div>
          </DetailSection>
        )}
      </DetailPageLayout>
    </div>
  );
}
