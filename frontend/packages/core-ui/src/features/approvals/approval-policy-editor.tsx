import { useState } from 'react';
import {
  useApprovalPoliciesStore,
  type ApprovalOperator,
  useApprovalPoliciesUpdate,
  type ApprovalPolicyResource,
  type ApprovalSubjectTypesIndex200DataItem,
} from '@erp/core-api';
import { Plus, Trash2 } from 'lucide-react';
import { Button } from '../../components/ui/button';
import { Checkbox } from '../../components/ui/checkbox';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { getApiErrorMessage } from '../../lib/api-errors';
import { ON_EXPIRY_OPTIONS } from './approval-labels';

interface ConditionDraft { fact: string; operator: string; value: string }
interface StepDraft {
  name: string;
  stage: string;
  required_approvals: string;
  permission: string;
  use_authority_limit: boolean;
  allow_submitter: boolean;
  expires_after_hours: string;
  on_expiry: string;
  escalation_permission: string;
}

const emptyStep = (stage: number): StepDraft => ({
  name: '', stage: String(stage), required_approvals: '1', permission: '', use_authority_limit: false, allow_submitter: false,
  expires_after_hours: '', on_expiry: 'FLAG_OVERDUE', escalation_permission: '',
});

interface ApprovalPolicyEditorProps {
  subjectTypes: ApprovalSubjectTypesIndex200DataItem[];
  /** Editing this draft in place. */
  draft?: ApprovalPolicyResource;
  /** Starting the next version of this policy (same subject and code) from its current content. */
  basedOn?: ApprovalPolicyResource;
  onDone: () => void;
  onCancel: () => void;
}

/**
 * Authors one policy version (ADR 0070): what it applies to (typed conditions over the facts the document kind declares),
 * and the steps — stages run in order, steps sharing a stage run in parallel, each needing N distinct approvers. A new
 * version starts from the one in force; an active version is never edited, so history stays explainable.
 */
