import { useState } from 'react';
import { useParams, useNavigate } from 'react-router';
import {
  useRecalculationRequestsShow,
  useRecalculationImpactVersionsDecide,
  useRecalculationImpactVersionsExecute,
  useRecalculationImpactVersionsResumeRegeneration,
  RecalculationApprovalDecision,
} from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Textarea } from '../../components/ui/textarea';
import { Badge } from '../../components/ui/badge';
import { Skeleton } from '../../components/ui/skeleton';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table';
import { PageHeader } from '../../components/page/page-header';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { DetailField } from '../../components/detail/detail-field';
import { getApiErrorMessage } from '../../lib/api-errors';
import { currentVersion, latestVersion, recalculationStatus } from './recalculation-request-columns';
import { useAuthStore } from '../../stores/auth-store';

const RISK_VARIANT: Record<string, 'secondary' | 'destructive'> = { NORMAL_RISK: 'secondary', HIGH_RISK: 'destructive' };

/**
 * The workspace for one recalculation request: header, its full impact
 * version history (a request can accumulate several — staleness
 * regenerates a replacement), the current version's approvals, and
 * whichever of Decide/Execute/Resume-regeneration currently applies.
 *
 * Unlike physical counts, inventory cutover and negative-stock overrides,
 * a *failed* decide/execute attempt can still change server state here
 * (revalidation can stale-flip the version before the call ultimately
 * throws) — so every mutation refetches on both success and error, not
 * just success.
 */
