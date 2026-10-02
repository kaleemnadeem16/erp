import { useState } from 'react';
import {
  useApprovalPoliciesActivate,
  useApprovalPoliciesDestroy,
  useApprovalPoliciesIndex,
  type ApprovalPolicyResource,
  type ApprovalSubjectTypesIndex200DataItem,
} from '@erp/core-api';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { ListError } from '../../components/data-table';
import { getApiErrorMessage } from '../../lib/api-errors';
import { formatDate } from '../../lib/format-date';
import { ApprovalPolicyEditor } from './approval-policy-editor';
import { POLICY_STATUS_LABELS } from './approval-labels';

type Editing = { kind: 'new' } | { kind: 'draft'; policy: ApprovalPolicyResource } | { kind: 'version'; policy: ApprovalPolicyResource } | null;

function conditionSentence(condition: ApprovalPolicyResource['conditions'][number]): string {
  const value = Array.isArray(condition.value) ? (condition.value as string[]).join(', ') : String(condition.value);
  return `${condition.fact_label} ${condition.operator_label} ${value}`;
}

function stepSentence(step: ApprovalPolicyResource['steps'][number]): string {
  const bits = [`${step.required_approvals} ${step.required_approvals === 1 ? 'approval' : 'approvals'}`];
  if (step.use_authority_limit) bits.push('within each approver’s limit');
  if (step.expires_after_hours) bits.push(`${step.expires_after_hours} h time limit`);
  return `Stage ${step.stage}: ${step.name} — ${bits.join(', ')}`;
}

/**
 * The company's approval policies (ADR 0070): versions of named rule sets. A draft can be edited, activated or discarded;
 * an active version is never edited — a new version is started from it, which supersedes it only when activated, so a
 * request routed under the old version stays explainable.
 */
export function PoliciesPanel({ subjectTypes, mayManage }: { subjectTypes: ApprovalSubjectTypesIndex200DataItem[]; mayManage: boolean }) {
  const query = useApprovalPoliciesIndex({ per_page: 100 });
  const rows = query.data?.status === 200 ? query.data.data.data : [];
  const activate = useApprovalPoliciesActivate();
  const destroy = useApprovalPoliciesDestroy();
  const [editing, setEditing] = useState<Editing>(null);
  const [error, setError] = useState<string | null>(null);
  const done = () => { setEditing(null); setError(null); void query.refetch(); };
  const fail = (e: unknown) => setError(getApiErrorMessage(e) ?? 'That did not work.');
  const labelOf = (code: string) => subjectTypes.find((s) => s.code === code)?.label ?? code;

  return (
    <section className="space-y-3 rounded-lg border p-4" aria-labelledby="policies-heading">
      <div>
        <h2 id="policies-heading" className="font-semibold">Approval policies</h2>
        <p className="text-sm text-muted-foreground">Who must approve what. An active version is never edited: start a new version and activate it when it is ready.</p>
      </div>
      {error && <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}
      {query.isError ? (
        <ListError message="Couldn't load the policies." error={query.error} onRetry={() => void query.refetch()} />
      ) : query.isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : rows.length === 0 ? (
        <p className="text-sm text-muted-foreground">No policies yet. Until you add one, nothing is sent for approval.</p>
      ) : (
        <ul className="space-y-3">
          {rows.map((policy) => (
            <li key={policy.id} className="space-y-2 rounded-md border p-3 text-sm" aria-label={`${policy.name} version ${policy.version_no}`}>
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <span className="font-medium">{policy.name}</span> <span className="text-xs text-muted-foreground">({policy.code} · v{policy.version_no} · {labelOf(policy.subject_type)} · priority {policy.priority})</span>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant={policy.status === 'ACTIVE' ? 'success' : policy.status === 'DRAFT' ? 'warning' : 'outline'}>{POLICY_STATUS_LABELS[policy.status] ?? policy.status}</Badge>
                  {mayManage && policy.status === 'DRAFT' && (
                    <>
                      <Button type="button" size="sm" variant="outline" aria-label={`Edit draft ${policy.name}`} onClick={() => setEditing({ kind: 'draft', policy })}>Edit</Button>
                      <Button type="button" size="sm" aria-label={`Activate ${policy.name} version ${policy.version_no}`} disabled={activate.isPending} onClick={() => activate.mutate({ approvalPolicy: policy.id }, { onSuccess: done, onError: fail })}>Activate</Button>
                      <Button type="button" size="sm" variant="ghost" aria-label={`Discard draft ${policy.name}`} disabled={destroy.isPending} onClick={() => destroy.mutate({ approvalPolicy: policy.id }, { onSuccess: done, onError: fail })}>Discard</Button>
                    </>
                  )}
                  {mayManage && policy.status === 'ACTIVE' && (
                    <Button type="button" size="sm" variant="outline" aria-label={`Start a new version of ${policy.name}`} onClick={() => setEditing({ kind: 'version', policy })}>New version</Button>
                  )}
                </div>
              </div>
              <p className="text-muted-foreground">
                {policy.conditions.length === 0 ? 'Applies to every document of this kind.' : `Applies when: ${policy.conditions.map(conditionSentence).join(' and ')}.`}
              </p>
              <ol className="list-decimal space-y-0.5 pl-5 text-muted-foreground">{policy.steps.map((step) => <li key={step.id}>{stepSentence(step)}</li>)}</ol>
              {policy.effective_from && <p className="text-xs text-muted-foreground">In force from {formatDate(policy.effective_from)}{policy.effective_to ? ` to ${formatDate(policy.effective_to)}` : ''} · {policy.reason}</p>}
            </li>
          ))}
        </ul>
      )}
      {mayManage && (editing === null ? (
        <Button type="button" variant="outline" onClick={() => setEditing({ kind: 'new' })}>Add a policy</Button>
      ) : (
        <ApprovalPolicyEditor
          key={editing.kind === 'new' ? 'new' : `${editing.kind}-${editing.policy.id}`}
          subjectTypes={subjectTypes}
          draft={editing.kind === 'draft' ? editing.policy : undefined}
          basedOn={editing.kind === 'version' ? editing.policy : undefined}
          onDone={done}
          onCancel={() => setEditing(null)}
        />
      ))}
    </section>
  );
}
