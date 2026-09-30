import { useState } from 'react';
import { useApprovalRequestsIndex, useApprovalRequestsInbox } from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { ListError } from '../../components/data-table';
import { Button } from '../../components/ui/button';
import { usePermission } from '../../hooks/use-permission';
import { ApprovalRequestPanel } from './approval-request-panel';
import { DelegationsPanel } from './delegations-panel';

/**
 * Everything waiting for this person's decision (ADR 0070) — including what they may decide as someone's delegate — and,
 * for those who may audit approvals, the recent history of all of them. An empty inbox is the honest answer, not an error.
 */
export function ApprovalsInboxScreen() {
  const mayAudit = usePermission('approvals.history.view');
  const [tab, setTab] = useState<'mine' | 'all'>('mine');
  const inbox = useApprovalRequestsInbox();
  const history = useApprovalRequestsIndex({ per_page: 25 }, { query: { enabled: mayAudit && tab === 'all' } });
  const waiting = inbox.data?.status === 200 ? inbox.data.data.data : [];
  const all = history.data?.status === 200 ? history.data.data.data : [];

  return (
    <div className="space-y-4 p-4 pb-24 md:p-6">
      <PageHeader title="Approvals" description="What is waiting for your decision. Approving or rejecting is recorded with who, when and why." />
      {mayAudit && (
        <div className="flex gap-2" role="group" aria-label="Which approvals to show">
          <Button type="button" size="sm" variant={tab === 'mine' ? 'default' : 'outline'} aria-pressed={tab === 'mine'} onClick={() => setTab('mine')}>Waiting for me</Button>
          <Button type="button" size="sm" variant={tab === 'all' ? 'default' : 'outline'} aria-pressed={tab === 'all'} onClick={() => setTab('all')}>All approvals</Button>
        </div>
      )}

      {tab === 'mine' ? (
        inbox.isError ? (
          <ListError message="Couldn't load your approvals." error={inbox.error} onRetry={() => void inbox.refetch()} />
        ) : inbox.isLoading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : waiting.length === 0 ? (
          <p className="rounded-md border p-4 text-sm text-muted-foreground">Nothing is waiting for your approval.</p>
        ) : (
          <div className="space-y-4">
            {waiting.map((request) => <ApprovalRequestPanel key={request.id} request={request} onChanged={() => void inbox.refetch()} />)}
          </div>
        )
      ) : history.isError ? (
        <ListError message="Couldn't load the approvals." error={history.error} onRetry={() => void history.refetch()} />
      ) : all.length === 0 ? (
        <p className="rounded-md border p-4 text-sm text-muted-foreground">No approvals have been requested yet.</p>
      ) : (
        <div className="space-y-4">{all.map((request) => <ApprovalRequestPanel key={request.id} request={request} />)}</div>
      )}

      <DelegationsPanel />
    </div>
  );
}
