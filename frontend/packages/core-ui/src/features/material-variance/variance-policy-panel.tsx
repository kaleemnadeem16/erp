import { useState } from 'react';
import {
  useMaterialVariancePoliciesActivate,
  useMaterialVariancePoliciesDestroy,
  useMaterialVariancePoliciesIndex,
  useMaterialVariancePoliciesStore,
  useMaterialVariancePoliciesUpdate,
  type MaterialVariancePolicyResource,
  type VarianceScopeType,
} from '@erp/core-api';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { ListError } from '../../components/data-table';
import { getApiErrorMessage, isApiError } from '../../lib/api-errors';
import { EMPTY_POLICY_VALUES, toFormValues, toPolicyPayload, VariancePolicyEditor, type VariancePolicyFormValues } from './variance-policy-editor';
import { bandSentence, EFFECT_LABELS } from './variance-labels';

const FALLBACK: Record<string, string> = {
  COMPANY: 'the built-in default applies: any planned change needs a reason.',
  CATEGORY: 'items in it follow the rules of the category above, or the company rules.',
  ITEM: 'this item follows the rules of its category, or the company rules.',
};

interface PanelProps {
  scopeType: VarianceScopeType;
  scopeId: number | null;
  scopeLabel: string;
  mayManage: boolean;
  /** Called after any change, so the page's overview of active rules can refresh too. */
  onChanged?: () => void;
}

/**
 * One scope's rule sets: the active version, drafts (edit, activate, delete) and superseded history. A
 * version is never edited once active — activating a new one supersedes it and keeps it as history, so a
 * past decision is always explainable by the rules in force at the time (ADR 0054).
 */