export function RecalculationRequestDetailScreen() {
  const params = useParams<{ id: string }>();
  const navigate = useNavigate();
  const requestId = Number(params.id);

  const requestQuery = useRecalculationRequestsShow(requestId, { include: 'item,location,destinationLocation,batch' });
  const request = requestQuery.data?.status === 200 ? requestQuery.data.data.data : undefined;

  const currentUser = useAuthStore((state) => state.user);

  const [actionError, setActionError] = useState<string | null>(null);
  const [decision, setDecision] = useState('');
  const [decideNotes, setDecideNotes] = useState('');
  const [resumeNotes, setResumeNotes] = useState('');

  const decide = useRecalculationImpactVersionsDecide();
  const execute = useRecalculationImpactVersionsExecute();
  const resume = useRecalculationImpactVersionsResumeRegeneration();

  function refetch() {
    requestQuery.refetch();
  }

  function handleDecide(versionId: number) {
    if (!decision || !decideNotes.trim()) return;
    setActionError(null);
    decide.mutate(
      { recalculationImpactVersion: versionId, data: { decision: decision as RecalculationApprovalDecision, notes: decideNotes } },
      {
        onSuccess: () => {
          setDecision('');
          setDecideNotes('');
          refetch();
        },
        onError: (error) => {
          setActionError(getApiErrorMessage(error));
          refetch();
        },
      }
    );
  }

  function handleExecute(versionId: number) {
    setActionError(null);
    execute.mutate(
      { recalculationImpactVersion: versionId },
      {
        onSuccess: refetch,
        onError: (error) => {
          setActionError(getApiErrorMessage(error));
          refetch();
        },
      }
    );
  }

  function handleResume(versionId: number) {
    if (!resumeNotes.trim()) return;
    setActionError(null);
    resume.mutate(
      { recalculationImpactVersion: versionId, data: { notes: resumeNotes } },
      {
        onSuccess: () => {
          setResumeNotes('');
          refetch();
        },
        onError: (error) => {
          setActionError(getApiErrorMessage(error));
          refetch();
        },
      }
    );
  }

  if (requestQuery.isLoading) {
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  if (requestQuery.isError || !request) {
    return (
      <div className="space-y-4 p-6">
        <p className="text-sm text-destructive">Couldn't load this stock recalculation request.</p>
        <Button variant="outline" size="sm" onClick={() => navigate('/recalculation-requests')}>
          Back to stock recalculation
        </Button>
      </div>
    );
  }

  const versions = [...(request.impact_versions ?? [])].sort((a, b) => b.version_no - a.version_no);
  const status = recalculationStatus(request);
  const latest = latestVersion(request);
  const current = currentVersion(request);
  const paused = !latest?.execution && !latest?.is_rejected && !current;
  const isOwnRequest = !!currentUser && currentUser.id === request.requested_by;

  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={`Recalculation request #${request.id}`}
        description={request.item ? `${request.item.code} — ${request.item.name}` : undefined}
        breadcrumbs={[{ label: 'Stock Recalculation', to: '/recalculation-requests' }, { label: `#${request.id}` }]}
      />

      {actionError && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{actionError}</div>
      )}

      <DetailPageLayout>
        <DetailSection title="Movement">
          <DetailField label="Status">
            <Badge variant={status.variant}>{status.label}</Badge>
          </DetailField>
          <DetailField label="Movement kind">{request.movement_kind}</DetailField>
          <DetailField label="Location">{request.location?.code ?? request.location_id}</DetailField>
          <DetailField label="Destination">{request.destination_location?.code}</DetailField>
          <DetailField label="Quantity">{request.qty}</DetailField>
          <DetailField label="True historical date">{new Date(request.moved_at).toLocaleString()}</DetailField>
          <DetailField label="Horizon mode">{request.horizon_mode}</DetailField>
          <DetailField label="Review due">{new Date(request.review_due_at).toLocaleString()}</DetailField>
          <DetailField label="Source" fullWidth>{`${request.source_type} #${request.source_id}`}</DetailField>
        </DetailSection>

        <DetailSection title="Impact versions" description="A version is replaced (never edited) whenever the ledger changes underneath an in-review request." fullWidth>
          <div className="overflow-x-auto rounded-md border border-border sm:col-span-2">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Version</TableHead>
                  <TableHead>Risk</TableHead>
                  <TableHead>Stale</TableHead>
                  <TableHead className="text-right">Original qty / value</TableHead>
                  <TableHead className="text-right">Counterfactual qty / value</TableHead>
                  <TableHead className="text-right">Valuation adjustment</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {versions.map((version) => (
                  <TableRow key={version.id}>
                    <TableCell>#{version.version_no}</TableCell>
                    <TableCell>
                      <Badge variant={RISK_VARIANT[version.risk_classification] ?? 'secondary'}>{version.risk_classification}</Badge>
                    </TableCell>
                    <TableCell>{version.is_stale ? 'Yes' : 'No'}</TableCell>
                    <TableCell className="text-right tabular-nums">{`${version.original_qty_on_hand} / ${version.original_inventory_value}`}</TableCell>
                    <TableCell className="text-right tabular-nums">{`${version.counterfactual_qty_on_hand} / ${version.counterfactual_inventory_value}`}</TableCell>
                    <TableCell className="text-right tabular-nums">{version.valuation_adjustment}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </DetailSection>

        {current && (
          <DetailSection title={`Approvals — version #${current.version_no}`} description={`${current.required_approval_count} independent approver(s) required.`} fullWidth>
            <div className="overflow-x-auto rounded-md border border-border sm:col-span-2">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Decided by</TableHead>
                    <TableHead>Decision</TableHead>
                    <TableHead>Notes</TableHead>
                    <TableHead>Decided at</TableHead>
                    <TableHead>Expires</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {current.approvals.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={5} className="h-16 text-center text-muted-foreground">
                        No decisions yet.
                      </TableCell>
                    </TableRow>
                  ) : (
                    current.approvals.map((approval) => (
                      <TableRow key={approval.id}>
                        <TableCell>{approval.decided_by}</TableCell>
                        <TableCell>
                          <Badge variant={approval.decision === 'APPROVED' ? 'success' : 'destructive'}>{approval.decision}</Badge>
                        </TableCell>
                        <TableCell>{approval.notes}</TableCell>
                        <TableCell>{new Date(approval.decided_at).toLocaleString()}</TableCell>
                        <TableCell>
                          {approval.expires_at ? (
                            <span className={approval.is_expired ? 'text-destructive' : undefined}>
                              {new Date(approval.expires_at).toLocaleString()}
                              {approval.is_expired ? ' (expired)' : ''}
                            </span>
                          ) : (
                            '—'
                          )}
                        </TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </div>

            {current.execution ? (
              <div className="sm:col-span-2 space-y-1 border-t border-border pt-4 text-sm">
                <p className="font-medium">Executed</p>
                <p className="text-muted-foreground">
                  By user #{current.execution.executed_by} at {new Date(current.execution.executed_at).toLocaleString()} — stock move #
                  {current.execution.stock_move_id}
                </p>
              </div>
            ) : current.has_unexpired_full_approval ? (
              <div className="flex justify-end sm:col-span-2 border-t border-border pt-4">
                <Button onClick={() => handleExecute(current.id)} disabled={execute.isPending}>
                  {execute.isPending ? 'Executing…' : 'Execute'}
                </Button>
              </div>
            ) : !current.is_decided ? (
              <form
                onSubmit={(event) => {
                  event.preventDefault();
                  handleDecide(current.id);
                }}
                className="flex flex-wrap items-end gap-2 border-t border-border pt-4 sm:col-span-2"
              >
                <div className="flex flex-col gap-1">
                  <span className="text-xs font-medium text-muted-foreground">Decision</span>
                  <Select value={decision} onValueChange={setDecision}>
                    <SelectTrigger className="h-9 w-40">
                      <SelectValue placeholder="Select a decision" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="APPROVED">Approve</SelectItem>
                      <SelectItem value="REJECTED">Reject</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="flex flex-1 min-w-64 flex-col gap-1">
                  <span className="text-xs font-medium text-muted-foreground">Notes</span>
                  <Textarea value={decideNotes} onChange={(event) => setDecideNotes(event.target.value)} className="h-9 min-h-9" />
                </div>
                <div className="flex flex-col items-start gap-1">
                  <Button
                    type="submit"
                    disabled={!decision || !decideNotes.trim() || decide.isPending || isOwnRequest}
                    title={isOwnRequest ? "You can't decide your own recalculation request" : undefined}
                  >
                    {decide.isPending ? 'Submitting…' : 'Submit decision'}
                  </Button>
                  {isOwnRequest && (
                    <span className="text-xs text-muted-foreground">You can't decide your own request</span>
                  )}
                </div>
              </form>
            ) : (
              <p className="text-sm text-muted-foreground sm:col-span-2 border-t border-border pt-4">
                Rejected — no further decisions are accepted on this version.
              </p>
            )}
          </DetailSection>
        )}

        {paused && latest && (
          <DetailSection title="Regeneration paused" description="Automatic regeneration hit its cap after repeated ledger changes — manual coordination is required before a replacement version is computed.">
            <form
              onSubmit={(event) => {
                event.preventDefault();
                handleResume(latest.id);
              }}
              className="flex flex-wrap items-end gap-2 sm:col-span-2"
            >
              <div className="flex flex-1 min-w-64 flex-col gap-1">
                <span className="text-xs font-medium text-muted-foreground">Notes</span>
                <Textarea value={resumeNotes} onChange={(event) => setResumeNotes(event.target.value)} />
              </div>
              <Button type="submit" disabled={!resumeNotes.trim() || resume.isPending}>
                {resume.isPending ? 'Submitting…' : 'Resume regeneration'}
              </Button>
            </form>
          </DetailSection>
        )}
      </DetailPageLayout>
    </div>
  );
}
