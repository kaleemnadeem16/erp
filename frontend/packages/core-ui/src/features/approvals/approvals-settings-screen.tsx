import { useState } from 'react';
import { useApprovalSubjectTypesIndex, useApprovalSubjectTypesMode } from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { ListError } from '../../components/data-table';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select';
import { getApiErrorMessage } from '../../lib/api-errors';
import { usePermission } from '../../hooks/use-permission';
import { AuthoritiesPanel } from './authorities-panel';
import { MODE_OPTIONS } from './approval-labels';
import { PoliciesPanel } from './policies-panel';

/**
 * Approval settings (ADR 0070): which kinds of document need approval and how strictly, the policies that say who must
 * approve what, and each person's amount limit. Nothing is asked of anyone until a company switches a kind of document on
 * and configures a policy, so a small company is not slowed down by a control it never chose.
 */
export function ApprovalsSettingsScreen() {
  const mayManageSettings = usePermission('approvals.settings.manage');
  const mayManagePolicies = usePermission('approvals.policy.manage');
  const mayManageAuthorities = usePermission('approvals.authority.manage');
  // Everyone's limits are readable only with the manage capability or the auditor one (ADR 0087); history alone no longer shows them.
  const mayViewAuthorities = mayManageAuthorities || usePermission('approvals.authority.view');
  const subjects = useApprovalSubjectTypesIndex({ query: { retry: false } });
  const rows = subjects.data?.status === 200 ? subjects.data.data.data : [];
  const updateMode = useApprovalSubjectTypesMode();
  const [error, setError] = useState<string | null>(null);

  return (
    <div className="space-y-6 p-4 pb-24 md:p-6">
      <PageHeader title="Approvals" description="Choose what needs approval, who must approve it, and how much each person may approve." />
      {error && <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}

      <section className="space-y-3 rounded-lg border p-4" aria-labelledby="modes-heading">
        <div>
          <h2 id="modes-heading" className="font-semibold">What needs approval</h2>
          <p className="text-sm text-muted-foreground">Each kind of document is off until you switch it on. “Always required” refuses a document when no policy covers it, rather than letting it through.</p>
        </div>
        {subjects.isError ? (
          <ListError message="Couldn't load the kinds of approval." error={subjects.error} onRetry={() => void subjects.refetch()} />
        ) : (
          <ul className="divide-y rounded-md border">
            {rows.map((subject) => (
              <li key={subject.code} className="flex flex-wrap items-center justify-between gap-3 p-3 text-sm">
                <div>
                  <span className="font-medium">{subject.label}</span>
                  <p className="text-xs text-muted-foreground">{MODE_OPTIONS.find((o) => o.value === subject.mode)?.hint}</p>
                </div>
                <Select
                  value={subject.mode}
                  disabled={!mayManageSettings || updateMode.isPending}
                  onValueChange={(mode) => {
                    setError(null);
                    updateMode.mutate({ subjectType: subject.code, data: { mode: mode as never } }, { onSuccess: () => void subjects.refetch(), onError: (e) => setError(getApiErrorMessage(e) ?? 'Could not change this.') });
                  }}
                >
                  <SelectTrigger className="w-64" aria-label={`Approval for ${subject.label}`}><SelectValue /></SelectTrigger>
                  <SelectContent>{MODE_OPTIONS.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}</SelectContent>
                </Select>
              </li>
            ))}
          </ul>
        )}
      </section>

      <PoliciesPanel subjectTypes={rows} mayManage={mayManagePolicies} />
      {mayViewAuthorities && <AuthoritiesPanel subjectTypes={rows} mayManage={mayManageAuthorities} />}
    </div>
  );
}