export function VariancePolicyPanel({ scopeType, scopeId, scopeLabel, mayManage, onChanged }: PanelProps) {
  const [editing, setEditing] = useState<'new' | number | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const query = useMaterialVariancePoliciesIndex({
    'filter[scope_type]': scopeType,
    ...(scopeId !== null ? { 'filter[scope_id]': scopeId } : {}),
    include: 'bands',
    sort: '-created_at',
    per_page: 50,
  });
  const rows = query.data?.status === 200 ? query.data.data.data : [];
  const forbidden = isApiError(query.error) && query.error.status === 403;
  const active = rows.find((row) => row.status === 'ACTIVE');
  const drafts = rows.filter((row) => row.status === 'DRAFT');
  const history = rows.filter((row) => row.status === 'SUPERSEDED');

  const store = useMaterialVariancePoliciesStore();
  const update = useMaterialVariancePoliciesUpdate();
  const activate = useMaterialVariancePoliciesActivate();
  const destroy = useMaterialVariancePoliciesDestroy();
  const busy = store.isPending || update.isPending || activate.isPending || destroy.isPending;

  const done = () => {
    setEditing(null);
    setActionError(null);
    void query.refetch();
    onChanged?.();
  };
  const failed = (message: string) => (e: unknown) => setActionError(getApiErrorMessage(e) ?? message);

  function save(values: VariancePolicyFormValues) {
    setActionError(null);
    const payload = toPolicyPayload(values);
    if (editing === 'new') {
      store.mutate(
        { data: { scope_type: scopeType, ...(scopeId !== null ? { scope_id: scopeId } : {}), ...payload } },
        { onSuccess: done, onError: failed('Could not save this rule set.') },
      );
    } else if (typeof editing === 'number') {
      update.mutate({ materialVariancePolicy: editing, data: payload }, { onSuccess: done, onError: failed('Could not save this rule set.') });
    }
  }

  if (forbidden) {
    return (
      <DetailSection title={`Rules — ${scopeLabel}`} fullWidth>
        <p className="text-sm text-muted-foreground sm:col-span-2">You don't have permission to view variance rules.</p>
      </DetailSection>
    );
  }

  const editorValues = (): VariancePolicyFormValues => {
    if (typeof editing === 'number') {
      const draft = drafts.find((row) => row.id === editing);

      return draft ? toFormValues(draft, true) : EMPTY_POLICY_VALUES;
    }

    // A new version starts from what is in force now, so a small change is a small edit.
    return active ? toFormValues(active, false) : EMPTY_POLICY_VALUES;
  };

  return (
    <DetailSection
      title={`Rules — ${scopeLabel}`}
      description="Item → nearest category → company; the strongest matching band wins. At most one version is active per scope, and older versions are kept as history."
      fullWidth
    >
      <div className="space-y-4 sm:col-span-2">
        {actionError && <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{actionError}</div>}

        {query.isError ? (
          <ListError message="Couldn't load the rules." error={query.error} onRetry={() => void query.refetch()} />
        ) : query.isLoading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : (
          <>
            {active ? <PolicyCard policy={active} badge={<Badge variant="success">Active</Badge>} /> : <p className="text-sm text-muted-foreground">No rule set is active here, so {FALLBACK[scopeType]}</p>}

            {drafts.map((draft) =>
              editing === draft.id ? null : (
                <PolicyCard
                  key={draft.id}
                  policy={draft}
                  badge={<Badge variant="secondary">Draft — not in force</Badge>}
                  actions={
                    mayManage && (
                      <>
                        <Button type="button" size="sm" variant="outline" onClick={() => setEditing(draft.id)} disabled={busy}>Edit</Button>
                        <Button type="button" size="sm" variant="outline" onClick={() => destroy.mutate({ materialVariancePolicy: draft.id }, { onSuccess: done, onError: failed('Could not delete this draft.') })} disabled={busy}>Delete</Button>
                        <Button type="button" size="sm" onClick={() => activate.mutate({ materialVariancePolicy: draft.id }, { onSuccess: done, onError: failed('Could not activate this draft.') })} disabled={busy}>Activate</Button>
                      </>
                    )
                  }
                />
              ),
            )}

            {editing !== null ? (
              <VariancePolicyEditor
                initial={editorValues()}
                submitLabel={editing === 'new' ? 'Save as draft' : 'Save changes'}
                isSubmitting={busy}
                formError={actionError}
                onSubmit={save}
                onCancel={() => { setEditing(null); setActionError(null); }}
              />
            ) : (
              mayManage && <Button type="button" variant="outline" onClick={() => setEditing('new')}>{active ? 'Start a new version' : 'Set up rules'}</Button>
            )}

            {history.length > 0 && (
              <details className="text-sm">
                <summary className="cursor-pointer font-medium">History ({history.length})</summary>
                <div className="mt-2 space-y-2">
                  {history.map((row) => <PolicyCard key={row.id} policy={row} badge={<Badge variant="outline">{row.effective_from} → {row.effective_to}</Badge>} muted />)}
                </div>
              </details>
            )}
          </>
        )}
      </div>
    </DetailSection>
  );
}

function PolicyCard({ policy, badge, actions, muted }: { policy: MaterialVariancePolicyResource; badge: React.ReactNode; actions?: React.ReactNode; muted?: boolean }) {
  const bands = policy.bands ?? [];

  return (
    <div className={`rounded-md border p-3 text-sm ${muted ? 'text-muted-foreground' : ''}`}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <span className="font-medium">Version {policy.id}</span> {badge}
          <p className="text-xs text-muted-foreground">"{policy.reason}"{policy.effective_from && policy.status === 'ACTIVE' ? ` · since ${policy.effective_from}` : ''}</p>
        </div>
        {actions && <div className="flex gap-2">{actions}</div>}
      </div>
      <ul className="mt-2 list-disc space-y-0.5 pl-5">
        {policy.minimum_effect && <li>Any change: at least “{EFFECT_LABELS[policy.minimum_effect] ?? policy.minimum_effect}”</li>}
        {bands.map((band) => <li key={band.id}>{bandSentence(band)}</li>)}
        {bands.length === 0 && !policy.minimum_effect && <li>No bands: every change is only recorded.</li>}
      </ul>
    </div>
  );
}