export function ApprovalPolicyEditor({ subjectTypes, draft, basedOn, onDone, onCancel }: ApprovalPolicyEditorProps) {
  const source = draft ?? basedOn;
  const store = useApprovalPoliciesStore();
  const update = useApprovalPoliciesUpdate();
  const [subjectType, setSubjectType] = useState(source?.subject_type ?? subjectTypes[0]?.code ?? '');
  const [code, setCode] = useState(source?.code ?? '');
  const [name, setName] = useState(source?.name ?? '');
  const [priority, setPriority] = useState(String(source?.priority ?? 0));
  const [reason, setReason] = useState(draft ? draft.reason : '');
  const [conditions, setConditions] = useState<ConditionDraft[]>(
    (source?.conditions ?? []).map((c) => ({ fact: c.fact, operator: c.operator, value: Array.isArray(c.value) ? (c.value as string[]).join(', ') : String(c.value) })),
  );
  const [steps, setSteps] = useState<StepDraft[]>(
    source && source.steps.length > 0
      ? source.steps.map((s) => ({
          name: s.name, stage: String(s.stage), required_approvals: String(s.required_approvals), permission: s.permission ?? '',
          use_authority_limit: s.use_authority_limit, allow_submitter: s.allow_submitter, expires_after_hours: s.expires_after_hours == null ? '' : String(s.expires_after_hours),
          on_expiry: s.on_expiry, escalation_permission: s.escalation_permission ?? '',
        }))
      : [emptyStep(1)],
  );
  const [error, setError] = useState<string | null>(null);
  const subject = subjectTypes.find((s) => s.code === subjectType);
  const pending = store.isPending || update.isPending;

  const patchCondition = (i: number, patch: Partial<ConditionDraft>) => setConditions((rows) => rows.map((row, k) => (k === i ? { ...row, ...patch } : row)));
  const patchStep = (i: number, patch: Partial<StepDraft>) => setSteps((rows) => rows.map((row, k) => (k === i ? { ...row, ...patch } : row)));

  function payload() {
    return {
      name: name.trim(),
      priority: Number(priority) || 0,
      reason: reason.trim(),
      conditions: conditions.map((c) => {
        return { fact: c.fact, operator: c.operator as ApprovalOperator, // IN carries a list; the generated type only knows the scalar form (the rule is `required`), so it is cast here.
          value: (c.operator === 'IN' ? c.value.split(',').map((v) => v.trim()).filter(Boolean) : c.value.trim()) as unknown as string };
      }),
      steps: steps.map((s) => ({
        name: s.name.trim(), stage: Number(s.stage) || 1, required_approvals: Number(s.required_approvals) || 1,
        permission: s.permission.trim() === '' ? null : s.permission.trim(), use_authority_limit: s.use_authority_limit, allow_submitter: s.allow_submitter,
        expires_after_hours: s.expires_after_hours.trim() === '' ? null : Number(s.expires_after_hours), on_expiry: s.on_expiry as 'FLAG_OVERDUE' | 'EXPIRE_REQUEST',
        escalation_permission: s.escalation_permission.trim() === '' ? null : s.escalation_permission.trim(),
      })),
    };
  }

  function submit() {
    setError(null);
    const body = payload();
    const fail = (e: unknown) => setError(getApiErrorMessage(e) ?? 'Could not save this policy.');
    if (draft) {
      update.mutate({ approvalPolicy: draft.id, data: body }, { onSuccess: onDone, onError: fail });
    } else {
      store.mutate({ data: { ...body, subject_type: subjectType as never, code: code.trim() } }, { onSuccess: onDone, onError: fail });
    }
  }

  return (
    <form className="space-y-4 rounded-md border bg-muted/30 p-4" aria-label={draft ? 'Edit policy draft' : basedOn ? 'New policy version' : 'New policy'} onSubmit={(event) => { event.preventDefault(); submit(); }}>
      {error && <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-1">
          <Label>Applies to</Label>
          <Select value={subjectType} onValueChange={(value) => { setSubjectType(value); setConditions([]); }} disabled={Boolean(source)}>
            <SelectTrigger aria-label="Applies to"><SelectValue /></SelectTrigger>
            <SelectContent>{subjectTypes.map((s) => <SelectItem key={s.code} value={s.code}>{s.label}</SelectItem>)}</SelectContent>
          </Select>
        </div>
        <div className="space-y-1">
          <Label htmlFor="policy-code">Code</Label>
          <Input id="policy-code" value={code} onChange={(event) => setCode(event.target.value)} disabled={Boolean(source)} placeholder="e.g. large-orders" />
        </div>
        <div className="space-y-1">
          <Label htmlFor="policy-name">Name</Label>
          <Input id="policy-name" value={name} onChange={(event) => setName(event.target.value)} />
        </div>
        <div className="space-y-1">
          <Label htmlFor="policy-priority">Priority</Label>
          <Input id="policy-priority" value={priority} onChange={(event) => setPriority(event.target.value)} inputMode="numeric" />
          <p className="text-xs text-muted-foreground">When several policies match, the highest priority is used.</p>
        </div>
      </div>

      <fieldset className="space-y-2">
        <legend className="text-sm font-medium">When it applies</legend>
        <p className="text-xs text-muted-foreground">All of these must be true. Leave empty for every {subject?.label.toLowerCase() ?? 'document'}.</p>
        {conditions.map((condition, i) => {
          const fact = subject?.facts.find((f) => f.key === condition.fact);
          return (
            <div key={i} className="grid gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]">
              <Select value={condition.fact} onValueChange={(value) => patchCondition(i, { fact: value, operator: subject?.facts.find((f) => f.key === value)?.operators[0]?.value ?? 'EQ' })}>
                <SelectTrigger aria-label={`Condition ${i + 1} fact`}><SelectValue placeholder="Fact" /></SelectTrigger>
                <SelectContent>{subject?.facts.map((f) => <SelectItem key={f.key} value={f.key}>{f.label}</SelectItem>)}</SelectContent>
              </Select>
              <Select value={condition.operator} onValueChange={(value) => patchCondition(i, { operator: value })}>
                <SelectTrigger aria-label={`Condition ${i + 1} comparison`}><SelectValue /></SelectTrigger>
                <SelectContent>{(fact?.operators ?? []).map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}</SelectContent>
              </Select>
              <Input aria-label={`Condition ${i + 1} value`} value={condition.value} onChange={(event) => patchCondition(i, { value: event.target.value })} placeholder={condition.operator === 'IN' ? 'a, b, c' : 'value'} />
              <Button type="button" variant="ghost" size="icon" aria-label={`Remove condition ${i + 1}`} onClick={() => setConditions((rows) => rows.filter((_, k) => k !== i))}><Trash2 className="size-4" /></Button>
            </div>
          );
        })}
        <Button type="button" variant="outline" size="sm" disabled={!subject} onClick={() => setConditions((rows) => [...rows, { fact: subject?.facts[0]?.key ?? '', operator: subject?.facts[0]?.operators[0]?.value ?? 'EQ', value: '' }])}>
          <Plus className="size-4" /> Add a condition
        </Button>
      </fieldset>

      <fieldset className="space-y-3">
        <legend className="text-sm font-medium">Who must approve</legend>
        <p className="text-xs text-muted-foreground">Steps with the same stage number run in parallel; stage 2 opens only when everything in stage 1 is approved.</p>
        {steps.map((step, i) => (
          <div key={i} className="space-y-2 rounded-md border bg-background p-3">
            <div className="grid gap-2 sm:grid-cols-[2fr_1fr_1fr_auto]">
              <Input aria-label={`Step ${i + 1} name`} value={step.name} onChange={(event) => patchStep(i, { name: event.target.value })} placeholder="e.g. Purchasing manager" />
              <Input aria-label={`Step ${i + 1} stage`} value={step.stage} onChange={(event) => patchStep(i, { stage: event.target.value })} inputMode="numeric" placeholder="Stage" />
              <Input aria-label={`Step ${i + 1} approvals needed`} value={step.required_approvals} onChange={(event) => patchStep(i, { required_approvals: event.target.value })} inputMode="numeric" placeholder="Approvals" />
              <Button type="button" variant="ghost" size="icon" aria-label={`Remove step ${i + 1}`} disabled={steps.length === 1} onClick={() => setSteps((rows) => rows.filter((_, k) => k !== i))}><Trash2 className="size-4" /></Button>
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
              <Input aria-label={`Step ${i + 1} approver capability`} value={step.permission} onChange={(event) => patchStep(i, { permission: event.target.value })} placeholder={`Capability (default ${subject?.approve_permission ?? ''})`} />
              <Input aria-label={`Step ${i + 1} time limit in hours`} value={step.expires_after_hours} onChange={(event) => patchStep(i, { expires_after_hours: event.target.value })} inputMode="numeric" placeholder="Time limit in hours (optional)" />
            </div>
            {step.expires_after_hours.trim() !== '' && (
              <div className="grid gap-2 sm:grid-cols-2">
                <Select value={step.on_expiry} onValueChange={(value) => patchStep(i, { on_expiry: value })}>
                  <SelectTrigger aria-label={`Step ${i + 1} on expiry`}><SelectValue /></SelectTrigger>
                  <SelectContent>{ON_EXPIRY_OPTIONS.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}</SelectContent>
                </Select>
                <Input aria-label={`Step ${i + 1} escalation capability`} value={step.escalation_permission} onChange={(event) => patchStep(i, { escalation_permission: event.target.value })} placeholder="Also lets this capability approve once overdue (optional)" />
              </div>
            )}
            <div className="flex flex-wrap gap-6 text-sm">
              <label className="flex items-center gap-2"><Checkbox checked={step.use_authority_limit} onCheckedChange={(checked) => patchStep(i, { use_authority_limit: checked === true })} />Each approver needs an authority ceiling that covers the amount</label>
              <label className="flex items-center gap-2"><Checkbox checked={step.allow_submitter} onCheckedChange={(checked) => patchStep(i, { allow_submitter: checked === true })} />The person who submitted may approve this step</label>
            </div>
          </div>
        ))}
        <Button type="button" variant="outline" size="sm" onClick={() => setSteps((rows) => [...rows, emptyStep(Math.max(...rows.map((r) => Number(r.stage) || 1)) + 1)])}><Plus className="size-4" /> Add a step</Button>
      </fieldset>

      <div className="space-y-1">
        <Label htmlFor="policy-reason">Why is this being set?</Label>
        <Input id="policy-reason" value={reason} onChange={(event) => setReason(event.target.value)} />
      </div>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="ghost" onClick={onCancel}>Cancel</Button>
        <Button type="submit" disabled={pending}>{pending ? 'Saving…' : draft ? 'Save draft' : 'Save as draft'}</Button>
      </div>
    </form>
  );
}
